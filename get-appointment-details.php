<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Check if user is logged in
requireLogin();

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Appointment ID required']);
    exit;
}

$id = (int)$_GET['id'];
$view = isset($_GET['view']);

try {
    $db = Database::getInstance();
    
    // Get appointment details with patient and doctor info
    $stmt = $db->query(
        "SELECT a.*, 
                p.name as patient_name, p.patient_id as patient_number, p.phone as patient_phone, 
                p.email as patient_email, p.date_of_birth as patient_dob, p.gender as patient_gender,
                d.name as doctor_name, d.employee_id as doctor_employee_id, d.specialization, 
                d.phone as doctor_phone, d.email as doctor_email,
                dept.name as department_name
         FROM appointments a 
         JOIN patients p ON a.patient_id = p.id 
         JOIN doctors d ON a.doctor_id = d.id 
         LEFT JOIN departments dept ON d.department_id = dept.id
         WHERE a.id = ?",
        [$id]
    );
    
    $appointment = $stmt->fetch();
    
    if (!$appointment) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found']);
        exit;
    }
    
    if ($view) {
        // Return HTML for view modal
        $appointmentDate = formatDate($appointment['appointment_date'], 'd M Y');
        $appointmentTime = date('h:i A', strtotime($appointment['appointment_time']));
        $patientAge = $appointment['patient_dob'] ? getAgeFromDOB($appointment['patient_dob']) : 'N/A';
        
        $html = "
        <div class='row'>
            <div class='col-md-6'>
                <h6 class='text-primary mb-3'><i class='fas fa-calendar-check me-2'></i>Appointment Information</h6>
                <div class='mb-3'>
                    <strong>Appointment ID:</strong><br>
                    <span class='text-muted'>{$appointment['appointment_id']}</span>
                </div>
                <div class='mb-3'>
                    <strong>Date & Time:</strong><br>
                    <span class='text-muted'>$appointmentDate at $appointmentTime</span>
                </div>
                <div class='mb-3'>
                    <strong>Status:</strong><br>
                    <span class='badge bg-" . ($appointment['status'] == 'completed' ? 'success' : ($appointment['status'] == 'cancelled' ? 'danger' : 'primary')) . "'>" . ucfirst($appointment['status']) . "</span>
                </div>
                <div class='mb-3'>
                    <strong>Reason for Visit:</strong><br>
                    <span class='text-muted'>{$appointment['reason']}</span>
                </div>";
        
        if ($appointment['notes']) {
            $html .= "
                <div class='mb-3'>
                    <strong>Notes:</strong><br>
                    <span class='text-muted'>{$appointment['notes']}</span>
                </div>";
        }
        
        $html .= "
            </div>
            <div class='col-md-6'>
                <h6 class='text-primary mb-3'><i class='fas fa-user-injured me-2'></i>Patient Information</h6>
                <div class='mb-3'>
                    <strong>Name:</strong><br>
                    <span class='text-muted'>{$appointment['patient_name']}</span>
                </div>
                <div class='mb-3'>
                    <strong>Patient ID:</strong><br>
                    <span class='text-muted'>{$appointment['patient_number']}</span>
                </div>
                <div class='mb-3'>
                    <strong>Phone:</strong><br>
                    <span class='text-muted'>{$appointment['patient_phone']}</span>
                </div>
                <div class='mb-3'>
                    <strong>Email:</strong><br>
                    <span class='text-muted'>{$appointment['patient_email']}</span>
                </div>
                <div class='mb-3'>
                    <strong>Age/Gender:</strong><br>
                    <span class='text-muted'>$patientAge / {$appointment['patient_gender']}</span>
                </div>
            </div>
        </div>
        
        <div class='row mt-3'>
            <div class='col-12'>
                <h6 class='text-primary mb-3'><i class='fas fa-user-md me-2'></i>Doctor Information</h6>
                <div class='row'>
                    <div class='col-md-6'>
                        <div class='mb-3'>
                            <strong>Doctor Name:</strong><br>
                            <span class='text-muted'>{$appointment['doctor_name']}</span>
                        </div>
                        <div class='mb-3'>
                            <strong>Employee ID:</strong><br>
                            <span class='text-muted'>{$appointment['doctor_employee_id']}</span>
                        </div>
                    </div>
                    <div class='col-md-6'>
                        <div class='mb-3'>
                            <strong>Specialization:</strong><br>
                            <span class='text-muted'>{$appointment['specialization']}</span>
                        </div>
                        <div class='mb-3'>
                            <strong>Department:</strong><br>
                            <span class='text-muted'>" . ($appointment['department_name'] ?? 'N/A') . "</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>";
        
        if ($appointment['consultation_notes']) {
            $html .= "
            <div class='row mt-3'>
                <div class='col-12'>
                    <h6 class='text-primary mb-3'><i class='fas fa-notes-medical me-2'></i>Consultation Notes</h6>
                    <div class='mb-3'>
                        <span class='text-muted'>{$appointment['consultation_notes']}</span>
                    </div>
                </div>
            </div>";
        }
        
        if ($appointment['cancellation_reason']) {
            $html .= "
            <div class='row mt-3'>
                <div class='col-12'>
                    <h6 class='text-danger mb-3'><i class='fas fa-times-circle me-2'></i>Cancellation Reason</h6>
                    <div class='mb-3'>
                        <span class='text-muted'>{$appointment['cancellation_reason']}</span>
                    </div>
                </div>
            </div>";
        }
        
        $html .= "
        <div class='row mt-3'>
            <div class='col-md-6'>
                <div class='mb-3'>
                    <strong>Created At:</strong><br>
                    <span class='text-muted'>" . formatDate($appointment['created_at']) . "</span>
                </div>
            </div>
            <div class='col-md-6'>
                <div class='mb-3'>
                    <strong>Last Updated:</strong><br>
                    <span class='text-muted'>" . formatDate($appointment['updated_at']) . "</span>
                </div>
            </div>
        </div>";
        
        echo json_encode(['success' => true, 'html' => $html]);
    } else {
        // Return appointment data for editing
        echo json_encode(['success' => true, 'appointment' => $appointment]);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>