<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('GET method required', 405);
}

$db = get_db();

$limit = intval($_GET['limit'] ?? 100);
$offset = intval($_GET['offset'] ?? 0);
if ($limit > 500) $limit = 500;

$result = $db->query("SELECT * FROM payment_logs ORDER BY created_at DESC LIMIT $limit OFFSET $offset");

$logs = [];
while ($row = $result->fetch_assoc()) {
    $row['id'] = intval($row['id']);
    $row['payment_id'] = intval($row['payment_id']);
    $row['user_id'] = intval($row['user_id']);
    $row['usdt_amount'] = floatval($row['usdt_amount']);
    if ($row['statement_id']) $row['statement_id'] = intval($row['statement_id']);
    $logs[] = $row;
}

$count_result = $db->query("SELECT COUNT(*) as total FROM payment_logs");
$total = intval($count_result->fetch_assoc()['total']);

$db->close();

json_response([
    'logs' => $logs,
    'total' => $total,
    'limit' => $limit,
    'offset' => $offset,
]);
?>
