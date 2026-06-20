<?php
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Only admins can manage users; supervisors can only change their own password
$current_role = $_SESSION['admin_role'] ?? 'supervisor';
$current_user_id = $_SESSION['admin_user_id'] ?? 0;

// GET: list admin users (admin only)
if ($method === 'GET' && $action === 'users') {
    if ($current_role !== 'admin') {
        json_error('Only admins can view admin users', 403);
    }
    $db = get_db();
    $result = $db->query("SELECT id, username, role, is_active, created_at FROM admin_users ORDER BY id ASC");
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = intval($row['id']);
        $row['is_active'] = intval($row['is_active']);
        $users[] = $row;
    }
    $db->close();
    json_response(['users' => $users]);
}

// POST: change own password
if ($method === 'POST' && $action === 'change_password') {
    $body = get_json_body();
    $current_password = $body['current_password'] ?? '';
    $new_password = $body['new_password'] ?? '';
    
    if (!$current_password || !$new_password) {
        json_error('Current and new password required');
    }
    if (strlen($new_password) < 6) {
        json_error('New password must be at least 6 characters');
    }
    
    $db = get_db();
    $stmt = $db->prepare("SELECT password_hash FROM admin_users WHERE id = ?");
    $stmt->bind_param("i", $current_user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$user || !password_verify($current_password, $user['password_hash'])) {
        $db->close();
        json_error('Current password is incorrect');
    }
    
    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?");
    $stmt->bind_param("si", $new_hash, $current_user_id);
    $stmt->execute();
    $stmt->close();
    $db->close();
    
    json_response(['success' => true, 'message' => 'Password changed successfully']);
}

// POST: add admin/supervisor (admin only)
if ($method === 'POST' && $action === 'add_user') {
    if ($current_role !== 'admin') {
        json_error('Only admins can add users', 403);
    }
    $body = get_json_body();
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';
    $role = $body['role'] ?? 'supervisor';
    
    if (!$username || !$password) {
        json_error('Username and password required');
    }
    if (!in_array($role, ['admin', 'supervisor'])) {
        json_error('Role must be admin or supervisor');
    }
    if (strlen($password) < 6) {
        json_error('Password must be at least 6 characters');
    }
    
    $db = get_db();
    // Check if username exists
    $stmt = $db->prepare("SELECT id FROM admin_users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        $db->close();
        json_error('Username already exists');
    }
    $stmt->close();
    
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO admin_users (username, password_hash, role) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $hash, $role);
    $stmt->execute();
    $new_id = $db->insert_id;
    $stmt->close();
    $db->close();
    
    json_response(['success' => true, 'message' => "User '$username' created as $role", 'id' => $new_id]);
}

// POST: toggle active status (admin only)
if ($method === 'POST' && $action === 'toggle_user') {
    if ($current_role !== 'admin') {
        json_error('Only admins can manage users', 403);
    }
    $body = get_json_body();
    $user_id = intval($body['user_id'] ?? 0);
    if (!$user_id) json_error('user_id required');
    if ($user_id === $current_user_id) json_error('Cannot deactivate yourself');
    
    $db = get_db();
    $stmt = $db->prepare("UPDATE admin_users SET is_active = IF(is_active=1, 0, 1) WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
    $db->close();
    
    json_response(['success' => true, 'message' => 'User status toggled']);
}

// DELETE: remove admin user (admin only)
if ($method === 'DELETE' && $action === 'delete_user') {
    if ($current_role !== 'admin') {
        json_error('Only admins can delete users', 403);
    }
    $user_id = intval($_GET['user_id'] ?? 0);
    if (!$user_id) json_error('user_id required');
    if ($user_id === $current_user_id) json_error('Cannot delete yourself');
    
    $db = get_db();
    $stmt = $db->prepare("DELETE FROM admin_users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
    $db->close();
    
    json_response(['success' => true, 'message' => 'User deleted']);
}

json_error('Invalid action. Use ?action=users|change_password|add_user|toggle_user|delete_user', 400);
?>
