<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('GET method required', 405);
}

$db = get_db();

// Collect all unique wallet addresses from two sources:
// 1. User default wallet addresses
// 2. Per-payment wallet addresses from pending records
$address_map = []; // key = wallet_address, value = [full_name, wallet_name]
$user_ids_to_whitelist = [];

// Source 1: User default wallet addresses
$result = $db->query("
    SELECT u.id, u.full_name, u.wallet_address, u.wallet_name
    FROM users u
    WHERE u.wallet_address IS NOT NULL 
      AND u.wallet_address != ''
    ORDER BY u.full_name ASC
");
while ($row = $result->fetch_assoc()) {
    $addr = $row['wallet_address'];
    if (!isset($address_map[$addr])) {
        $address_map[$addr] = [
            'full_name' => $row['full_name'],
            'wallet_name' => $row['wallet_name'] ?: $row['full_name'],
        ];
    }
    $user_ids_to_whitelist[] = intval($row['id']);
}

// Source 2: Per-payment wallet addresses from pending records
$result2 = $db->query("
    SELECT p.wallet_address, u.full_name, u.wallet_name
    FROM payments p
    JOIN users u ON p.user_id = u.id
    WHERE p.status = 'Pending'
      AND p.wallet_address IS NOT NULL 
      AND p.wallet_address != ''
    ORDER BY u.full_name ASC
");
while ($row = $result2->fetch_assoc()) {
    $addr = $row['wallet_address'];
    if (!isset($address_map[$addr])) {
        $address_map[$addr] = [
            'full_name' => $row['full_name'],
            'wallet_name' => $row['wallet_name'] ?: $row['full_name'],
        ];
    }
}

// Mark exported users as whitelisted
if (!empty($user_ids_to_whitelist)) {
    $id_list = implode(',', array_unique($user_ids_to_whitelist));
    $db->query("UPDATE users SET whitelisted = 1 WHERE id IN ($id_list)");
}
$db->close();

// Output CSV in CoinEx import format
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="WhiteList' . date('dmY') . '.csv"');
header('Access-Control-Allow-Origin: *');

$output = fopen('php://output', 'w');
// Header row matching CoinEx sample format
fputcsv($output, ['remark ', 'coin', 'network ', 'address ', 'memo']);

foreach ($address_map as $addr => $info) {
    fputcsv($output, [
        $info['full_name'],       // remark = user full name
        'USDT',                   // coin
        'Tron',                   // network
        $addr,                    // address = wallet address
        $info['wallet_name'],     // memo = wallet_name (fallback to full_name)
    ]);
}

fclose($output);
exit();
?>
