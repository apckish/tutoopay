<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST method required', 405);
}

$body = get_json_body();
$payment_id = intval($body['payment_id'] ?? 0);

if (!$payment_id) {
    json_error('payment_id is required');
}

$db = get_db();

$stmt = $db->prepare("SELECT p.*, u.full_name, u.email FROM payments p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
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
    json_error('Cannot revert: this payment has already been paid.', 409);
}
if ($payment['status'] !== 'Approved') {
    $db->close();
    json_error('Only approved (unpaid) claims can be reverted (current status: ' . $payment['status'] . ').');
}

// Free any statement that is matched to this payment so it can be re-used correctly.
$s = $db->prepare("UPDATE statements SET status = 'unmatched', matched_payment_id = NULL WHERE matched_payment_id = ?");
$s->bind_param("i", $payment_id);
$s->execute();
$freed = $s->affected_rows;
$s->close();

// Revert the payment back to Pending and clear its transaction match.
$u = $db->prepare("UPDATE payments SET status = 'Pending', matched_tx_id = NULL WHERE id = ?");
$u->bind_param("i", $payment_id);
$u->execute();
$u->close();

// Remove the approval log entries for this payment.
$d = $db->prepare("DELETE FROM payment_logs WHERE payment_id = ? AND action = 'approved'");
$d->bind_param("i", $payment_id);
$d->execute();
$d->close();

// Audit log (best-effort; ignore if the action value is not permitted by the schema).
try {
    $admin_user = $_SESSION['admin_username'] ?? 'admin';
    $empty = '';
    $sid = 0;
    $uid = intval($payment['user_id']);
    $tx = $payment['matched_tx_id'] ?? '';
    $wallet = $payment['wallet_address'] ?? '';
    $amt = floatval($payment['amount']);
    $log = $db->prepare("INSERT INTO payment_logs (payment_id, user_id, user_name, user_email, wallet_address, usdt_amount, statement_id, statement_tx_id, statement_source, coinex_withdraw_id, action, admin_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'reverted', ?)");
    $log->bind_param("iisssdissss", $payment_id, $uid, $payment['full_name'], $payment['email'], $wallet, $amt, $sid, $tx, $empty, $empty, $admin_user);
    $log->execute();
    $log->close();
} catch (\Throwable $e) {
    // Non-fatal: the revert itself already succeeded.
}

$db->close();

json_response([
    'success' => true,
    'message' => 'Claim reverted to Pending',
    'payment_id' => $payment_id,
    'statements_freed' => $freed,
]);
?>
