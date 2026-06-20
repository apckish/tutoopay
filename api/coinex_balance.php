<?php
require_once __DIR__ . '/config.php';

$result = coinex_request('GET', '/v2/assets/spot/balance', null);
json_response($result);
?>
