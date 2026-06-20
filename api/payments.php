<?php
require_once __DIR__ . '/config.php';

$db = get_db();

$where = "1=1";
$params = [];
$types = "";

if (isset($_GET['status']) && $_GET['status']) {
    $where .= " AND p.status = ?";
    $params[] = $_GET['status'];
    $types .= "s";
}
if (isset($_GET['paid_status']) && $_GET['paid_status']) {
    $where .= " AND p.paid_status = ?";
    $params[] = $_GET['paid_status'];
    $types .= "s";
}
if (isset($_GET['user_id']) && intval($_GET['user_id']) > 0) {
    $where .= " AND p.user_id = ?";
    $params[] = intval($_GET['user_id']);
    $types .= "i";
}

$query = "SELECT p.*, u.full_name as user_name, u.email as user_email, u.wallet_address as user_wallet_address, u.wallet_name, u.whitelisted, u.commission_pct 
          FROM payments p JOIN users u ON p.user_id = u.id 
          WHERE $where ORDER BY p.date DESC";

$stmt = $db->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$payments = [];
while ($row = $result->fetch_assoc()) {
    // Convert decimal fields to float
    if (isset($row['amount'])) $row['amount'] = floatval($row['amount']);
    if (isset($row['usdt_amount'])) $row['usdt_amount'] = $row['usdt_amount'] ? floatval($row['usdt_amount']) : null;
    $row['amount_usd'] = convert_to_usd(floatval($row['amount'] ?? 0), $row['currency'] ?? 'USD');
    $row['whitelisted'] = intval($row['whitelisted'] ?? 0);
    // Effective wallet: per-payment wallet_address takes priority, fallback to user default
    $row['effective_wallet'] = ($row['wallet_address'] ?? '') ?: ($row['user_wallet_address'] ?? '');
    // Commission: default 20%, calculate payout amount
    $row['commission_pct'] = floatval($row['commission_pct'] ?? 20);
    $commission_amount = $row['amount_usd'] * ($row['commission_pct'] / 100);
    $row['payout_amount'] = round($row['amount_usd'] - $commission_amount - 2, 2);
    if ($row['payout_amount'] < 0) $row['payout_amount'] = 0;
    $payments[] = $row;
}

$stmt->close();
$db->close();

json_response(['payments' => $payments]);
?>
