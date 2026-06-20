<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST method required', 405);
}

$body = get_json_body();
$payment_id = intval($body['payment_id'] ?? 0);
$description = trim($body['description'] ?? '');
$exchange_fee = isset($body['exchange_fee']) ? floatval($body['exchange_fee']) : 2.0;

if (!$payment_id) {
    json_error('payment_id is required');
}
if (!$description) {
    json_error('description is required');
}

$db = get_db();

// Check payment exists, is Approved, and not already paid
$stmt = $db->prepare("SELECT p.*, u.full_name, u.email, u.wallet_address as user_wallet, u.commission_pct FROM payments p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) {
    $db->close();
    json_error('Payment not found', 404);
}

if ($payment['paid_status'] === 'paid') {
    $db->close();
    json_error('Payment already paid');
}

// Calculate commission-adjusted payout amount with custom exchange fee
$commission_pct = floatval($payment['commission_pct'] ?? 20);
$original_amount_usd = convert_to_usd(floatval($payment['amount'] ?? 0), $payment['currency'] ?? 'USD');
$commission_amount = round($original_amount_usd * ($commission_pct / 100), 2);
$payout_amount = round($original_amount_usd - $commission_amount - $exchange_fee, 2);
if ($payout_amount < 0) $payout_amount = 0;

// Update payment to paid
$stmt = $db->prepare("UPDATE payments SET paid_status = 'paid' WHERE id = ?");
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$stmt->close();

// Log the manual payment with commission-adjusted amount
$admin_user = $_SESSION['admin_username'] ?? 'admin';
$user_name = $payment['full_name'] ?? '';
$user_email = $payment['email'] ?? '';
$user_id = intval($payment['user_id']);
$wallet = $payment['wallet_address'] ?: ($payment['user_wallet'] ?? '');
$usdt_amount = $payout_amount;
$matched_tx_id = $payment['matched_tx_id'] ?? '';

$log_stmt = $db->prepare("INSERT INTO payment_logs (payment_id, user_id, user_name, user_email, wallet_address, usdt_amount, statement_id, statement_tx_id, statement_source, coinex_withdraw_id, action, admin_user) VALUES (?, ?, ?, ?, ?, ?, 0, ?, '', ?, 'manual_paid', ?)");
$log_stmt->bind_param("iisssdsss", $payment_id, $user_id, $user_name, $user_email, $wallet, $usdt_amount, $matched_tx_id, $description, $admin_user);
$log_stmt->execute();
$log_stmt->close();

// Send confirmation email to user
send_payment_confirmation_email(
    $user_email, $user_name, $original_amount_usd, $payment['currency'] ?? 'USD',
    $commission_pct, $commission_amount, $exchange_fee, $payout_amount,
    $wallet, $payment['payment_method'] ?? ''
);

$db->close();

json_response([
    'success' => true,
    'message' => 'Payment marked as manually paid',
    'payment_id' => $payment_id,
]);
?>
