<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('POST method required', 405);
}

// Admin-only check
if (($_SESSION['admin_role'] ?? '') !== 'admin') {
    json_error('Admin access required', 403);
}

$body = get_json_body();
$user_id = intval($body['user_id'] ?? 0);
$name = trim($body['name'] ?? '');
$wallet_address = trim($body['wallet_address'] ?? '');
$amount = floatval($body['amount'] ?? 0);
$commission_pct = isset($body['commission_pct']) ? floatval($body['commission_pct']) : 20.0;
$exchange_fee = isset($body['exchange_fee']) ? floatval($body['exchange_fee']) : 2.0;
$manual = !empty($body['manual']);
$description = trim($body['description'] ?? '');
$chain = $body['chain'] ?? 'TRC20';
if (!in_array($chain, ['TRC20', 'BSC'])) $chain = 'TRC20';

if (!$name) {
    json_error('Name is required');
}
if (!$manual && !$wallet_address) {
    json_error('Wallet address is required');
}
if ($amount <= 0) {
    json_error('Amount must be greater than 0');
}

// For manual pay, description is required; wallet validation only for CoinEx
if ($manual) {
    if (!$description) {
        json_error('Description is required for manual payment');
    }
} else {
    // Validate wallet address format for CoinEx
    if ($chain === 'TRC20') {
        if (strlen($wallet_address) !== 34 || $wallet_address[0] !== 'T') {
            json_error('Invalid TRC20 wallet address');
        }
    } elseif ($chain === 'BSC') {
        if (strlen($wallet_address) !== 42 || substr($wallet_address, 0, 2) !== '0x') {
            json_error('Invalid BEP20 wallet address');
        }
    }
}

// Calculate payout
$commission_amount = round($amount * ($commission_pct / 100), 2);
$payout_amount = round($amount - $commission_amount - $exchange_fee, 2);
if ($payout_amount <= 0) {
    json_error('Payout amount must be greater than 0 after deductions');
}

$db = get_db();
$admin_user = $_SESSION['admin_username'] ?? 'admin';

// Look up user email if user_id provided
$user_email = '';
if ($user_id > 0) {
    $u_stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
    $u_stmt->bind_param("i", $user_id);
    $u_stmt->execute();
    $u_row = $u_stmt->get_result()->fetch_assoc();
    $user_email = $u_row['email'] ?? '';
    $u_stmt->close();
}

if ($manual) {
    // Manual payment — no CoinEx, just log it
    $log_stmt = $db->prepare("INSERT INTO payment_logs (payment_id, user_id, user_name, user_email, wallet_address, usdt_amount, statement_id, statement_tx_id, statement_source, coinex_withdraw_id, action, admin_user) VALUES (0, ?, ?, ?, ?, ?, 0, '', 'direct', ?, 'direct_paid', ?)");
    $desc_note = 'manual: ' . $description;
    $log_stmt->bind_param("isssdss", $user_id, $name, $user_email, $wallet_address, $payout_amount, $desc_note, $admin_user);
    $log_stmt->execute();
    $log_stmt->close();

    // Send confirmation email
    if ($user_email) {
        send_payment_confirmation_email(
            $user_email, $name, $amount, 'USD',
            $commission_pct, $commission_amount, $exchange_fee, $payout_amount,
            $wallet_address ?: 'Manual: ' . $description, 'Direct Pay (Manual)'
        );
    }

    $db->close();
    json_response([
        'success' => true,
        'message' => 'Manual direct payment recorded successfully',
        'manual' => true,
        'description' => $description,
        'withdraw_id' => 'manual',
        'name' => $name,
        'original_amount' => $amount,
        'commission_pct' => $commission_pct,
        'commission_amount' => $commission_amount,
        'exchange_fee' => $exchange_fee,
        'payout_amount' => $payout_amount,
        'wallet_address' => $wallet_address,
    ]);
} else {
    // CoinEx API withdrawal
    $result = coinex_request('POST', '/v2/assets/withdraw', [
        'ccy' => 'USDT',
        'chain' => $chain,
        'to_address' => $wallet_address,
        'amount' => strval($payout_amount),
        'withdraw_method' => 'on_chain',
    ]);

    if (isset($result['code']) && $result['code'] == 0) {
        $withdraw_id = strval($result['data']['withdraw_id'] ?? '');
        
        $log_stmt = $db->prepare("INSERT INTO payment_logs (payment_id, user_id, user_name, user_email, wallet_address, usdt_amount, statement_id, statement_tx_id, statement_source, coinex_withdraw_id, action, admin_user) VALUES (0, ?, ?, ?, ?, ?, 0, '', 'direct', ?, 'admin_paid', ?)");
        $log_stmt->bind_param("isssdss", $user_id, $name, $user_email, $wallet_address, $payout_amount, $withdraw_id, $admin_user);
        $log_stmt->execute();
        $log_stmt->close();
        
        // Send confirmation email
        if ($user_email) {
            send_payment_confirmation_email(
                $user_email, $name, $amount, 'USD',
                $commission_pct, $commission_amount, $exchange_fee, $payout_amount,
                $wallet_address, 'Direct Pay (CoinEx)'
            );
        }

        $db->close();
        json_response([
            'success' => true,
            'message' => 'Direct payout submitted successfully',
            'withdraw_id' => $withdraw_id,
            'name' => $name,
            'original_amount' => $amount,
            'commission_pct' => $commission_pct,
            'commission_amount' => $commission_amount,
            'exchange_fee' => $exchange_fee,
            'payout_amount' => $payout_amount,
            'wallet_address' => $wallet_address,
        ]);
    } else {
        // Log the failed attempt
        $coinex_code = strval($result['code'] ?? 'unknown');
        $coinex_msg = $result['message'] ?? 'Unknown error';
        $error_detail = "Code: $coinex_code - $coinex_msg";
        $log_stmt = $db->prepare("INSERT INTO payment_logs (payment_id, user_id, user_name, user_email, wallet_address, usdt_amount, statement_id, statement_tx_id, statement_source, coinex_withdraw_id, action, admin_user) VALUES (0, ?, ?, '', ?, ?, 0, '', 'direct', ?, 'admin_paid_failed', ?)");
        $log_stmt->bind_param("issdss", $user_id, $name, $wallet_address, $payout_amount, $error_detail, $admin_user);
        $log_stmt->execute();
        $log_stmt->close();
        
        $db->close();
        
        $msg = $result['message'] ?? 'Unknown CoinEx error';
        $code = $result['code'] ?? 'unknown';
        json_error("CoinEx error: $msg (code: $code)");
    }
}
?>
