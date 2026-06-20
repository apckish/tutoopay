<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST method required', 405);
}

$body = get_json_body();
$payment_id = intval($body['payment_id'] ?? 0);
$statement_id = intval($body['statement_id'] ?? 0);
$tx_id = $body['tx_id'] ?? '';
$usdt_amount = floatval($body['usdt_amount'] ?? 0);
$wallet_address = $body['wallet_address'] ?? '';
$exchange_fee = isset($body['exchange_fee']) ? floatval($body['exchange_fee']) : 2.0;
$chain = $body['chain'] ?? 'TRC20';
if (!in_array($chain, ['TRC20', 'BSC'])) $chain = 'TRC20';

if (!$payment_id || !$tx_id || !$usdt_amount) {
    json_error('payment_id, tx_id, and usdt_amount are required');
}

$db = get_db();

// Check payment exists and not already paid, JOIN with statements to get actual received amount
$stmt = $db->prepare("SELECT p.*, u.wallet_address as user_wallet, u.commission_pct,
       s.gross as stmt_gross, s.net as stmt_net, s.currency as stmt_currency
       FROM payments p
       JOIN users u ON p.user_id = u.id
       LEFT JOIN statements s ON s.matched_payment_id = p.id
       WHERE p.id = ?");
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

$wallet = $wallet_address ?: ($payment['user_wallet'] ?? '');
if (!$wallet) {
    $db->close();
    json_error('No wallet address available. Set user wallet address first.');
}

// Server-side commission calculation with custom exchange fee
// Use statement amount (what was actually received) if a matched statement exists
$commission_pct = floatval($payment['commission_pct'] ?? 20);

$stmt_net = $payment['stmt_net'] !== null ? floatval($payment['stmt_net']) : null;
$stmt_gross = $payment['stmt_gross'] !== null ? floatval($payment['stmt_gross']) : null;
$stmt_currency = $payment['stmt_currency'] ?? null;

if ($stmt_gross !== null && $stmt_currency) {
    $stmt_amount = ($stmt_net !== null && $stmt_net > 0) ? $stmt_net : $stmt_gross;
    $original_amount_usd = convert_to_usd($stmt_amount, $stmt_currency);
} else {
    $original_amount_usd = convert_to_usd(floatval($payment['amount'] ?? 0), $payment['currency'] ?? 'USD');
}

$commission_amount = round($original_amount_usd * ($commission_pct / 100), 2);
$calculated_payout = round($original_amount_usd - $commission_amount - $exchange_fee, 2);
if ($calculated_payout < 0) $calculated_payout = 0;

// Use the calculated payout amount (server-side authority)
$usdt_amount = $calculated_payout;

// Mark as processing
$stmt = $db->prepare("UPDATE payments SET paid_status = 'processing' WHERE id = ?");
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$stmt->close();

// Call CoinEx API
$result = coinex_request('POST', '/v2/assets/withdraw', [
    'ccy' => 'USDT',
    'chain' => $chain,
    'to_address' => $wallet,
    'amount' => strval($usdt_amount),
    'withdraw_method' => 'on_chain',
]);

if (isset($result['code']) && $result['code'] == 0) {
    $withdraw_id = strval($result['data']['withdraw_id'] ?? '');

    // Update payment record
    $stmt = $db->prepare("UPDATE payments SET status = 'Approved', paid_status = 'paid', matched_tx_id = ?, coinex_withdraw_id = ?, usdt_amount = ? WHERE id = ?");
    $stmt->bind_param("ssdi", $tx_id, $withdraw_id, $usdt_amount, $payment_id);
    $stmt->execute();
    $stmt->close();

    // Mark statement row as paid
    $stmt_source = '';
    if ($statement_id > 0) {
        // Get statement source before updating
        $src_stmt = $db->prepare("SELECT source FROM statements WHERE id = ?");
        $src_stmt->bind_param("i", $statement_id);
        $src_stmt->execute();
        $src_row = $src_stmt->get_result()->fetch_assoc();
        $stmt_source = $src_row['source'] ?? '';
        $src_stmt->close();

        $stmt = $db->prepare("UPDATE statements SET status = 'paid', matched_payment_id = ? WHERE id = ?");
        $stmt->bind_param("ii", $payment_id, $statement_id);
        $stmt->execute();
        $stmt->close();
    } else {
        // Fallback: match by tx_id
        $stmt = $db->prepare("UPDATE statements SET status = 'paid', matched_payment_id = ? WHERE tx_id = ?");
        $stmt->bind_param("is", $payment_id, $tx_id);
        $stmt->execute();
        $stmt->close();
    }

    // Get user info for log
    $user_stmt = $db->prepare("SELECT full_name, email FROM users WHERE id = ?");
    $user_stmt->bind_param("i", $payment['user_id']);
    $user_stmt->execute();
    $user_info = $user_stmt->get_result()->fetch_assoc();
    $user_stmt->close();

    // Log the payment
    $admin_user = $_SESSION['admin_username'] ?? 'admin';
    $log_stmt = $db->prepare("INSERT INTO payment_logs (payment_id, user_id, user_name, user_email, wallet_address, usdt_amount, statement_id, statement_tx_id, statement_source, coinex_withdraw_id, action, admin_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'payout', ?)");
    $user_name = $user_info['full_name'] ?? '';
    $user_email = $user_info['email'] ?? '';
    $user_id = intval($payment['user_id']);
    $log_stmt->bind_param("iisssdissss", $payment_id, $user_id, $user_name, $user_email, $wallet, $usdt_amount, $statement_id, $tx_id, $stmt_source, $withdraw_id, $admin_user);
    $log_stmt->execute();
    $log_stmt->close();

    // Send confirmation email to user
    send_payment_confirmation_email(
        $user_email, $user_name, $original_amount_usd, $payment['currency'] ?? 'USD',
        $commission_pct, $commission_amount, $exchange_fee, $usdt_amount,
        $wallet, $payment['payment_method'] ?? ''
    );

    $db->close();
    json_response([
        'success' => true,
        'message' => 'Withdrawal submitted successfully',
        'withdraw_id' => $withdraw_id,
        'coinex_response' => $result['data'] ?? [],
    ]);
} else {
    // Reset back to unpaid so the user can retry
    $stmt = $db->prepare("UPDATE payments SET paid_status = 'unpaid' WHERE id = ?");
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $stmt->close();

    // Log the failed attempt
    $user_stmt = $db->prepare("SELECT full_name, email FROM users WHERE id = ?");
    $user_stmt->bind_param("i", $payment['user_id']);
    $user_stmt->execute();
    $user_info = $user_stmt->get_result()->fetch_assoc();
    $user_stmt->close();

    $admin_user = $_SESSION['admin_username'] ?? 'admin';
    $coinex_code = strval($result['code'] ?? 'unknown');
    $coinex_msg = $result['message'] ?? 'Unknown error';
    $error_detail = "Code: $coinex_code - $coinex_msg";
    $empty_source = '';
    $log_stmt = $db->prepare("INSERT INTO payment_logs (payment_id, user_id, user_name, user_email, wallet_address, usdt_amount, statement_id, statement_tx_id, statement_source, coinex_withdraw_id, action, admin_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'payout_failed', ?)");
    $user_name = $user_info['full_name'] ?? '';
    $user_email = $user_info['email'] ?? '';
    $user_id = intval($payment['user_id']);
    $log_stmt->bind_param("iisssdissss", $payment_id, $user_id, $user_name, $user_email, $wallet, $usdt_amount, $statement_id, $tx_id, $empty_source, $error_detail, $admin_user);
    $log_stmt->execute();
    $log_stmt->close();

    $db->close();

    $msg = $result['message'] ?? 'Unknown CoinEx error';
    $code = $result['code'] ?? 'unknown';
    json_error("CoinEx error: $msg (code: $code)");
}
?>
