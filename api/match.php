<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST method required', 405);
}

$db = get_db();

// Get all pending unpaid payments with user info
$result = $db->query("SELECT p.*, u.full_name as user_name, u.email as user_email, u.wallet_address as user_wallet_address, u.whitelisted 
                       FROM payments p JOIN users u ON p.user_id = u.id 
                       WHERE p.paid_status IN ('unpaid', 'failed') 
                       AND p.status = 'Pending' 
                       ORDER BY p.date DESC");

$payments = [];
while ($row = $result->fetch_assoc()) {
    $row['amount'] = floatval($row['amount']);
    if ($row['usdt_amount']) $row['usdt_amount'] = floatval($row['usdt_amount']);
    $payments[] = $row;
}

// Get all unmatched statement rows from DB
$stmt_result = $db->query("SELECT * FROM statements WHERE status = 'unmatched' ORDER BY tx_date DESC");
$statements = [];
while ($row = $stmt_result->fetch_assoc()) {
    $row['gross'] = floatval($row['gross']);
    $row['fee'] = floatval($row['fee']);
    $row['net'] = floatval($row['net']);
    $statements[] = $row;
}

$db->close();

// Build a map of payment_method -> statement source for filtering
$source_map = [
    'PayPal' => 'PayPal',
    'Payoneer' => 'Payoneer',
    'Stripe' => 'Stripe',
    'Wise' => 'Wise',
    'Bank Transfer' => 'Bank Transfer',
    'WeChat Pay' => 'WeChat Pay',
    'Credit Card' => 'Stripe', // map credit card to Stripe
];

$matches = find_matches($payments, $statements, $source_map);

// Group matches by payment_id so frontend can show selection UI
$grouped = [];
foreach ($matches as $m) {
    $pid = $m['payment_id'];
    if (!isset($grouped[$pid])) {
        $grouped[$pid] = [
            'payment_id' => $pid,
            'payment_user_id' => $m['payment_user_id'],
            'payment_amount' => $m['payment_amount'],
            'payment_currency' => $m['payment_currency'],
            'payment_amount_usd' => $m['payment_amount_usd'],
            'payment_sender_name' => $m['payment_sender_name'],
            'payment_user_name' => $m['payment_user_name'],
            'payment_user_email' => $m['payment_user_email'],
            'payment_wallet_address' => $m['payment_wallet_address'],
            'payment_whitelisted' => intval($m['payment_whitelisted'] ?? 0),
            'payment_date' => $m['payment_date'],
            'payment_status' => $m['payment_status'],
            'payment_paid_status' => $m['payment_paid_status'],
            'payment_method' => $m['payment_method'],
            'payment_receipt_url' => $m['payment_receipt_url'],
            'candidates' => [],
        ];
    }
    $grouped[$pid]['candidates'][] = [
        'statement_id' => $m['statement_id'],
        'tx_id' => $m['tx_id'],
        'tx_name' => $m['tx_name'],
        'tx_email' => $m['tx_email'],
        'tx_amount' => $m['tx_amount'],
        'tx_fee' => $m['tx_fee'],
        'tx_net' => $m['tx_net'],
        'tx_currency' => $m['tx_currency'],
        'tx_amount_usd' => $m['tx_amount_usd'],
        'tx_fee_usd' => $m['tx_fee_usd'],
        'tx_net_usd' => $m['tx_net_usd'],
        'tx_date' => $m['tx_date'],
        'tx_note' => $m['tx_note'],
        'tx_source' => $m['tx_source'],
        'amount_diff' => $m['amount_diff'],
        'amount_pct_diff' => $m['amount_pct_diff'],
        'date_diff_days' => $m['date_diff_days'],
        'confidence' => $m['confidence'],
        'amount_confidence' => $m['amount_confidence'],
        'date_confidence' => $m['date_confidence'],
    ];
}

// Sort each group's candidates by confidence desc
foreach ($grouped as &$g) {
    usort($g['candidates'], function($a, $b) { return $b['confidence'] - $a['confidence']; });
}
unset($g);

// Sort groups by best candidate confidence desc
$grouped_list = array_values($grouped);
usort($grouped_list, function($a, $b) {
    $conf_a = $a['candidates'][0]['confidence'] ?? 0;
    $conf_b = $b['candidates'][0]['confidence'] ?? 0;
    return $conf_b - $conf_a;
});

// Find unmatched pending payments (no statement match found)
$matched_payment_ids = array_keys($grouped);
$unmatched_pending = [];
foreach ($payments as $payment) {
    $pid = intval($payment['id']);
    if (in_array($pid, $matched_payment_ids)) continue;
    $payment_amount = floatval($payment['amount']);
    $payment_currency = $payment['currency'] ?? 'USD';
    $payment_amount_usd = convert_to_usd($payment_amount, $payment_currency);
    $unmatched_pending[] = [
        'payment_id' => $pid,
        'payment_user_id' => intval($payment['user_id']),
        'payment_amount' => $payment_amount,
        'payment_currency' => $payment_currency,
        'payment_amount_usd' => round($payment_amount_usd, 2),
        'payment_sender_name' => $payment['sender_name'] ?? '',
        'payment_user_name' => $payment['user_name'] ?? '',
        'payment_user_email' => $payment['user_email'] ?? '',
        'payment_wallet_address' => ($payment['wallet_address'] ?? '') ?: ($payment['user_wallet_address'] ?? ''),
        'payment_whitelisted' => intval($payment['whitelisted'] ?? 0),
        'payment_date' => $payment['transfer_date'] ?? $payment['date'] ?? null,
        'payment_status' => $payment['status'] ?? '',
        'payment_paid_status' => $payment['paid_status'] ?? 'unpaid',
        'payment_method' => $payment['payment_method'] ?? '',
        'payment_receipt_url' => ($payment['receipt_url'] ?? '') ? (str_starts_with($payment['receipt_url'], 'http') ? $payment['receipt_url'] : 'https://upload.tutoopay.com/' . $payment['receipt_url']) : '',
    ];
}

json_response(['matches' => $grouped_list, 'total_matches' => count($grouped_list), 'unmatched_pending' => $unmatched_pending, 'total_unmatched' => count($unmatched_pending)]);

function find_matches($payments, $statements, $source_map) {
    $matches = [];
    
    foreach ($payments as $payment) {
        $payment_amount = floatval($payment['amount']);
        $payment_currency = $payment['currency'] ?? 'USD';
        $payment_amount_usd = convert_to_usd($payment_amount, $payment_currency);
        $payment_method = $payment['payment_method'] ?? '';
        
        // Determine which statement source to match against
        $expected_source = $source_map[$payment_method] ?? null;
        
        // Parse payment date
        $payment_date = null;
        $payment_date_str = $payment['transfer_date'] ?? $payment['date'] ?? null;
        if ($payment_date_str) {
            $payment_date = strtotime($payment_date_str);
        }
        
        foreach ($statements as $stmt) {
            // Filter by source: only match against matching statement type
            if ($expected_source && $stmt['source'] !== $expected_source) continue;
            
            $tx_amount = floatval($stmt['gross']);
            $tx_fee = floatval($stmt['fee']);
            $tx_net = floatval($stmt['net']);
            $tx_currency = $stmt['currency'] ?? 'USD';
            $tx_amount_usd = convert_to_usd($tx_amount, $tx_currency);
            $tx_fee_usd = convert_to_usd(abs($tx_fee), $tx_currency);
            $tx_net_usd = convert_to_usd($tx_net, $tx_currency);
            
            // Amount check with tolerance
            if ($payment_amount_usd == 0 || $tx_amount_usd == 0) continue;
            $amount_diff = abs($payment_amount_usd - $tx_amount_usd);
            $amount_pct_diff = $amount_diff / max($payment_amount_usd, $tx_amount_usd);
            
            if ($amount_pct_diff > AMOUNT_TOLERANCE) continue;
            
            // Date info (no filtering, just for display)
            $tx_date_str = $stmt['tx_date'] ?? '';
            $tx_date = $tx_date_str ? strtotime($tx_date_str) : null;
            $date_diff_days = 999;
            if ($payment_date && $tx_date) {
                $date_diff_days = abs(($payment_date - $tx_date) / 86400);
            }
            
            // Calculate separate amount and date confidence
            $amount_confidence = max(0, round(100 - ($amount_pct_diff * 100 / AMOUNT_TOLERANCE)));
            $date_confidence = 0;
            if ($date_diff_days <= DATE_RANGE_DAYS) {
                $date_confidence = max(0, round(100 - ($date_diff_days / DATE_RANGE_DAYS) * 100));
            }
            $confidence = $amount_confidence; // primary sort by amount match
            
            $matches[] = [
                'payment_id' => intval($payment['id']),
                'payment_user_id' => intval($payment['user_id']),
                'payment_amount' => $payment_amount,
                'payment_currency' => $payment_currency,
                'payment_amount_usd' => round($payment_amount_usd, 2),
                'payment_sender_name' => $payment['sender_name'] ?? '',
                'payment_user_name' => $payment['user_name'] ?? '',
                'payment_user_email' => $payment['user_email'] ?? '',
                'payment_wallet_address' => ($payment['wallet_address'] ?? '') ?: ($payment['user_wallet_address'] ?? ''),
                'payment_whitelisted' => intval($payment['whitelisted'] ?? 0),
                'payment_date' => $payment_date_str,
                'payment_status' => $payment['status'] ?? '',
                'payment_paid_status' => $payment['paid_status'] ?? 'unpaid',
                'payment_method' => $payment_method,
                'payment_receipt_url' => ($payment['receipt_url'] ?? '') ? (str_starts_with($payment['receipt_url'], 'http') ? $payment['receipt_url'] : 'https://upload.tutoopay.com/' . $payment['receipt_url']) : '',
                'statement_id' => intval($stmt['id']),
                'tx_id' => $stmt['tx_id'],
                'tx_name' => $stmt['name'],
                'tx_email' => $stmt['from_email'] ?? '',
                'tx_amount' => $tx_amount,
                'tx_fee' => $tx_fee,
                'tx_net' => $tx_net,
                'tx_currency' => $tx_currency,
                'tx_amount_usd' => round($tx_amount_usd, 2),
                'tx_fee_usd' => round($tx_fee_usd, 2),
                'tx_net_usd' => round($tx_net_usd, 2),
                'tx_date' => $tx_date_str,
                'tx_note' => $stmt['note'] ?? '',
                'tx_source' => $stmt['source'],
                'amount_diff' => round($amount_diff, 2),
                'amount_pct_diff' => round($amount_pct_diff * 100, 2),
                'date_diff_days' => round($date_diff_days),
                'confidence' => $confidence,
                'amount_confidence' => $amount_confidence,
                'date_confidence' => $date_confidence,
            ];
        }
    }
    
    return $matches;
}
?>
