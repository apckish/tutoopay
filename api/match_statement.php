<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST method required', 405);
}

$body = get_json_body();
$statement_id = intval($body['statement_id'] ?? 0);
$user_id = intval($body['user_id'] ?? 0);
$payment_id_link = intval($body['payment_id'] ?? 0); // optional: link to existing payment

if (!$statement_id) {
    json_error('statement_id is required');
}
if (!$user_id) {
    json_error('user_id is required');
}

$db = get_db();

// Get the statement
$stmt = $db->prepare("SELECT * FROM statements WHERE id = ?");
$stmt->bind_param("i", $statement_id);
$stmt->execute();
$statement = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$statement) {
    $db->close();
    json_error('Statement not found', 404);
}

if ($statement['status'] !== 'unmatched') {
    $db->close();
    json_error('Statement is already ' . $statement['status']);
}

// Get the user
$stmt = $db->prepare("SELECT id, full_name, email, wallet_address FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    $db->close();
    json_error('User not found', 404);
}

// Convert amount to USD
$gross = floatval($statement['gross']);
$net = floatval($statement['net']);
$currency = $statement['currency'] ?? 'USD';
$amount_usd = convert_to_usd($net > 0 ? $net : $gross, $currency);

$tx_id = $statement['tx_id'];
$source = $statement['source'];
$tx_date = $statement['tx_date'];
$sender_name = $statement['name'] ?: $user['full_name'];
$wallet = $user['wallet_address'] ?? '';
$admin_user = $_SESSION['admin_username'] ?? 'admin';
$empty_withdraw = '';

if ($payment_id_link > 0) {
    // Link to an existing payment record
    $stmt = $db->prepare("SELECT id, user_id, status FROM payments WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $payment_id_link, $user_id);
    $stmt->execute();
    $existing_payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$existing_payment) {
        $db->close();
        json_error('Payment not found or does not belong to this user', 404);
    }
    
    // Update the existing payment to Approved status with matched_tx_id
    $stmt = $db->prepare("UPDATE payments SET status = 'Approved', matched_tx_id = ? WHERE id = ?");
    $stmt->bind_param("si", $tx_id, $payment_id_link);
    $stmt->execute();
    $stmt->close();
    
    $payment_id = $payment_id_link;
} else {
    // Create a new payment record with Approved status
    $submission_date = date('Y-m-d H:i:s');
    $empty_receipt = '';
    $stmt = $db->prepare("INSERT INTO payments (user_id, amount, currency, sender_name, payment_method, transfer_date, submission_date, status, paid_status, matched_tx_id, wallet_address, receipt_url) VALUES (?, ?, ?, ?, ?, ?, ?, 'Approved', 'unpaid', ?, ?, ?)");
    $stmt->bind_param("idssssssss", $user_id, $amount_usd, $currency, $sender_name, $source, $tx_date, $submission_date, $tx_id, $wallet, $empty_receipt);
    $stmt->execute();
    $payment_id = $stmt->insert_id;
    $stmt->close();
}

// Mark statement as matched
$stmt = $db->prepare("UPDATE statements SET status = 'matched', matched_payment_id = ? WHERE id = ?");
$stmt->bind_param("ii", $payment_id, $statement_id);
$stmt->execute();
$stmt->close();

// Track matched tx_id in session
$_SESSION['matched_tx_ids'][$tx_id] = true;

// Log the approval

$log_stmt = $db->prepare("INSERT INTO payment_logs (payment_id, user_id, user_name, user_email, wallet_address, usdt_amount, statement_id, statement_tx_id, statement_source, coinex_withdraw_id, action, admin_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', ?)");
$log_stmt->bind_param("iisssdissss", $payment_id, $user_id, $user['full_name'], $user['email'], $wallet, $amount_usd, $statement_id, $tx_id, $source, $empty_withdraw, $admin_user);
$log_stmt->execute();
$log_stmt->close();

$db->close();

// Send approval email
$to = $user['email'];
$subject_mail = "Your Payment Has Been Approved - TutooPay";
$full_name = $user['full_name'];
$pay_amount = number_format($amount_usd, 2);

$html_body = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="margin:0;padding:0;background:#f4f4f7;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:40px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">
<tr><td style="background:linear-gradient(135deg,#10b981,#059669);padding:40px 30px;text-align:center;">
<div style="font-size:48px;margin-bottom:10px;">&#10004;</div>
<h1 style="color:#ffffff;margin:0;font-size:28px;">Payment Approved</h1>
<p style="color:rgba(255,255,255,0.85);margin:8px 0 0;font-size:14px;">Your payment has been verified and approved</p>
</td></tr>
<tr><td style="padding:40px 30px;">
<h2 style="color:#1f2937;margin:0 0 20px;font-size:22px;">Dear ' . htmlspecialchars($full_name) . ',</h2>
<p style="color:#4b5563;line-height:1.7;margin:0 0 15px;font-size:15px;">Great news! Your payment record has been <strong>approved</strong> by TutooPay and has been passed to our financial department for processing.</p>
<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:20px;text-align:center;margin:25px 0;">
<div style="color:#6b7280;font-size:13px;text-transform:uppercase;letter-spacing:1px;">Approved Amount</div>
<div style="color:#059669;font-size:32px;font-weight:bold;margin:5px 0;">$' . $pay_amount . '</div>
</div>
<p style="color:#4b5563;line-height:1.7;margin:0 0 15px;font-size:15px;">Our team will process your payment shortly. You will receive a confirmation once the transfer has been completed.</p>
<p style="color:#4b5563;line-height:1.7;margin:0 0 15px;font-size:15px;">Thank you for choosing <strong>TutooPay</strong> as your payment partner.</p>
</td></tr>
<tr><td style="background:#f9fafb;padding:25px 30px;text-align:center;border-top:1px solid #e5e7eb;">
<p style="color:#9ca3af;font-size:12px;margin:0;">&copy; ' . date('Y') . ' TutooPay. All rights reserved.</p>
</td></tr>
</table></td></tr></table></body></html>';

$headers_mail = "MIME-Version: 1.0\r\n";
$headers_mail .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers_mail .= "From: TutooPay <noreply@tutoopay.com>\r\n";
$headers_mail .= "Reply-To: support@tutoopay.com\r\n";

$email_sent = false;
if ($to) {
    $email_sent = @mail($to, $subject_mail, $html_body, $headers_mail);
}

json_response([
    'success' => true,
    'message' => $payment_id_link > 0 ? 'Statement matched to existing payment' : 'Statement matched to user and approved',
    'payment_id' => $payment_id,
    'user_name' => $user['full_name'],
    'amount_usd' => $amount_usd,
    'email_sent' => $email_sent,
    'linked_existing' => $payment_id_link > 0,
]);
?>
