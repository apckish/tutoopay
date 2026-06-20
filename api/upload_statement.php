<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST method required', 405);
}

if (!isset($_FILES['file'])) {
    json_error('No file uploaded');
}

$source = $_POST['source'] ?? 'PayPal';
$allowed_sources = ['PayPal', 'Payoneer', 'Stripe', 'Wise', 'Bank Transfer', 'WeChat Pay', 'Other'];
if (!in_array($source, $allowed_sources)) {
    json_error('Invalid source. Allowed: ' . implode(', ', $allowed_sources));
}

$file = $_FILES['file'];
$content = file_get_contents($file['tmp_name']);

// Try different encodings
$text = false;
foreach (['UTF-8', 'ISO-8859-1', 'Windows-1252'] as $enc) {
    $converted = @mb_convert_encoding($content, 'UTF-8', $enc);
    if ($converted !== false) {
        $text = $converted;
        break;
    }
}
if ($text === false) $text = $content;

// Remove BOM
$text = preg_replace('/^\xEF\xBB\xBF/', '', $text);

// Parse CSV
$lines = str_getcsv_lines($text);
if (count($lines) < 2) json_error('CSV file is empty or invalid');

$headers = $lines[0];
$headers = array_map(function($h) { return trim(str_replace('"', '', $h)); }, $headers);

// Find column indexes - flexible mapping for different statement formats
$col_map = [];
$all_cols = ['Date', 'Time', 'TimeZone', 'Name', 'Type', 'Status', 'Currency', 'Gross', 'Fee', 'Net',
             'From Email Address', 'To Email Address', 'Transaction ID', 'Note', 'Subject',
             'Amount', 'Description', 'Reference', 'Payee Name', 'Payment ID'];
foreach ($all_cols as $col) {
    foreach ($headers as $i => $h) {
        $h_clean = trim($h);
        if (strcasecmp($h_clean, $col) === 0) {
            $col_map[$col] = $i;
            break;
        }
    }
}
// Second pass: partial match for columns not found
foreach ($all_cols as $col) {
    if (isset($col_map[$col])) continue;
    foreach ($headers as $i => $h) {
        if (stripos(trim($h), $col) !== false) {
            $col_map[$col] = $i;
            break;
        }
    }
}

// Alternative column mappings for non-PayPal formats
if (!isset($col_map['Gross']) && isset($col_map['Amount'])) {
    $col_map['Gross'] = $col_map['Amount'];
}
if (!isset($col_map['Transaction ID'])) {
    if (isset($col_map['Reference'])) $col_map['Transaction ID'] = $col_map['Reference'];
    elseif (isset($col_map['Payment ID'])) $col_map['Transaction ID'] = $col_map['Payment ID'];
}
if (!isset($col_map['Name']) && isset($col_map['Description'])) {
    $col_map['Name'] = $col_map['Description'];
}
if (!isset($col_map['Name']) && isset($col_map['Payee Name'])) {
    $col_map['Name'] = $col_map['Payee Name'];
}
if (!isset($col_map['Note']) && isset($col_map['Description'])) {
    $col_map['Note'] = $col_map['Description'];
}

$db = get_db();
$transactions = [];
$inserted = 0;
$skipped = 0;
$cross_source_duplicates = 0;

// === SAFEGUARD: Pre-scan for cross-source duplicates and source mismatch ===
$tx_ids_to_check = [];
for ($i = 1; $i < count($lines); $i++) {
    $row = $lines[$i];
    if (count($row) < 3) continue;
    $tx_id = get_col($row, $col_map, 'Transaction ID', '');
    if ($tx_id) $tx_ids_to_check[] = $tx_id;
}

// Check if tx_ids already exist under a DIFFERENT source
$cross_source_found = [];
if (count($tx_ids_to_check) > 0) {
    $batch_size = 100;
    for ($b = 0; $b < count($tx_ids_to_check); $b += $batch_size) {
        $batch = array_slice($tx_ids_to_check, $b, $batch_size);
        $placeholders = implode(',', array_fill(0, count($batch), '?'));
        $types = str_repeat('s', count($batch)) . 's';
        $params = array_merge($batch, [$source]);
        $sql = "SELECT tx_id, source FROM statements WHERE tx_id IN ($placeholders) AND source != ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $cross_source_found[$row['tx_id']] = $row['source'];
        }
        $stmt->close();
    }
}

// If significant cross-source matches found, warn and abort
if (count($cross_source_found) > 0) {
    $sample = array_slice($cross_source_found, 0, 5, true);
    $existing_sources = array_unique(array_values($cross_source_found));
    $db->close();
    json_response([
        'error' => true,
        'message' => "IMPORT BLOCKED: " . count($cross_source_found) . " of " . count($tx_ids_to_check) . " transaction IDs already exist under a different source (" . implode(', ', $existing_sources) . "). You may have selected the wrong source. Please verify and try again.",
        'cross_source_count' => count($cross_source_found),
        'selected_source' => $source,
        'existing_sources' => $existing_sources,
        'sample_conflicts' => $sample,
    ], 409);
}
// === END SAFEGUARD ===

