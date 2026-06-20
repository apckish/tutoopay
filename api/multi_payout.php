<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST method required', 405);
}

$body = get_json_body();
$user_id = intval($body['user_id'] ?? 0);
$payment_ids = $body['payment_ids'] ?? []; // approved payment IDs to pay
$deduction_log_ids = $body['deduction_log_ids'] ?? []; // admin_paid/direct log IDs to deduct
$exchange_fee = isset($body['exchange_fee']) ? floatval($body['exchange_fee']) : 2.0;
$wallet_address = trim($body['wallet_address'] ?? '');
$chain = $body['chain'] ?? 'TRC20';
if (!in_array($chain, ['TRC20', 'BSC'])) $chain = 'TRC20';

if (!$user_id) {
    json_error('user_id is required');
}
if (empty($payment_ids)) {
    json_error('At least one payment_id is required');
}

// Sanitize IDs
$payment_ids = array_map('intval', $payment_ids);
$deduction_log_ids = array_map('intval', $deduction_log_ids);

$db = get_db();

// Get user info
$u_stmt = $db->prepare("SELECT full_name, email, wallet_address, commission_pct FROM users WHERE id = ?");
$u_stmt->bind_param("i", $user_id);
$u_stmt->execute();
$user = $u_stmt->get_result()->fetch_assoc();
$u_stmt->close();

if (!$user) {
    $db->close();
    json_error('User not found');
}

$commission_pct = floatval($user['commission_pct'] ?? 20);
$user_wallet = $wallet_address ?: ($user['wallet_address'] ?? '');
if (!$user_wallet) {
    $db->close();
    json_error('No wallet address available');
}

