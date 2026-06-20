<?php
require_once __DIR__ . '/config.php';

$db = get_db();

// Get all approved + unpaid payments grouped by user, with matched statement amounts
$query = "SELECT p.id, p.user_id, p.amount, p.currency, p.payment_method, p.sender_name, p.date, p.transfer_date, p.status, p.paid_status, p.matched_tx_id, p.wallet_address as payment_wallet, p.receipt_url,
          u.full_name as user_name, u.email as user_email, u.wallet_address as user_wallet_address, u.wallet_name, u.whitelisted, u.commission_pct,
          s.gross as stmt_gross, s.net as stmt_net, s.currency as stmt_currency
          FROM payments p JOIN users u ON p.user_id = u.id 
          LEFT JOIN statements s ON s.matched_payment_id = p.id
          WHERE p.status = 'Approved' AND p.paid_status = 'unpaid' AND p.payout_group_id IS NULL
          ORDER BY p.user_id, p.date DESC";
$result = $db->query($query);

$payments_by_user = [];
while ($row = $result->fetch_assoc()) {
    $uid = intval($row['user_id']);
    $row['amount'] = floatval($row['amount']);
    
    // Use statement amount (what was actually received) if a matched statement exists
    $stmt_net = $row['stmt_net'] !== null ? floatval($row['stmt_net']) : null;
    $stmt_gross = $row['stmt_gross'] !== null ? floatval($row['stmt_gross']) : null;
    $stmt_currency = $row['stmt_currency'] ?? null;
    
    // Keep original user-claimed amount
    $row['claimed_amount'] = $row['amount'];
    $row['claimed_currency'] = $row['currency'] ?? 'USD';
    
    if ($stmt_gross !== null && $stmt_currency) {
        // Use statement net if available and > 0, otherwise use gross
        $stmt_amount = ($stmt_net !== null && $stmt_net > 0) ? $stmt_net : $stmt_gross;
        $row['approved_amount'] = $stmt_amount;
        $row['approved_currency'] = $stmt_currency;
        $row['stmt_gross'] = $stmt_gross;
        $row['stmt_gross_currency'] = $stmt_currency;
        $row['amount_usd'] = convert_to_usd($stmt_amount, $stmt_currency);
    } else {
        // No matched statement — use payment amount (user-entered)
        $row['approved_amount'] = $row['amount'];
        $row['approved_currency'] = $row['currency'] ?? 'USD';
        $row['amount_usd'] = convert_to_usd($row['amount'], $row['currency'] ?? 'USD');
    }
    
    $row['commission_pct'] = floatval($row['commission_pct'] ?? 20);
    $row['effective_wallet'] = ($row['payment_wallet'] ?? '') ?: ($row['user_wallet_address'] ?? '');
    $row['whitelisted'] = intval($row['whitelisted'] ?? 0);
    if (!isset($payments_by_user[$uid])) {
        $payments_by_user[$uid] = [
            'user_id' => $uid,
            'user_name' => $row['user_name'],
            'user_email' => $row['user_email'],
            'user_wallet_address' => $row['user_wallet_address'] ?? '',
            'wallet_name' => $row['wallet_name'] ?? '',
            'whitelisted' => $row['whitelisted'],
            'commission_pct' => $row['commission_pct'],
            'payments' => [],
            'admin_paid' => [],
            'total_approved_usd' => 0,
            'total_admin_paid_usd' => 0,
        ];
    }
    $payments_by_user[$uid]['payments'][] = $row;
    $payments_by_user[$uid]['total_approved_usd'] += $row['amount_usd'];
}

// Get unbound admin_paid and direct payout logs (not yet consumed in a group)
$log_query = "SELECT id, payment_id, user_id, user_name, user_email, wallet_address, usdt_amount, action, admin_user, created_at, coinex_withdraw_id
              FROM payment_logs 
              WHERE action IN ('admin_paid', 'manual_paid', 'direct_paid') AND payout_group_id IS NULL AND user_id > 0
              ORDER BY user_id, created_at DESC";
$log_result = $db->query($log_query);

while ($row = $log_result->fetch_assoc()) {
    $uid = intval($row['user_id']);
    $row['usdt_amount'] = floatval($row['usdt_amount']);
    if (!isset($payments_by_user[$uid])) {
        // User has admin/direct paid but no approved payments - still show them
        // Look up user info
        $u_stmt = $db->prepare("SELECT full_name, email, wallet_address, wallet_name, whitelisted, commission_pct FROM users WHERE id = ?");
        $u_stmt->bind_param("i", $uid);
        $u_stmt->execute();
        $u_row = $u_stmt->get_result()->fetch_assoc();
        $u_stmt->close();
        if (!$u_row) continue; // skip if user doesn't exist
        $payments_by_user[$uid] = [
            'user_id' => $uid,
            'user_name' => $u_row['full_name'] ?? $row['user_name'],
            'user_email' => $u_row['email'] ?? $row['user_email'],
            'user_wallet_address' => $u_row['wallet_address'] ?? '',
            'wallet_name' => $u_row['wallet_name'] ?? '',
            'whitelisted' => intval($u_row['whitelisted'] ?? 0),
            'commission_pct' => floatval($u_row['commission_pct'] ?? 20),
            'payments' => [],
            'admin_paid' => [],
            'total_approved_usd' => 0,
            'total_admin_paid_usd' => 0,
        ];
    }
    $payments_by_user[$uid]['admin_paid'][] = $row;
    $payments_by_user[$uid]['total_admin_paid_usd'] += $row['usdt_amount'];
}

// Round totals and compute net
foreach ($payments_by_user as &$user) {
    $user['total_approved_usd'] = round($user['total_approved_usd'], 2);
    $user['total_admin_paid_usd'] = round($user['total_admin_paid_usd'], 2);
    $user['net_usd'] = round($user['total_approved_usd'] - $user['total_admin_paid_usd'], 2);
}
unset($user);

$db->close();

// Return as array sorted by user name
$users_list = array_values($payments_by_user);
usort($users_list, function($a, $b) { return strcasecmp($a['user_name'], $b['user_name']); });

json_response(['users' => $users_list, 'total_users' => count($users_list)]);
?>
