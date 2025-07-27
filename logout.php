<?php
session_start();
require_once 'config/database.php';

// Log the logout activity if user is logged in
if (isset($_SESSION['user_id'])) {
    try {
        $db = Database::getInstance();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $db->query(
            "INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)",
            [$_SESSION['user_id'], 'Logout', 'User logged out successfully', $ip, $userAgent]
        );
    } catch (Exception $e) {
        error_log("Logout logging error: " . $e->getMessage());
    }
}

// Destroy session
session_destroy();

// Clear session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Redirect to login page
header('Location: index.php?message=logged_out');
exit;
?>