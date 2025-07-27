<?php
function formatDate($date, $format = 'Y-m-d H:i:s') {
    if ($date) {
        return date($format, strtotime($date));
    }
    return '';
}

function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' min ago';
    if ($time < 86400) return floor($time/3600) . ' hour' . (floor($time/3600) > 1 ? 's' : '') . ' ago';
    if ($time < 2592000) return floor($time/86400) . ' day' . (floor($time/86400) > 1 ? 's' : '') . ' ago';
    if ($time < 31536000) return floor($time/2592000) . ' month' . (floor($time/2592000) > 1 ? 's' : '') . ' ago';
    
    return floor($time/31536000) . ' year' . (floor($time/31536000) > 1 ? 's' : '') . ' ago';
}

function generatePatientId() {
    return 'PAT' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
}

function generateBillNumber() {
    return 'BILL' . date('Y') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function generateAppointmentId() {
    return 'APT' . date('Ymd') . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
}

function getBloodGroups() {
    return ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
}

function getGenders() {
    return ['Male', 'Female', 'Other'];
}

function getMaritalStatus() {
    return ['Single', 'Married', 'Divorced', 'Widowed'];
}

function getBedTypes() {
    return ['General', 'Private', 'ICU', 'Emergency', 'Pediatric', 'Maternity'];
}

function getRoomTypes() {
    return ['General Ward', 'Private Room', 'ICU', 'Emergency Room', 'Operation Theater', 'Delivery Room', 'Pediatric Ward'];
}

function getInsuranceProviders() {
    return ['HDFC ERGO', 'ICICI Lombard', 'New India Assurance', 'National Insurance', 'United India Insurance', 'Oriental Insurance', 'Bajaj Allianz', 'Reliance General', 'Star Health', 'Max Bupa', 'Apollo Munich', 'Religare Health'];
}

function getAppointmentStatuses() {
    return ['Scheduled', 'Confirmed', 'In Progress', 'Completed', 'Cancelled', 'No Show'];
}

function getBillStatuses() {
    return ['Pending', 'Partially Paid', 'Paid', 'Overdue', 'Cancelled'];
}

function getPaymentMethods() {
    return ['Cash', 'Card', 'UPI', 'Net Banking', 'Cheque', 'Insurance'];
}

function uploadFile($file, $uploadDir = 'uploads/') {
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $fileName = time() . '_' . basename($file['name']);
    $targetFile = $uploadDir . $fileName;
    
    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        return $fileName;
    }
    
    return false;
}

function deleteFile($fileName, $uploadDir = 'uploads/') {
    $filePath = $uploadDir . $fileName;
    if (file_exists($filePath)) {
        return unlink($filePath);
    }
    return true;
}

function sendEmail($to, $subject, $message, $headers = '') {
    if (empty($headers)) {
        $headers = "From: hospital@example.com\r\n";
        $headers .= "Reply-To: hospital@example.com\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    }
    
    return mail($to, $subject, $message, $headers);
}

function logActivity($user_id, $action, $details = '') {
    try {
        $db = Database::getInstance();
        $db->query(
            "INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())",
            [$user_id, $action, $details]
        );
    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
    }
}

function getAgeFromDOB($dob) {
    $today = new DateTime();
    $birthDate = new DateTime($dob);
    $age = $today->diff($birthDate);
    return $age->y;
}

function generatePassword($length = 8) {
    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $password;
}

function getPagination($currentPage, $totalPages, $url) {
    $pagination = '<nav aria-label="Page navigation"><ul class="pagination justify-content-center">';
    
    if ($currentPage > 1) {
        $pagination .= '<li class="page-item"><a class="page-link" href="' . $url . '&page=' . ($currentPage - 1) . '">Previous</a></li>';
    }
    
    for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++) {
        $active = ($i == $currentPage) ? 'active' : '';
        $pagination .= '<li class="page-item ' . $active . '"><a class="page-link" href="' . $url . '&page=' . $i . '">' . $i . '</a></li>';
    }
    
    if ($currentPage < $totalPages) {
        $pagination .= '<li class="page-item"><a class="page-link" href="' . $url . '&page=' . ($currentPage + 1) . '">Next</a></li>';
    }
    
    $pagination .= '</ul></nav>';
    return $pagination;
}
?>