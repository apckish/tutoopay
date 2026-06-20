<?php
require_once __DIR__ . '/config.php';

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'PUT' && isset($_GET['user_id'])) {
    $body = get_json_body();
    $user_id = intval($_GET['user_id']);
    
    // Update commission if provided
    if (isset($body['commission_pct'])) {
        $commission = floatval($body['commission_pct']);
        if ($commission < 0 || $commission > 100) json_error('Commission must be between 0 and 100');
        $stmt = $db->prepare("UPDATE users SET commission_pct = ? WHERE id = ?");
        $stmt->bind_param("di", $commission, $user_id);
        $stmt->execute();
        $stmt->close();
        $db->close();
        json_response(['message' => 'Commission updated']);
    }
    
    // Update full_name if provided
    if (isset($body['full_name'])) {
        $name = trim($body['full_name']);
        if (!$name) json_error('Name cannot be empty');
        $stmt = $db->prepare("UPDATE users SET full_name = ? WHERE id = ?");
        $stmt->bind_param("si", $name, $user_id);
        $stmt->execute();
        $stmt->close();
        $db->close();
        json_response(['message' => 'Name updated']);
    }
    
    // Update wallet_address and optionally wallet_name
    if (isset($body['wallet_address'])) {
        $wallet = trim($body['wallet_address']);
        $wallet_name = isset($body['wallet_name']) ? trim($body['wallet_name']) : null;
        
        if ($wallet_name !== null) {
            $stmt = $db->prepare("UPDATE users SET wallet_address = ?, wallet_name = ? WHERE id = ?");
            $stmt->bind_param("ssi", $wallet, $wallet_name, $user_id);
        } else {
            $stmt = $db->prepare("UPDATE users SET wallet_address = ? WHERE id = ?");
            $stmt->bind_param("si", $wallet, $user_id);
        }
        $stmt->execute();
        $stmt->close();
        $db->close();
        json_response(['message' => 'Wallet updated']);
    }
    
    json_error('No valid field to update');
}

// GET user detail with payments and statements
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['user_id'])) {
    $user_id = intval($_GET['user_id']);
    
    // Get user info
    $stmt = $db->prepare("SELECT id, full_name, email, wallet_address, wallet_name, whitelisted, whatsapp_number, telegram_id, status, commission_pct FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$user) {
        $db->close();
        json_error('User not found', 404);
    }
    
    // Get all payments for this user with matched statement data
    $commission_pct = floatval($user['commission_pct'] ?? 20);
    $stmt = $db->prepare("SELECT p.*, u.wallet_address as user_wallet_address,
        s.gross as stmt_gross, s.fee as stmt_fee, s.net as stmt_net, s.currency as stmt_currency, s.name as stmt_name, s.tx_id as stmt_tx_id
        FROM payments p 
        JOIN users u ON p.user_id = u.id 
        LEFT JOIN statements s ON s.matched_payment_id = p.id
        WHERE p.user_id = ? ORDER BY p.date DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $payments = [];
    while ($row = $result->fetch_assoc()) {
        $row['amount'] = floatval($row['amount'] ?? 0);
        $row['usdt_amount'] = $row['usdt_amount'] ? floatval($row['usdt_amount']) : null;
        $row['commission_pct'] = $commission_pct;
        
        // Use statement amount (what was actually received) if matched
        $stmt_net = $row['stmt_net'] !== null ? floatval($row['stmt_net']) : null;
        $stmt_gross = $row['stmt_gross'] !== null ? floatval($row['stmt_gross']) : null;
        $stmt_fee = $row['stmt_fee'] !== null ? floatval($row['stmt_fee']) : null;
        $stmt_currency = $row['stmt_currency'] ?? null;
        
        if ($stmt_gross !== null && $stmt_currency) {
            $stmt_amount = ($stmt_net !== null && $stmt_net > 0) ? $stmt_net : $stmt_gross;
            $row['approved_amount'] = round($stmt_amount, 2);
            $row['approved_currency'] = $stmt_currency;
            $row['stmt_fee'] = $stmt_fee ? round($stmt_fee, 2) : 0;
            $row['amount_usd'] = round(convert_to_usd($stmt_amount, $stmt_currency), 2);
        } else {
            $row['approved_amount'] = $row['amount'];
            $row['approved_currency'] = $row['currency'] ?? 'USD';
            $row['stmt_fee'] = 0;
            $row['amount_usd'] = round(convert_to_usd($row['amount'], $row['currency'] ?? 'USD'), 2);
        }
        
        // Calculate commission and payout
        $commission_amount = round($row['amount_usd'] * $commission_pct / 100, 2);
        $exchange_fee = 2.0; // $2 exchange fee
        $payout = round($row['amount_usd'] - $commission_amount - $exchange_fee, 2);
        $row['commission_amount'] = $commission_amount;
        $row['exchange_fee'] = $exchange_fee;
        $row['calculated_payout'] = max(0, $payout);
        
        $row['effective_wallet'] = ($row['wallet_address'] ?? '') ?: ($row['user_wallet_address'] ?? '');
        $payments[] = $row;
    }
    $stmt->close();
    
    // Get matched statements for this user (via payments)
    $stmt = $db->prepare("SELECT s.* FROM statements s JOIN payments p ON s.matched_payment_id = p.id WHERE p.user_id = ? ORDER BY s.tx_date DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $statements = [];
    while ($row = $result->fetch_assoc()) {
        $row['gross'] = floatval($row['gross'] ?? 0);
        $row['fee'] = floatval($row['fee'] ?? 0);
        $row['net'] = floatval($row['net'] ?? 0);
        $net_val = $row['net'] > 0 ? $row['net'] : $row['gross'];
        $row['net_usd'] = round(convert_to_usd($net_val, $row['currency'] ?? 'USD'), 2);
        $statements[] = $row;
    }
    $stmt->close();
    
    // Get payment logs for this user
    $stmt = $db->prepare("SELECT * FROM payment_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $logs = [];
    while ($row = $result->fetch_assoc()) {
        $row['usdt_amount'] = floatval($row['usdt_amount'] ?? 0);
        $logs[] = $row;
    }
    $stmt->close();
    
    $db->close();
    json_response([
        'user' => $user,
        'payments' => $payments,
        'statements' => $statements,
        'logs' => $logs,
    ]);
}

$result = $db->query("SELECT id, full_name, email, wallet_address, wallet_name, whitelisted, whatsapp_number, telegram_id, status, commission_pct FROM users ORDER BY id DESC");
$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$db->close();

json_response(['users' => $users]);
?>
