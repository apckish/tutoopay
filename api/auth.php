<?php
// Auth endpoint - login, logout, check
// Must be included BEFORE config.php sets JSON content type for login page
$is_api = true;

require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$action = $_GET['action'] ?? '';

if ($action === 'check') {
    // Check if user is logged in
    if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
        json_response(['authenticated' => true, 'username' => $_SESSION['admin_username'] ?? 'admin', 'role' => $_SESSION['admin_role'] ?? 'supervisor']);
    } else {
        json_response(['authenticated' => false]);
    }
}

if ($action === 'logout') {
    session_destroy();
    json_response(['success' => true, 'message' => 'Logged out']);
}

if ($method === 'POST' && $action === 'login') {
    $body = get_json_body();
    $username = $body['username'] ?? '';
    $password = $body['password'] ?? '';
    
    if (!$username || !$password) {
        json_error('Username and password required');
    }
    
    // Check credentials against admin_users table
    $db = get_db();
    $stmt = $db->prepare("SELECT id, username, password_hash, role FROM admin_users WHERE username = ? AND is_active = 1");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $db->close();
    
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $user['username'];
        $_SESSION['admin_user_id'] = $user['id'];
        $_SESSION['admin_role'] = $user['role'] ?? 'supervisor';
        json_response(['success' => true, 'message' => 'Login successful', 'username' => $user['username'], 'role' => $user['role'] ?? 'supervisor']);
    } else {
        json_error('Invalid username or password', 401);
    }
}

json_error('Invalid action. Use ?action=login|logout|check', 400);
?>
