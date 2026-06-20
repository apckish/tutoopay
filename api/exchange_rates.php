<?php
require_once __DIR__ . '/config.php';

// Return all exchange rates (cached, from ExchangeRate-API)
$cache_file = sys_get_temp_dir() . '/exchange_rates_usd.json';
$cache_max_age = 3600; // 1 hour
$rates = null;

// Check cache first
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_max_age) {
    $cached = @json_decode(file_get_contents($cache_file), true);
    if ($cached && isset($cached['conversion_rates'])) {
        $rates = $cached['conversion_rates'];
    }
}

// Fetch fresh rates if cache is stale
if (!$rates) {
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $url = 'https://v6.exchangerate-api.com/v6/' . EXCHANGERATE_API_KEY . '/latest/USD';
    $json = @file_get_contents($url, false, $ctx);
    if ($json) {
        $data = @json_decode($json, true);
        if ($data && isset($data['conversion_rates'])) {
            $rates = $data['conversion_rates'];
            @file_put_contents($cache_file, $json);
        }
    }
}

if (!$rates) {
    // Fallback hardcoded rates
    $rates = [
        'USD' => 1, 'CAD' => 1/CAD_TO_USD_RATE, 'EUR' => 1/EUR_TO_USD_RATE,
        'GBP' => 1/GBP_TO_USD_RATE, 'TRY' => 1/TRY_TO_USD_RATE,
        'JPY' => 1/JPY_TO_USD_RATE, 'CNY' => 1/CNY_TO_USD_RATE,
    ];
}

json_response(['rates' => $rates, 'base' => 'USD']);
?>
