<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Check if user is logged in
requireLogin();

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Doctor ID required']);
    exit;
}

$id = (int)$_GET['id'];
$view = isset($_GET['view']);

try {
    $db = Database::getInstance();
    
    // Get doctor details with department info
    $stmt = $db->query(
        "SELECT d.*, dept.name as department_name 
         FROM doctors d 
         LEFT JOIN departments dept ON d.department_id = dept.id 
         WHERE d.id = ? AND d.deleted_at IS NULL",
        [$id]
    );
    
    $doctor = $stmt->fetch();
    
    if (!$doctor) {
        echo json_encode(['success' => false, 'message' => 'Doctor not found']);
        exit;
    }
    
    if ($view) {
        // Return HTML for view modal
        $age = $doctor['date_of_birth'] ? getAgeFromDOB($doctor['date_of_birth']) : 'N/A';
        
        $html = "
        <div class='row'>
            <div class='col-md-6'>
                <div class='mb-3'>
                    <strong>Employee ID:</strong><br>
                    <span class='text-muted'>{$doctor['employee_id']}</span>
                </div>
                <div class='mb-3'>
                    <strong>Full Name:</strong><br>
                    <span class='text-muted'>{$doctor['name']}</span>
                </div>
                <div class='mb-3'>
                    <strong>Email:</strong><br>
                    <span class='text-muted'>{$doctor['email']}</span>
                </div>
                <div class='mb-3'>
                    <strong>Phone:</strong><br>
                    <span class='text-muted'>{$doctor['phone']}</span>
                </div>
                <div class='mb-3'>
                    <strong>Specialization:</strong><br>
                    <span class='text-muted'>{$doctor['specialization']}</span>
                </div>
                <div class='mb-3'>
                    <strong>Department:</strong><br>
                    <span class='text-muted'>" . ($doctor['department_name'] ?? 'N/A') . "</span>
                </div>
                <div class='mb-3'>
                    <strong>Qualification:</strong><br>
                    <span class='text-muted'>{$doctor['qualification']}</span>
                </div>
            </div>
            <div class='col-md-6'>
                <div class='mb-3'>
                    <strong>Experience:</strong><br>
                    <span class='text-muted'>{$doctor['experience']} years</span>
                </div>
                <div class='mb-3'>
                    <strong>Consultation Fee:</strong><br>
                    <span class='text-muted'>" . formatCurrency($doctor['consultation_fee']) . "</span>
                </div>
                <div class='mb-3'>
                    <strong>Date of Birth:</strong><br>
                    <span class='text-muted'>" . ($doctor['date_of_birth'] ? formatDate($doctor['date_of_birth'], 'd-m-Y') : 'N/A') . "</span>
                </div>
                <div class='mb-3'>
                    <strong>Age:</strong><br>
                    <span class='text-muted'>{$age}</span>
                </div>
                <div class='mb-3'>
                    <strong>Gender:</strong><br>
                    <span class='text-muted'>" . ($doctor['gender'] ?: 'N/A') . "</span>
                </div>
                <div class='mb-3'>
                    <strong>License Number:</strong><br>
                    <span class='text-muted'>" . ($doctor['license_number'] ?: 'N/A') . "</span>
                </div>
                <div class='mb-3'>
                    <strong>Status:</strong><br>
                    <span class='badge bg-" . ($doctor['status'] == 'active' ? 'success' : 'danger') . "'>" . ucfirst($doctor['status']) . "</span>
                </div>
            </div>
        </div>";
        
        if ($doctor['address']) {
            $html .= "
            <div class='row mt-3'>
                <div class='col-12'>
                    <div class='mb-3'>
                        <strong>Address:</strong><br>
                        <span class='text-muted'>{$doctor['address']}</span>
                    </div>
                </div>
            </div>";
        }
        
        if ($doctor['emergency_contact']) {
            $html .= "
            <div class='row'>
                <div class='col-12'>
                    <div class='mb-3'>
                        <strong>Emergency Contact:</strong><br>
                        <span class='text-muted'>{$doctor['emergency_contact']}</span>
                    </div>
                </div>
            </div>";
        }
        
        $html .= "
        <div class='row mt-3'>
            <div class='col-md-6'>
                <div class='mb-3'>
                    <strong>Created At:</strong><br>
                    <span class='text-muted'>" . formatDate($doctor['created_at']) . "</span>
                </div>
            </div>
            <div class='col-md-6'>
                <div class='mb-3'>
                    <strong>Last Updated:</strong><br>
                    <span class='text-muted'>" . formatDate($doctor['updated_at']) . "</span>
                </div>
            </div>
        </div>";
        
        echo json_encode(['success' => true, 'html' => $html]);
    } else {
        // Return doctor data for editing
        echo json_encode(['success' => true, 'doctor' => $doctor]);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>