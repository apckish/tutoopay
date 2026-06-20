<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('GET method required', 405);
}

$db = get_db();

// Find PayPal statements that have an exact Payoneer match (same date, name, gross, currency)
$sql = "SELECT pp.id as paypal_id, pp.tx_date, pp.name, pp.gross, pp.currency, pp.status as paypal_status, pp.tx_id,
               pn.id as payoneer_id, pn.status as payoneer_status
        FROM statements pp
        JOIN statements pn ON pp.tx_date = pn.tx_date AND pp.gross = pn.gross AND pp.name = pn.name AND pp.currency = pn.currency
        WHERE pp.source = 'PayPal' AND pn.source = 'Payoneer'
        ORDER BY pp.tx_date DESC, pp.id DESC";

$result = $db->query($sql);
$duplicates = [];
while ($row = $result->fetch_assoc()) {
    $row['gross'] = floatval($row['gross']);
    $row['paypal_id'] = intval($row['paypal_id']);
    $row['payoneer_id'] = intval($row['payoneer_id']);
    $duplicates[] = $row;
}

$db->close();

json_response([
    'duplicates' => $duplicates,
    'total' => count($duplicates),
]);
?>
