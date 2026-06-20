<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('GET method required', 405);
}

$db = get_db();

$result = $db->query("SELECT id, full_name, email, wallet_address, wallet_name, whitelisted, whatsapp_number, telegram_id, status, commission_pct FROM users ORDER BY id ASC");
$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$db->close();

// Override JSON headers from config.php for CSV output
header_remove('Content-Type');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Users_' . date('dmY') . '.csv"');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');

$output = fopen('php://output', 'w');
// BOM for Excel UTF-8 compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Header row
fputcsv($output, ['ID', 'Full Name', 'Email', 'Wallet Address', 'Wallet Name', 'Whitelisted', 'WhatsApp', 'Telegram', 'Status', 'Commission %']);

foreach ($users as $u) {
    fputcsv($output, [
        $u['id'],
        $u['full_name'],
        $u['email'],
        $u['wallet_address'] ?: '',
        $u['wallet_name'] ?: '',
        $u['whitelisted'] ? 'Yes' : 'No',
        $u['whatsapp_number'] ?: '',
        $u['telegram_id'] ?: '',
        $u['status'],
        $u['commission_pct'] ?? 20,
    ]);
}

fclose($output);
exit();
?>
