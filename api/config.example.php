<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', '');
define('DB_PASS', '');
define('DB_NAME', '');

// CoinEx API Configuration
define('COINEX_ACCESS_ID', '');
define('COINEX_SECRET_KEY', '');
define('COINEX_BASE_URL', 'https://api.coinex.com');

// Matching Configuration
define('AMOUNT_TOLERANCE', 0.05); // 5%
define('DATE_RANGE_DAYS', 10);
define('CAD_TO_USD_RATE', 0.72);
define('EUR_TO_USD_RATE', 1.08);
define('GBP_TO_USD_RATE', 1.26);
define('TRY_TO_USD_RATE', 0.031);
define('JPY_TO_USD_RATE', 0.0067);
define('CNY_TO_USD_RATE', 0.14);

// CORS headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function get_db() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(['error' => 'Database connection failed']);
        exit();
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function json_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit();
}

function json_error($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['detail' => $message]);
    exit();
}

function get_json_body() {
    $body = file_get_contents('php://input');
    return json_decode($body, true) ?: [];
}

// CoinEx API helpers
function coinex_sign($method, $request_path, $body_str, $timestamp) {
    $prepared = $method . $request_path . $body_str . strval($timestamp);
    return strtolower(hash_hmac('sha256', $prepared, COINEX_SECRET_KEY));
}

function coinex_request($method, $path, $body = null) {
    $timestamp = intval(microtime(true) * 1000);
    $body_str = $body ? json_encode($body, JSON_UNESCAPED_SLASHES) : '';
    $signature = coinex_sign($method, $path, $body_str, $timestamp);

    $headers = [
        'Content-Type: application/json',
        'X-COINEX-KEY: ' . COINEX_ACCESS_ID,
        'X-COINEX-SIGN: ' . $signature,
        'X-COINEX-TIMESTAMP: ' . $timestamp,
    ];

    $ch = curl_init(COINEX_BASE_URL . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body_str);
    }

    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// ExchangeRate API
define('EXCHANGERATE_API_KEY', '');

function normalize_currency_code($currency) {
    $currency = strtoupper(trim($currency));
    if (strpos($currency, ' ') !== false) {
        $currency = explode(' ', $currency)[0];
    }
    if (strpos($currency, '-') !== false && strlen($currency) > 3) {
        $currency = explode('-', $currency)[0];
    }
    return trim($currency);
}

function get_live_usd_rate($currency) {
    $currency = normalize_currency_code($currency);
    if ($currency === 'USD') return 1.0;

    $cache_file = sys_get_temp_dir() . '/exchange_rates_usd.json';
    $cache_max_age = 3600;
    $rates = null;

    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_max_age) {
        $cached = @json_decode(file_get_contents($cache_file), true);
        if ($cached && isset($cached['conversion_rates'])) {
            $rates = $cached['conversion_rates'];
        }
    }

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

    if ($rates && isset($rates[$currency])) {
        return 1.0 / floatval($rates[$currency]);
    }

    $fallback = [
        'CAD' => CAD_TO_USD_RATE, 'EUR' => EUR_TO_USD_RATE, 'GBP' => GBP_TO_USD_RATE,
        'TRY' => TRY_TO_USD_RATE, 'JPY' => JPY_TO_USD_RATE, 'CNY' => CNY_TO_USD_RATE,
    ];
    return $fallback[$currency] ?? 1.0;
}

function convert_to_usd($amount, $currency) {
    $currency = normalize_currency_code($currency);
    if ($currency === 'USD') return $amount;
    $rate = get_live_usd_rate($currency);
    return $amount * $rate;
}

