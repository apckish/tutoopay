<?php
require_once __DIR__ . '/config.php';

$result = coinex_request('GET', '/v2/assets/withdraw-history?ccy=USDT', null);
json_response($result);
?>
