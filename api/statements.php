<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('GET method required', 405);
}

$source = $_GET['source'] ?? '';
$status = $_GET['status'] ?? '';

$db = get_db();

$where = [];
$params = [];
$types = '';

if ($source) {
    $where[] = "s.source = ?";
    $params[] = $source;
    $types .= 's';
}
if ($status) {
    $where[] = "s.status = ?";
    $params[] = $status;
    $types .= 's';
}

$sql = "SELECT s.*, p.user_id AS matched_user_id, u.full_name AS matched_user_name, p.receipt_url AS matched_receipt_url FROM statements s LEFT JOIN payments p ON s.matched_payment_id = p.id LEFT JOIN users u ON p.user_id = u.id";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY s.tx_date DESC, s.id DESC";

if ($params) {
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $db->query($sql);
}

$statements = [];
while ($row = $result->fetch_assoc()) {
    $row['gross'] = floatval($row['gross']);
    $row['fee'] = floatval($row['fee']);
    $row['net'] = floatval($row['net']);
    $row['id'] = intval($row['id']);
    if ($row['matched_payment_id']) $row['matched_payment_id'] = intval($row['matched_payment_id']);
    $statements[] = $row;
}

// Get source summary as flat array [{source, status, cnt}, ...]
$summary_result = $db->query("SELECT source, status, COUNT(*) as cnt FROM statements GROUP BY source, status ORDER BY source");
$summary = [];
while ($row = $summary_result->fetch_assoc()) {
    $summary[] = [
        'source' => $row['source'],
        'status' => $row['status'],
        'cnt' => intval($row['cnt']),
    ];
}

$db->close();

json_response([
    'statements' => $statements,
    'total' => count($statements),
    'summary' => $summary,
]);
?>
