<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST method required', 405);
}

$body = get_json_body();
$user_id = intval($body['user_id'] ?? 0);
$email_in = trim($body['email'] ?? '');
$amount = floatval($body['amount'] ?? 0);

if (!$user_id && !$email_in) {
    json_error('user_id or email is required');
}
if ($amount <= 0) {
    json_error('A positive amount is required');
}

$db = get_db();

if ($user_id) {
    $stmt = $db->prepare("SELECT id, full_name, email, wallet_address FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
} else {
    $stmt = $db->prepare("SELECT id, full_name, email, wallet_address FROM users WHERE email = ?");
    $stmt->bind_param("s", $email_in);
}
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    $db->close();
    json_error('User not found', 404);
}

$to = $user['email'] ?? '';
if (!$to) {
    $db->close();
    json_error('User has no email address');
}

$name = $user['full_name'] ?: 'there';
$wallet = trim($body['wallet_address'] ?? '') ?: ($user['wallet_address'] ?? '');
$amount_fmt = number_format($amount, 2);
$date = date('F j, Y');

$subject = "You've been paid " . $amount_fmt . " USDT - TutooPay";

$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>';
$html .= '<body style="margin:0;padding:0;background:#f4f4f7;font-family:Arial,Helvetica,sans-serif;">';
$html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:40px 0;"><tr><td align="center">';
$html .= '<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 6px 24px rgba(0,0,0,0.08);">';

$html .= '<tr><td style="background:linear-gradient(135deg,#10b981,#059669);padding:44px 30px;text-align:center;">';
$html .= '<div style="font-size:52px;line-height:1;margin-bottom:12px;">&#127881;</div>';
$html .= '<h1 style="color:#ffffff;margin:0;font-size:28px;">Your money is on the way!</h1>';
$html .= '<p style="color:rgba(255,255,255,0.9);margin:10px 0 0;font-size:15px;">USDT has been transferred to your wallet</p>';
$html .= '</td></tr>';

$html .= '<tr><td style="padding:40px 34px;">';
$html .= '<h2 style="color:#1f2937;margin:0 0 18px;font-size:21px;">Dear ' . htmlspecialchars($name) . ',</h2>';
$html .= '<p style="color:#4b5563;line-height:1.75;margin:0 0 20px;font-size:15px;">Great news! <strong>' . $amount_fmt . ' USDT</strong> has been transferred to your wallet address. The funds are yours now &mdash; you can enjoy your money!</p>';

$html .= '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:24px;text-align:center;margin:0 0 24px;">';
$html .= '<div style="color:#6b7280;font-size:12px;text-transform:uppercase;letter-spacing:1.5px;">Amount transferred</div>';
$html .= '<div style="color:#059669;font-size:36px;font-weight:bold;margin:8px 0 0;">' . $amount_fmt . ' USDT</div>';
$html .= '<div style="color:#6b7280;font-size:12px;margin-top:6px;">USDT &middot; BEP20 (BSC)</div>';
$html .= '</div>';

if ($wallet) {
    $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:12px;margin:0 0 24px;">';
    $html .= '<tr><td style="padding:16px 18px;border-bottom:1px solid #f3f4f6;">';
    $html .= '<div style="color:#6b7280;font-size:12px;text-transform:uppercase;letter-spacing:1px;">Wallet address</div>';
    $html .= '<div style="color:#111827;font-size:14px;font-family:Consolas,Menlo,monospace;word-break:break-all;margin-top:5px;">' . htmlspecialchars($wallet) . '</div>';
    $html .= '</td></tr>';
    $html .= '<tr><td style="padding:16px 18px;">';
    $html .= '<div style="color:#6b7280;font-size:12px;text-transform:uppercase;letter-spacing:1px;">Date</div>';
    $html .= '<div style="color:#111827;font-size:14px;margin-top:5px;">' . $date . '</div>';
    $html .= '</td></tr></table>';
} else {
    $html .= '<p style="color:#4b5563;line-height:1.7;margin:0 0 24px;font-size:15px;">Date: <strong>' . $date . '</strong></p>';
}

$html .= '<p style="color:#4b5563;line-height:1.75;margin:0 0 8px;font-size:15px;">Please allow a few minutes for the transaction to appear in your wallet app.</p>';
$html .= '<p style="color:#4b5563;line-height:1.75;margin:0;font-size:15px;">Thank you for working with <strong>TutooPay</strong>.</p>';
$html .= '</td></tr>';

$html .= '<tr><td style="background:#f9fafb;padding:24px 30px;text-align:center;border-top:1px solid #e5e7eb;">';
$html .= '<p style="color:#9ca3af;font-size:12px;margin:0 0 4px;">Questions? Just reply to this email.</p>';
$html .= '<p style="color:#9ca3af;font-size:12px;margin:0;">&copy; ' . date('Y') . ' TutooPay. All rights reserved.</p>';
$html .= '</td></tr>';

$html .= '</table></td></tr></table></body></html>';

$headers = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "From: TutooPay <noreply@tutoopay.com>\r\n";
$headers .= "Reply-To: support@tutoopay.com\r\n";

$sent = @mail($to, $subject, $html, $headers);

if ($sent) {
    $admin_user = $_SESSION['admin_username'] ?? 'admin';
    $uid = intval($user['id']);
    $log = $db->prepare(
        "INSERT INTO payment_logs
         (payment_id, user_id, user_name, user_email, wallet_address, usdt_amount,
          statement_id, statement_tx_id, statement_source, coinex_withdraw_id, action, admin_user)
         VALUES (0, ?, ?, ?, ?, ?, 0, '', 'manual', 'transfer_email', 'transfer_email', ?)"
    );
    if ($log) {
        $log->bind_param("isssds", $uid, $user['full_name'], $to, $wallet, $amount, $admin_user);
        @$log->execute();
        $log->close();
    }
}

$db->close();

if (!$sent) {
    json_error('Failed to send email to ' . $to, 500);
}

json_response([
    'success' => true,
    'message' => 'Email sent to ' . $to,
    'email' => $to,
    'amount' => $amount
]);
