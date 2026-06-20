<?php
require_once __DIR__ . '/config.php';

$transactions = $_SESSION['uploaded_transactions'];
foreach ($transactions as &$tx) {
    $tx['is_matched'] = isset($_SESSION['matched_tx_ids'][$tx['tx_id']]);
}

json_response(['transactions' => $transactions]);
?>
