<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST method required', 405);
}

$body = get_json_body();
$payment_id = intval($body['payment_id'] ?? 0);
$statement_id = intval($body['statement_id'] ?? 0);
$tx_id = $body['tx_id'] ?? '';
$approved_amount = isset($body['approved_amount']) ? floatval($body['approved_amount']) : null;

if (!$payment_id) {
    json_error('payment_id is required');
}

$db = get_db();

// Check payment exists and is Pending
$stmt = $db->prepare("SELECT p.*, u.full_name as user_name, u.email as user_email, u.wallet_address as user_wallet_address 
                       FROM payments p JOIN users u ON p.user_id = u.id WHERE p.id = ?");
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) {
    $db->close();
    json_error('Payment not found', 404);
}

if ($payment['status'] === 'Approved') {
    $db->close();
    json_error('Payment already approved');
}

// Guard: a bank statement / transaction can only ever back ONE approved claim.
// Prevents the same money being paid to two payments (double payout).
if ($statement_id > 0) {
    $chk = $db->prepare("SELECT id, status, matched_payment_id FROM statements WHERE id = ?");
    $chk->bind_param("i", $statement_id);
    $chk->execute();
    $st = $chk->get_result()->fetch_assoc();
    $chk->close();
    if ($st && $st['status'] === 'matched' && intval($st['matched_payment_id']) !== $payment_id) {
        $db->close();
        json_error('This statement is already matched to payment #' . intval($st['matched_payment_id']) . '. A statement can only back one payment.', 409);
    }
}
if ($tx_id !== '' && $tx_id !== null && $tx_id !== 'manual_approve') {
    $chk = $db->prepare("SELECT id FROM payments WHERE matched_tx_id = ? AND status = 'Approved' AND id != ? LIMIT 1");
    $chk->bind_param("si", $tx_id, $payment_id);
    $chk->execute();
    $dup = $chk->get_result()->fetch_assoc();
    $chk->close();
    if ($dup) {
        $db->close();
        json_error('Transaction ' . $tx_id . ' is already matched to approved payment #' . intval($dup['id']) . '. A transaction can only be paid once.', 409);
    }
}

// If approved_amount provided (e.g. PayPal net), update payment amount to the net value
if ($approved_amount !== null && $approved_amount > 0) {
    $stmt = $db->prepare("UPDATE payments SET status = 'Approved', matched_tx_id = ?, amount = ? WHERE id = ?");
    $stmt->bind_param("sdi", $tx_id, $approved_amount, $payment_id);
    $stmt->execute();
    $stmt->close();
} else {
    // Mark payment as Approved (but still unpaid)
    $stmt = $db->prepare("UPDATE payments SET status = 'Approved', matched_tx_id = ? WHERE id = ?");
    $stmt->bind_param("si", $tx_id, $payment_id);
    $stmt->execute();
    $stmt->close();
}

// Mark statement row as matched
if ($statement_id > 0) {
    $stmt = $db->prepare("UPDATE statements SET status = 'matched', matched_payment_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $payment_id, $statement_id);
    $stmt->execute();
    $stmt->close();
}

// Log the approval
$admin_user = $_SESSION['admin_username'] ?? 'admin';
$user_name = $payment['user_name'] ?? '';
$user_email = $payment['user_email'] ?? '';
$user_id = intval($payment['user_id']);
$wallet = ($payment['wallet_address'] ?? '') ?: ($payment['user_wallet_address'] ?? '');
$amount = ($approved_amount !== null && $approved_amount > 0) ? $approved_amount : floatval($payment['amount']);
$empty_withdraw = '';

$log_stmt = $db->prepare("INSERT INTO payment_logs (payment_id, user_id, user_name, user_email, wallet_address, usdt_amount, statement_id, statement_tx_id, statement_source, coinex_withdraw_id, action, admin_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?)");
$empty_source = '';
$log_stmt->bind_param("iisssdissss", $payment_id, $user_id, $user_name, $user_email, $wallet, $amount, $statement_id, $tx_id, $empty_source, $empty_withdraw, $admin_user);
$log_stmt->execute();
$log_stmt->close();

$db->close();

// Send approval email to user
$to = $payment['user_email'];
$subject = "Your Payment Has Been Approved - TutooPay";
$full_name = $payment['user_name'] ?? 'Valued Customer';
$pay_amount = number_format($amount, 2);
$pay_currency = $payment['currency'] ?? 'USD';

$html_body = '
<!DOCTYPE html>
<html>
<head><meta charset="utf-8">

<style>
  body { font-family: Arial, Helvetica, sans-serif; background: #f4f4f7; margin: 0; padding: 0; }
  .container { max-width: 600px; margin: 40px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
  .header { background: linear-gradient(135deg, #10b981, #059669); padding: 40px 30px; text-align: center; }
  .header h1 { color: #ffffff; margin: 0; font-size: 28px; }
  .header p { color: rgba(255,255,255,0.85); margin: 8px 0 0; font-size: 14px; }
  .body { padding: 40px 30px; }
  .body h2 { color: #1f2937; margin: 0 0 20px; font-size: 22px; }
  .body p { color: #4b5563; line-height: 1.7; margin: 0 0 15px; font-size: 15px; }
  .amount-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 20px; text-align: center; margin: 25px 0; }
  .amount-box .label { color: #6b7280; font-size: 13px; text-transform: uppercase; letter-spacing: 1px; }
  .amount-box .value { color: #059669; font-size: 32px; font-weight: bold; margin: 5px 0; }
  .checkmark { font-size: 48px; margin-bottom: 10px; }
  .footer { background: #f9fafb; padding: 25px 30px; text-align: center; border-top: 1px solid #e5e7eb; }
  .footer p { color: #9ca3af; font-size: 12px; margin: 0; }
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div class="checkmark">&#10004;</div>
    <h1>Payment Approved</h1>
    <p>Your payment has been verified and approved</p>
  </div>
  <div class="body">
    <h2>Dear ' . htmlspecialchars($full_name) . ',</h2>
    <p>Great news! Your payment record has been <strong>approved</strong> by TutooPay and has been passed to our financial department for processing.</p>
    <div class="amount-box">
      <div class="label">Approved Amount</div>
      <div class="value">' . htmlspecialchars($pay_currency) . ' ' . $pay_amount . '</div>
    </div>
    <p>Our team will process your payment shortly. You will receive a confirmation once the transfer has been completed.</p>
    <p>Thank you for choosing <strong>TutooPay</strong> as your payment partner. We appreciate your trust in our services.</p>
  </div>
  <div class="footer">
    <p>&copy; ' . date('Y') . ' TutooPay. All rights reserved.</p>
  </div>
</div>
</body>
</html>';

$headers = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "From: TutooPay <noreply@tutoopay.com>\r\n";
$headers .= "Reply-To: support@tutoopay.com\r\n";

$email_sent = false;
if ($to) {
    $email_sent = @mail($to, $subject, $html_body, $headers);
}

json_response([
    'success' => true,
    'message' => 'Payment approved successfully',
    'email_sent' => $email_sent,
    'payment_id' => $payment_id,
]);
?>