for ($i = 1; $i < count($lines); $i++) {
    $row = $lines[$i];
    if (count($row) < 3) continue;

    $type = get_col($row, $col_map, 'Type', '');
    $status_col = get_col($row, $col_map, 'Status', 'Completed');
    $gross_str = get_col($row, $col_map, 'Gross', '0');
    $gross = parse_amount($gross_str);

    // Only credit transactions (positive amounts)
    if ($gross <= 0) continue;

    // Source-specific filtering
    if ($source === 'PayPal') {
        if (stripos($status_col, 'Completed') === false && stripos($status_col, 'Cleared') === false) continue;
        $type_lower = strtolower($type);
        if (strpos($type_lower, 'reversal') !== false) continue;
        if (strpos($type_lower, 'currency conversion') !== false) continue;
        if (strpos($type_lower, 'general currency') !== false) continue;
    } elseif ($source === 'Payoneer') {
        if (stripos($status_col, 'Completed') === false) continue;
    }

    $tx_id = get_col($row, $col_map, 'Transaction ID', '');
    if (!$tx_id) {
        $tx_id = $source . '_' . md5($i . $gross_str . get_col($row, $col_map, 'Date', '') . get_col($row, $col_map, 'Name', ''));
    }

    $date_str = get_col($row, $col_map, 'Date', '');
    $tx_date = parse_date($date_str);
    $fee = parse_amount(get_col($row, $col_map, 'Fee', '0'));
    $net_val = parse_amount(get_col($row, $col_map, 'Net', '0'));
    if ($net_val == 0 && $gross > 0) $net_val = $gross + $fee;

    $name = get_col($row, $col_map, 'Name', '');
    $from_email = get_col($row, $col_map, 'From Email Address', '');
    $currency = get_col($row, $col_map, 'Currency', 'USD');
    $note = get_col($row, $col_map, 'Note', '');
    $subject = get_col($row, $col_map, 'Subject', '');
    $tx_time = get_col($row, $col_map, 'Time', '');

    $stmt = $db->prepare("INSERT INTO statements (source, tx_date, tx_time, name, from_email, tx_type, currency, gross, fee, net, tx_id, note, subject)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE name=VALUES(name), gross=VALUES(gross), fee=VALUES(fee), net=VALUES(net), note=VALUES(note)");
    $stmt->bind_param("sssssssdddsss",
        $source, $tx_date, $tx_time, $name, $from_email,
        $type, $currency, $gross, $fee, $net_val,
        $tx_id, $note, $subject
    );

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) $inserted++;
        else $skipped++;
    }
    $stmt->close();

    $check = $db->prepare("SELECT id, status FROM statements WHERE source = ? AND tx_id = ?");
    $check->bind_param("ss", $source, $tx_id);
    $check->execute();
    $res = $check->get_result()->fetch_assoc();
    $check->close();

    $transactions[] = [
        'id' => $res['id'] ?? 0,
        'source' => $source,
        'date' => $date_str,
        'time' => $tx_time,
        'name' => $name,
        'from_email' => $from_email,
        'type' => $type,
        'currency' => $currency,
        'gross' => $gross,
        'fee' => $fee,
        'net' => $net_val,
        'tx_id' => $tx_id,
        'note' => $note,
        'subject' => $subject,
        'status' => $res['status'] ?? 'unmatched',
        'is_matched' => (($res['status'] ?? 'unmatched') !== 'unmatched'),
    ];
}

$db->close();

json_response([
    'message' => "Parsed " . count($transactions) . " credit transactions from $source ($inserted new, $skipped existing)",
    'total_transactions' => count($transactions),
    'inserted' => $inserted,
    'skipped' => $skipped,
    'source' => $source,
    'transactions' => $transactions,
]);

function str_getcsv_lines($text) {
    $lines = [];
    $rows = explode("\n", $text);
    foreach ($rows as $row) {
        $row = trim($row);
        if ($row === '') continue;
        $lines[] = str_getcsv($row);
    }
    return $lines;
}

function get_col($row, $col_map, $name, $default = '') {
    if (!isset($col_map[$name])) return $default;
    $idx = $col_map[$name];
    return isset($row[$idx]) ? trim($row[$idx]) : $default;
}

function parse_amount($str) {
    $str = str_replace([',', ' '], ['', ''], trim($str));
    return floatval($str);
}

function parse_date($str) {
    $str = trim($str);
    if (!$str) return null;
    // Try DD/MM/YYYY
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $str, $m)) {
        return $m[3] . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
    }
    // Try YYYY-MM-DD
    if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $str)) return $str;
    // Try "DD Mon, YYYY" (Payoneer format)
    $ts = strtotime($str);
    if ($ts) return date('Y-m-d', $ts);
    return null;
}
?>
