<?php
/**
 * LIU Parking System - Logout Handler
 * Handles user logout and session cleanup
 */

session_start();

// Log the logout activity if user was logged in
if (isset($_SESSION['user_id'])) {
    // You can add activity logging here if needed
    $user_name = $_SESSION['user_name'] ?? 'Unknown User';
    error_log("User logout: " . $user_name);
}

// Destroy the session
session_destroy();

// Clear the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Redirect to login page with logout message
header('Location: login.php?logout=1');
exit;
?>