// Session management
$custom_session_path = '/home2/gapyggmy/admin_sessions';
if (is_dir($custom_session_path)) {
    ini_set('session.save_path', $custom_session_path);
}
ini_set('session.gc_maxlifetime', 172800);
ini_set('session.cookie_lifetime', 172800);
session_start();
if (!isset($_SESSION['uploaded_transactions'])) {
    $_SESSION['uploaded_transactions'] = [];
}
if (!isset($_SESSION['matched_tx_ids'])) {
    $_SESSION['matched_tx_ids'] = [];
    $db = get_db();
    $result = $db->query("SELECT matched_tx_id FROM payments WHERE matched_tx_id IS NOT NULL AND matched_tx_id != ''");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $_SESSION['matched_tx_ids'][$row['matched_tx_id']] = true;
        }
    }
    $db->close();
}

// Auth check
function require_auth() {
    $script = basename($_SERVER['SCRIPT_FILENAME']);
    if ($script === 'auth.php') return;
    
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(401);
        echo json_encode(['detail' => 'Authentication required', 'authenticated' => false]);
        exit();
    }
}

require_auth();

// Send payment confirmation email
function send_payment_confirmation_email($user_email, $user_name, $original_amount, $currency, $commission_pct, $commission_amount, $exchange_fee, $payout_amount, $wallet_address, $payment_method) {
    $subject = "Payment Confirmed - TutooPay";
    $date = date('F j, Y');
    
    $body = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>';
    $body .= '<body style="margin:0;padding:0;background:#f4f4f7;font-family:Arial,Helvetica,sans-serif;">';
    $body .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:40px 0;"><tr><td align="center">';
    $body .= '<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">';
    $body .= '<tr><td style="background:linear-gradient(135deg,#10b981,#059669);padding:40px 30px;text-align:center;">';
    $body .= '<div style="font-size:48px;margin-bottom:10px;">&#10004;</div>';
    $body .= '<h1 style="color:#ffffff;margin:0;font-size:28px;">Payment Confirmed</h1>';
    $body .= '<p style="color:rgba(255,255,255,0.85);margin:8px 0 0;font-size:14px;">Your payment has been processed successfully</p>';
    $body .= '</td></tr>';
    $body .= '<tr><td style="padding:40px 30px;">';
    $body .= '<h2 style="color:#1f2937;margin:0 0 20px;font-size:22px;">Dear ' . htmlspecialchars($user_name) . ',</h2>';
    $body .= '<p style="color:#4b5563;line-height:1.7;margin:0 0 15px;font-size:15px;">Great news! Your payment has been <strong>processed and confirmed</strong> by TutooPay.</p>';
    $body .= '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:20px;text-align:center;margin:25px 0;">';
    $body .= '<div style="color:#6b7280;font-size:13px;text-transform:uppercase;letter-spacing:1px;">Amount Paid</div>';
    $body .= '<div style="color:#059669;font-size:32px;font-weight:bold;margin:5px 0;">$' . number_format($payout_amount, 2) . ' USDT</div>';
    $body .= '</div>';
    $body .= '<p style="color:#4b5563;line-height:1.7;margin:0 0 15px;font-size:15px;">Wallet: <strong style="word-break:break-all;">' . htmlspecialchars($wallet_address) . '</strong></p>';
    $body .= '<p style="color:#4b5563;line-height:1.7;margin:0 0 15px;font-size:15px;">Date: <strong>' . $date . '</strong></p>';
    $body .= '<p style="color:#4b5563;line-height:1.7;margin:0 0 15px;font-size:15px;">Thank you for choosing <strong>TutooPay</strong> as your payment partner.</p>';
    $body .= '</td></tr>';
    $body .= '<tr><td style="background:#f9fafb;padding:25px 30px;text-align:center;border-top:1px solid #e5e7eb;">';
    $body .= '<p style="color:#9ca3af;font-size:12px;margin:0;">&copy; ' . date('Y') . ' TutooPay. All rights reserved.</p>';
    $body .= '</td></tr>';
    $body .= '</table></td></tr></table></body></html>';
    
    $headers_mail = "MIME-Version: 1.0\r\n";
    $headers_mail .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers_mail .= "From: TutooPay <noreply@tutoopay.com>\r\n";
    $headers_mail .= "Reply-To: support@tutoopay.com\r\n";
    
    return @mail($user_email, $subject, $body, $headers_mail);
}
?>
