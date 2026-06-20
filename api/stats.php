<?php
require_once __DIR__ . '/config.php';

$db = get_db();

$total = $db->query("SELECT COUNT(*) as total FROM payments")->fetch_assoc()['total'];
$paid = $db->query("SELECT COUNT(*) as total FROM payments WHERE paid_status = 'paid'")->fetch_assoc()['total'];
$unpaid = $db->query("SELECT COUNT(*) as total FROM payments WHERE paid_status = 'unpaid'")->fetch_assoc()['total'];
$processing = $db->query("SELECT COUNT(*) as total FROM payments WHERE paid_status = 'processing'")->fetch_assoc()['total'];
$failed = $db->query("SELECT COUNT(*) as total FROM payments WHERE paid_status = 'failed'")->fetch_assoc()['total'];
$total_usdt = floatval($db->query("SELECT COALESCE(SUM(usdt_amount), 0) as total FROM payments WHERE paid_status = 'paid'")->fetch_assoc()['total']);
$total_users = $db->query("SELECT COUNT(*) as total FROM users")->fetch_assoc()['total'];
$users_wallet = $db->query("SELECT COUNT(*) as total FROM users WHERE wallet_address IS NOT NULL AND wallet_address != ''")->fetch_assoc()['total'];
$approved_unpaid_result = $db->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'Approved' AND paid_status = 'unpaid'");
$approved_unpaid_total = floatval($approved_unpaid_result->fetch_assoc()['total']);

$db->close();

json_response([
    'total_payments' => intval($total),
    'paid_payments' => intval($paid),
    'unpaid_payments' => intval($unpaid),
    'processing_payments' => intval($processing),
    'failed_payments' => intval($failed),
    'total_paid_usdt' => $total_usdt,
    'total_users' => intval($total_users),
    'users_with_wallet' => intval($users_wallet),
    'approved_unpaid_total' => $approved_unpaid_total,
    'uploaded_transactions' => count($_SESSION['uploaded_transactions']),
    'matched_transactions' => count($_SESSION['matched_tx_ids']),
]);
?>
