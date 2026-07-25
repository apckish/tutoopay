<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST method required', 405);
}

$body = get_json_body();

$tx_date = $body['tx_date'] ?? '';
$amount = isset($body['amount']) ? floatval($body['amount']) : 0;
$currency = $body['currency'] ?? 'USD';
$source = $body['source'] ?? 'Other';
$name = $body['name'] ?? '';
$from_email = $body['from_email'] ?? '';
$note = $body['note'] ?? '';

if (!$tx_date) {
    json_error('Date is required');
}
if ($amount <= 0) {
    json_error('Amount must be greater than 0');
}
if (!$name) {
    json_error('Sender name is required');
}

$allowed_sources = ['PayPal', 'Payoneer', 'Stripe', 'Wise', 'Bank Transfer', 'WeChat Pay', 'Other'];
if (!in_array($source, $allowed_sources)) {
    json_error('Invalid source');
}

// Use provided tx_id or generate a unique one for manual entries
$custom_tx_id = trim($body['tx_id'] ?? '');
$tx_id = $custom_tx_id ?: ('MANUAL_' . date('Ymd') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 10));

$fee = 0.0;
$net = $amount;
$tx_time = '';
$tx_type = 'Manual Entry';
$subject = '';

$db = get_db();

// If a custom tx_id is provided and it already exists for this source, append a unique suffix
if ($custom_tx_id) {
    $check = $db->prepare("SELECT COUNT(*) as cnt FROM statements WHERE source = ? AND tx_id = ?");
    $check->bind_param("ss", $source, $tx_id);
    $check->execute();
    $result = $check->get_result()->fetch_assoc();
    $check->close();
    if ($result['cnt'] > 0) {
        $tx_id = $custom_tx_id . '_' . date('Ymd_His') . '_' . substr(uniqid(), -5);
    }
}

$stmt = $db->prepare("INSERT INTO statements (source, tx_date, tx_time, name, from_email, tx_type, currency, gross, fee, net, tx_id, note, subject, status)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unmatched')");
$stmt->bind_param("sssssssdddsss",
    $source, $tx_date, $tx_time, $name, $from_email,
    $tx_type, $currency, $amount, $fee, $net,
    $tx_id, $note, $subject
);

if (!$stmt->execute()) {
    $db->close();
    json_error('Failed to insert statement: ' . $stmt->error);
}

$statement_id = $stmt->insert_id;
$stmt->close();
$db->close();

json_response([
    'success' => true,
    'message' => 'Manual statement added successfully',
    'statement_id' => $statement_id,
    'tx_id' => $tx_id,
]);
?>
