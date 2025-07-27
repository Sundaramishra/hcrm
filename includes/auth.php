<?php
function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }
}

function requireRole($allowedRoles = []) {
    requireLogin();
    
    if (!empty($allowedRoles) && !in_array($_SESSION['role'], $allowedRoles)) {
        header('Location: dashboard.php?error=access_denied');
        exit;
    }
}

function hasPermission($permission) {
    if (!isset($_SESSION['permissions'])) {
        return false;
    }
    return in_array($permission, $_SESSION['permissions']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isDoctor() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'doctor';
}

function isNurse() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'nurse';
}

function isReceptionist() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'receptionist';
}

function isAccountant() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'accountant';
}

function isLabTechnician() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'lab_technician';
}

function isPharmacist() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'pharmacist';
}

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getCurrentUserRole() {
    return $_SESSION['role'] ?? null;
}

function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

function csrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateToken(16);
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
?>