// Fetch selected approved payments WITH matched statement amounts
$placeholders = implode(',', array_fill(0, count($payment_ids), '?'));
$types = str_repeat('i', count($payment_ids));
$stmt = $db->prepare("SELECT p.id, p.amount, p.currency, p.paid_status, p.wallet_address,
       s.gross as stmt_gross, s.net as stmt_net, s.currency as stmt_currency
       FROM payments p
       LEFT JOIN statements s ON s.matched_payment_id = p.id
       WHERE p.id IN ($placeholders) AND p.user_id = ? AND p.status = 'Approved' AND p.paid_status = 'unpaid' AND p.payout_group_id IS NULL");
$bind_params = array_merge($payment_ids, [$user_id]);
$bind_types = $types . 'i';
$stmt->bind_param($bind_types, ...$bind_params);
$stmt->execute();
$result = $stmt->get_result();

$total_approved_usd = 0;
$valid_payment_ids = [];
while ($row = $result->fetch_assoc()) {
    // Use statement amount (what was actually received) if a matched statement exists
    $stmt_net = $row['stmt_net'] !== null ? floatval($row['stmt_net']) : null;
    $stmt_gross = $row['stmt_gross'] !== null ? floatval($row['stmt_gross']) : null;
    $stmt_currency = $row['stmt_currency'] ?? null;

    if ($stmt_gross !== null && $stmt_currency) {
        $stmt_amount = ($stmt_net !== null && $stmt_net > 0) ? $stmt_net : $stmt_gross;
        $amt_usd = convert_to_usd($stmt_amount, $stmt_currency);
    } else {
        $amt_usd = convert_to_usd(floatval($row['amount']), $row['currency'] ?? 'USD');
    }

    $total_approved_usd += $amt_usd;
    $valid_payment_ids[] = intval($row['id']);
    // Use per-payment wallet if available
    if (!$wallet_address && ($row['wallet_address'] ?? '')) {
        $user_wallet = $row['wallet_address'];
    }
}
$stmt->close();

if (empty($valid_payment_ids)) {
    $db->close();
    json_error('No valid approved payments found');
}

// Fetch selected deduction logs (admin_paid/manual_paid)
$total_deductions = 0;
$valid_deduction_ids = [];
if (!empty($deduction_log_ids)) {
    $d_placeholders = implode(',', array_fill(0, count($deduction_log_ids), '?'));
    $d_types = str_repeat('i', count($deduction_log_ids));
    $d_stmt = $db->prepare("SELECT id, usdt_amount FROM payment_logs WHERE id IN ($d_placeholders) AND user_id = ? AND action IN ('admin_paid', 'manual_paid', 'direct_paid') AND payout_group_id IS NULL");
    $d_bind = array_merge($deduction_log_ids, [$user_id]);
    $d_bind_types = $d_types . 'i';
    $d_stmt->bind_param($d_bind_types, ...$d_bind);
    $d_stmt->execute();
    $d_result = $d_stmt->get_result();
    while ($d_row = $d_result->fetch_assoc()) {
        $total_deductions += floatval($d_row['usdt_amount']);
        $valid_deduction_ids[] = intval($d_row['id']);
    }
    $d_stmt->close();
}

// Calculate final payout
$total_approved_usd = round($total_approved_usd, 2);
$total_deductions = round($total_deductions, 2);
$commission_amount = round($total_approved_usd * ($commission_pct / 100), 2);
$payout_amount = round($total_approved_usd - $commission_amount - $exchange_fee - $total_deductions, 2);

if ($payout_amount <= 0) {
    $db->close();
    json_error("Payout amount is $payout_amount after deductions. Nothing to send.");
}

// Mark payments as processing
$up_placeholders = implode(',', array_fill(0, count($valid_payment_ids), '?'));
$up_types = str_repeat('i', count($valid_payment_ids));
$up_stmt = $db->prepare("UPDATE payments SET paid_status = 'processing' WHERE id IN ($up_placeholders)");
$up_stmt->bind_param($up_types, ...$valid_payment_ids);
$up_stmt->execute();
$up_stmt->close();

// Call CoinEx API
$result_api = coinex_request('POST', '/v2/assets/withdraw', [
    'ccy' => 'USDT',
    'chain' => $chain,
    'to_address' => $user_wallet,
    'amount' => strval($payout_amount),
    'withdraw_method' => 'on_chain',
]);

$admin_user = $_SESSION['admin_username'] ?? 'admin';
$group_id = 'grp_' . time() . '_' . $user_id;

if (isset($result_api['code']) && $result_api['code'] == 0) {
    $withdraw_id = strval($result_api['data']['withdraw_id'] ?? '');

    // Update all selected payments as paid with group ID
    $paid_placeholders = implode(',', array_fill(0, count($valid_payment_ids), '?'));
    $paid_types = str_repeat('i', count($valid_payment_ids));
    $paid_stmt = $db->prepare("UPDATE payments SET paid_status = 'paid', payout_group_id = '$group_id', coinex_withdraw_id = ?, usdt_amount = ? WHERE id IN ($paid_placeholders)");
    $paid_bind = array_merge([$withdraw_id, $payout_amount], $valid_payment_ids);
    $paid_bind_types = 'sd' . $paid_types;
    $paid_stmt->bind_param($paid_bind_types, ...$paid_bind);
    $paid_stmt->execute();
    $paid_stmt->close();

    // Mark deduction logs as consumed with group ID
    if (!empty($valid_deduction_ids)) {
        $ded_placeholders = implode(',', array_fill(0, count($valid_deduction_ids), '?'));
        $ded_types = str_repeat('i', count($valid_deduction_ids));
        $ded_stmt = $db->prepare("UPDATE payment_logs SET payout_group_id = '$group_id' WHERE id IN ($ded_placeholders)");
        $ded_stmt->bind_param($ded_types, ...$valid_deduction_ids);
        $ded_stmt->execute();
        $ded_stmt->close();
    }

    // Log the multi-payout
    $log_stmt = $db->prepare("INSERT INTO payment_logs (payment_id, user_id, user_name, user_email, wallet_address, usdt_amount, statement_id, statement_tx_id, statement_source, coinex_withdraw_id, action, admin_user, payout_group_id) VALUES (0, ?, ?, ?, ?, ?, 0, ?, 'multi', ?, 'multi_payout', ?, ?)");
    $payment_ids_str = implode(',', $valid_payment_ids);
    $user_name = $user['full_name'] ?? '';
    $user_email = $user['email'] ?? '';
    $log_stmt->bind_param("isssdssss", $user_id, $user_name, $user_email, $user_wallet, $payout_amount, $payment_ids_str, $withdraw_id, $admin_user, $group_id);
    $log_stmt->execute();
    $log_stmt->close();

    // Send confirmation email
    send_payment_confirmation_email(
        $user_email, $user_name, $total_approved_usd, 'USD',
        $commission_pct, $commission_amount, $exchange_fee + $total_deductions, $payout_amount,
        $user_wallet, 'Multi Payment'
    );

    $db->close();
    json_response([
        'success' => true,
        'message' => 'Multi-payout submitted successfully',
        'withdraw_id' => $withdraw_id,
        'group_id' => $group_id,
        'total_approved' => $total_approved_usd,
        'commission_pct' => $commission_pct,
        'commission_amount' => $commission_amount,
        'exchange_fee' => $exchange_fee,
        'total_deductions' => $total_deductions,
        'payout_amount' => $payout_amount,
        'payments_count' => count($valid_payment_ids),
        'deductions_count' => count($valid_deduction_ids),
    ]);
} else {
    // Reset payments back to unpaid
    $reset_placeholders = implode(',', array_fill(0, count($valid_payment_ids), '?'));
    $reset_types = str_repeat('i', count($valid_payment_ids));
    $reset_stmt = $db->prepare("UPDATE payments SET paid_status = 'unpaid' WHERE id IN ($reset_placeholders)");
    $reset_stmt->bind_param($reset_types, ...$valid_payment_ids);
    $reset_stmt->execute();
    $reset_stmt->close();

    // Log failure
    $coinex_code = strval($result_api['code'] ?? 'unknown');
    $coinex_msg = $result_api['message'] ?? 'Unknown error';
    $error_detail = "Code: $coinex_code - $coinex_msg";
    $log_stmt = $db->prepare("INSERT INTO payment_logs (payment_id, user_id, user_name, user_email, wallet_address, usdt_amount, statement_id, statement_tx_id, statement_source, coinex_withdraw_id, action, admin_user) VALUES (0, ?, ?, ?, ?, ?, 0, '', 'multi', ?, 'multi_payout_failed', ?)");
    $user_name = $user['full_name'] ?? '';
    $user_email = $user['email'] ?? '';
    $log_stmt->bind_param("isssdss", $user_id, $user_name, $user_email, $user_wallet, $payout_amount, $error_detail, $admin_user);
    $log_stmt->execute();
    $log_stmt->close();

    $db->close();

    $msg = $result_api['message'] ?? 'Unknown CoinEx error';
    $code = $result_api['code'] ?? 'unknown';
    json_error("CoinEx error: $msg (code: $code)");
}
?>
