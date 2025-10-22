<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();
requireRole(['admin', 'receptionist', 'doctor', 'nurse', 'pharmacist', 'lab_technician']);

$db = Database::getInstance();
$message = '';
$error = '';

// Handle form submissions
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_patient') {
        try {
            $patient_id = generatePatientId();
            $first_name = sanitizeInput($_POST['first_name']);
            $last_name = sanitizeInput($_POST['last_name']);
            $date_of_birth = $_POST['date_of_birth'];
            $gender = $_POST['gender'];
            $blood_group = $_POST['blood_group'] ?? null;
            $phone = sanitizeInput($_POST['phone']);
            $email = sanitizeInput($_POST['email']) ?: null;
            $address = sanitizeInput($_POST['address']);
            $emergency_contact_name = sanitizeInput($_POST['emergency_contact_name']) ?: null;
            $emergency_contact_phone = sanitizeInput($_POST['emergency_contact_phone']) ?: null;
            $marital_status = $_POST['marital_status'] ?? null;
            $occupation = sanitizeInput($_POST['occupation']) ?: null;
            $medical_history = sanitizeInput($_POST['medical_history']) ?: null;
            $allergies = sanitizeInput($_POST['allergies']) ?: null;
            $current_medications = sanitizeInput($_POST['current_medications']) ?: null;
            $insurance_provider = sanitizeInput($_POST['insurance_provider']) ?: null;
            $insurance_policy_number = sanitizeInput($_POST['insurance_policy_number']) ?: null;
            
            $db->query("
                INSERT INTO patients (
                    patient_id, first_name, last_name, date_of_birth, gender, blood_group, 
                    phone, email, address, emergency_contact_name, emergency_contact_phone,
                    marital_status, occupation, medical_history, allergies, current_medications,
                    insurance_provider, insurance_policy_number
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $patient_id, $first_name, $last_name, $date_of_birth, $gender, $blood_group,
                $phone, $email, $address, $emergency_contact_name, $emergency_contact_phone,
                $marital_status, $occupation, $medical_history, $allergies, $current_medications,
                $insurance_provider, $insurance_policy_number
            ]);
            
            logActivity(getCurrentUserId(), 'Patient Added', "Added new patient: $first_name $last_name ($patient_id)");
            $message = "Patient added successfully!";
            
        } catch (Exception $e) {
            $error = "Error adding patient: " . $e->getMessage();
        }
    }
    
    if ($action === 'update_patient') {
        try {
            $id = $_POST['patient_id'];
            $first_name = sanitizeInput($_POST['first_name']);
            $last_name = sanitizeInput($_POST['last_name']);
            $date_of_birth = $_POST['date_of_birth'];
            $gender = $_POST['gender'];
            $blood_group = $_POST['blood_group'] ?? null;
            $phone = sanitizeInput($_POST['phone']);
            $email = sanitizeInput($_POST['email']) ?: null;
            $address = sanitizeInput($_POST['address']);
            $emergency_contact_name = sanitizeInput($_POST['emergency_contact_name']) ?: null;
            $emergency_contact_phone = sanitizeInput($_POST['emergency_contact_phone']) ?: null;
            $marital_status = $_POST['marital_status'] ?? null;
            $occupation = sanitizeInput($_POST['occupation']) ?: null;
            $medical_history = sanitizeInput($_POST['medical_history']) ?: null;
            $allergies = sanitizeInput($_POST['allergies']) ?: null;
            $current_medications = sanitizeInput($_POST['current_medications']) ?: null;
            $insurance_provider = sanitizeInput($_POST['insurance_provider']) ?: null;
            $insurance_policy_number = sanitizeInput($_POST['insurance_policy_number']) ?: null;
            
            $db->query("
                UPDATE patients SET 
                    first_name = ?, last_name = ?, date_of_birth = ?, gender = ?, blood_group = ?,
                    phone = ?, email = ?, address = ?, emergency_contact_name = ?, emergency_contact_phone = ?,
                    marital_status = ?, occupation = ?, medical_history = ?, allergies = ?, current_medications = ?,
                    insurance_provider = ?, insurance_policy_number = ?, updated_at = NOW()
                WHERE id = ?
            ", [
                $first_name, $last_name, $date_of_birth, $gender, $blood_group,
                $phone, $email, $address, $emergency_contact_name, $emergency_contact_phone,
                $marital_status, $occupation, $medical_history, $allergies, $current_medications,
                $insurance_provider, $insurance_policy_number, $id
            ]);
            
            logActivity(getCurrentUserId(), 'Patient Updated', "Updated patient: $first_name $last_name");
            $message = "Patient updated successfully!";
            
        } catch (Exception $e) {
            $error = "Error updating patient: " . $e->getMessage();
        }
    }
    
    if ($action === 'delete_patient' && isAdmin()) {
        try {
            $id = $_POST['patient_id'];
            $patient = $db->query("SELECT first_name, last_name FROM patients WHERE id = ?", [$id])->fetch();
            
            $db->query("UPDATE patients SET is_active = 0 WHERE id = ?", [$id]);
            
            logActivity(getCurrentUserId(), 'Patient Deleted', "Deleted patient: {$patient['first_name']} {$patient['last_name']}");
            $message = "Patient deleted successfully!";
            
        } catch (Exception $e) {
            $error = "Error deleting patient: " . $e->getMessage();
        }
    }
}

// Get patients with search and pagination
$search = $_GET['search'] ?? '';
$page = (int)($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

$searchCondition = '';
$searchParams = [];

if ($search) {
    $searchCondition = "AND (first_name LIKE ? OR last_name LIKE ? OR patient_id LIKE ? OR phone LIKE ? OR email LIKE ?)";
    $searchTerm = "%$search%";
    $searchParams = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm];
}

$patients = $db->query("
    SELECT * FROM patients 
    WHERE is_active = 1 $searchCondition
    ORDER BY created_at DESC 
    LIMIT $limit OFFSET $offset
", $searchParams)->fetchAll();

$totalPatients = $db->query("
    SELECT COUNT(*) as count FROM patients 
    WHERE is_active = 1 $searchCondition
", $searchParams)->fetch()['count'];

$totalPages = ceil($totalPatients / $limit);

// Get patient for editing
$editPatient = null;
if (isset($_GET['edit'])) {
    $editPatient = $db->query("SELECT * FROM patients WHERE id = ?", [$_GET['edit']])->fetch();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patients Management - Hospital System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .container-fluid { padding: 2rem; }
        .card { border: none; border-radius: 15px; box-shadow: 0 0 20px rgba(0,0,0,0.08); margin-bottom: 2rem; }
        .card-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 15px 15px 0 0; padding: 1.5rem; }
        .btn { border-radius: 8px; padding: 0.5rem 1.5rem; font-weight: 500; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; }
        .btn-success { background: linear-gradient(135deg, #56ab2f 0%, #a8e6cf 100%); border: none; }
        .btn-danger { background: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%); border: none; }
        .btn-warning { background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%); border: none; color: #333; }
        .form-control, .form-select { border-radius: 8px; border: 2px solid #e9ecef; padding: 0.75rem; }
        .form-control:focus, .form-select:focus { border-color: #667eea; box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25); }
        .table { background: white; border-radius: 10px; overflow: hidden; }
        .table th { background: #f8f9fa; border: none; padding: 1rem; font-weight: 600; }
        .table td { border: none; padding: 1rem; vertical-align: middle; }
        .badge { padding: 0.5rem 1rem; border-radius: 20px; }
        .search-box { background: white; border-radius: 10px; padding: 1.5rem; margin-bottom: 2rem; box-shadow: 0 0 10px rgba(0,0,0,0.05); }
        .patient-card { background: white; border-radius: 10px; padding: 1.5rem; margin-bottom: 1rem; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border-left: 4px solid #667eea; }
        .patient-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .patient-name { font-size: 1.2rem; font-weight: 600; color: #2c3e50; }
        .patient-id { color: #7f8c8d; font-size: 0.9rem; }
        .patient-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
        .info-item { display: flex; align-items: center; gap: 0.5rem; }
        .info-icon { width: 20px; color: #667eea; }
        @media (max-width: 768px) {
            .container-fluid { padding: 1rem; }
            .patient-info { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-user-injured text-primary me-2"></i>Patients Management</h2>
                <p class="text-muted">Manage patient records and information</p>
            </div>
            <div>
                <a href="dashboard.php" class="btn btn-outline-secondary me-2">
                    <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
                </a>
                <?php if (isAdmin() || isReceptionist()): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#patientModal">
                    <i class="fas fa-plus me-2"></i>Add New Patient
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Search Box -->
        <div class="search-box">
            <form method="GET" class="row g-3">
                <div class="col-md-10">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search by name, patient ID, phone, or email..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Search</button>
                </div>
            </form>
        </div>

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-users fa-2x text-primary mb-2"></i>
                        <h4><?php echo number_format($totalPatients); ?></h4>
                        <p class="text-muted mb-0">Total Patients</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-user-plus fa-2x text-success mb-2"></i>
                        <h4><?php echo $db->query("SELECT COUNT(*) as count FROM patients WHERE DATE(created_at) = CURDATE()")->fetch()['count']; ?></h4>
                        <p class="text-muted mb-0">New Today</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-calendar-check fa-2x text-warning mb-2"></i>
                        <h4><?php echo $db->query("SELECT COUNT(*) as count FROM appointments WHERE appointment_date = CURDATE()")->fetch()['count']; ?></h4>
                        <p class="text-muted mb-0">Today's Appointments</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-bed fa-2x text-info mb-2"></i>
                        <h4><?php echo $db->query("SELECT COUNT(*) as count FROM bed_assignments WHERE is_active = 1")->fetch()['count']; ?></h4>
                        <p class="text-muted mb-0">Admitted Patients</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Patients List -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Patients List
                    <span class="badge bg-light text-dark ms-2"><?php echo number_format($totalPatients); ?> patients</span>
                </h5>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($patients)): ?>
                    <?php foreach ($patients as $patient): ?>
                    <div class="patient-card">
                        <div class="patient-header">
                            <div>
                                <div class="patient-name"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></div>
                                <div class="patient-id">ID: <?php echo htmlspecialchars($patient['patient_id']); ?></div>
                            </div>
                            <div>
                                <?php if (isAdmin() || isReceptionist()): ?>
                                <a href="?edit=<?php echo $patient['id']; ?>" class="btn btn-sm btn-warning me-1">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                                <a href="patient-details.php?id=<?php echo $patient['id']; ?>" class="btn btn-sm btn-primary me-1">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if (isAdmin()): ?>
                                <button class="btn btn-sm btn-danger" onclick="deletePatient(<?php echo $patient['id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="patient-info">
                            <div class="info-item">
                                <i class="fas fa-birthday-cake info-icon"></i>
                                <span><?php echo formatDate($patient['date_of_birth'], 'd M Y'); ?> (<?php echo getAgeFromDOB($patient['date_of_birth']); ?> years)</span>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-venus-mars info-icon"></i>
                                <span><?php echo htmlspecialchars($patient['gender']); ?></span>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-phone info-icon"></i>
                                <span><?php echo htmlspecialchars($patient['phone']); ?></span>
                            </div>
                            <?php if ($patient['blood_group']): ?>
                            <div class="info-item">
                                <i class="fas fa-tint info-icon"></i>
                                <span><?php echo htmlspecialchars($patient['blood_group']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($patient['email']): ?>
                            <div class="info-item">
                                <i class="fas fa-envelope info-icon"></i>
                                <span><?php echo htmlspecialchars($patient['email']); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="info-item">
                                <i class="fas fa-clock info-icon"></i>
                                <span>Added <?php echo timeAgo($patient['created_at']); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="d-flex justify-content-center mt-4">
                        <?php echo getPagination($page, $totalPages, "?search=" . urlencode($search)); ?>
                    </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-user-injured fa-3x text-muted mb-3"></i>
                    <h5>No patients found</h5>
                    <p class="text-muted">Try adjusting your search criteria or add a new patient.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Patient Modal -->
    <div class="modal fade" id="patientModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus me-2"></i>
                        <?php echo $editPatient ? 'Edit Patient' : 'Add New Patient'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="<?php echo $editPatient ? 'update_patient' : 'add_patient'; ?>">
                        <?php if ($editPatient): ?>
                        <input type="hidden" name="patient_id" value="<?php echo $editPatient['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" name="first_name" class="form-control" required 
                                       value="<?php echo htmlspecialchars($editPatient['first_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" name="last_name" class="form-control" required 
                                       value="<?php echo htmlspecialchars($editPatient['last_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Date of Birth <span class="text-danger">*</span></label>
                                <input type="date" name="date_of_birth" class="form-control" required 
                                       value="<?php echo $editPatient['date_of_birth'] ?? ''; ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Gender <span class="text-danger">*</span></label>
                                <select name="gender" class="form-select" required>
                                    <option value="">Select Gender</option>
                                    <?php foreach (getGenders() as $gender): ?>
                                    <option value="<?php echo $gender; ?>" <?php echo ($editPatient['gender'] ?? '') === $gender ? 'selected' : ''; ?>>
                                        <?php echo $gender; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Blood Group</label>
                                <select name="blood_group" class="form-select">
                                    <option value="">Select Blood Group</option>
                                    <?php foreach (getBloodGroups() as $bloodGroup): ?>
                                    <option value="<?php echo $bloodGroup; ?>" <?php echo ($editPatient['blood_group'] ?? '') === $bloodGroup ? 'selected' : ''; ?>>
                                        <?php echo $bloodGroup; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone <span class="text-danger">*</span></label>
                                <input type="tel" name="phone" class="form-control" required 
                                       value="<?php echo htmlspecialchars($editPatient['phone'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" 
                                       value="<?php echo htmlspecialchars($editPatient['email'] ?? ''); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Address <span class="text-danger">*</span></label>
                                <textarea name="address" class="form-control" rows="2" required><?php echo htmlspecialchars($editPatient['address'] ?? ''); ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Emergency Contact Name</label>
                                <input type="text" name="emergency_contact_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($editPatient['emergency_contact_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Emergency Contact Phone</label>
                                <input type="tel" name="emergency_contact_phone" class="form-control" 
                                       value="<?php echo htmlspecialchars($editPatient['emergency_contact_phone'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Marital Status</label>
                                <select name="marital_status" class="form-select">
                                    <option value="">Select Status</option>
                                    <?php foreach (getMaritalStatus() as $status): ?>
                                    <option value="<?php echo $status; ?>" <?php echo ($editPatient['marital_status'] ?? '') === $status ? 'selected' : ''; ?>>
                                        <?php echo $status; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Occupation</label>
                                <input type="text" name="occupation" class="form-control" 
                                       value="<?php echo htmlspecialchars($editPatient['occupation'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Insurance Provider</label>
                                <input type="text" name="insurance_provider" class="form-control" 
                                       value="<?php echo htmlspecialchars($editPatient['insurance_provider'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Insurance Policy Number</label>
                                <input type="text" name="insurance_policy_number" class="form-control" 
                                       value="<?php echo htmlspecialchars($editPatient['insurance_policy_number'] ?? ''); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Medical History</label>
                                <textarea name="medical_history" class="form-control" rows="2"><?php echo htmlspecialchars($editPatient['medical_history'] ?? ''); ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Allergies</label>
                                <textarea name="allergies" class="form-control" rows="2"><?php echo htmlspecialchars($editPatient['allergies'] ?? ''); ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Current Medications</label>
                                <textarea name="current_medications" class="form-control" rows="2"><?php echo htmlspecialchars($editPatient['current_medications'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>
                            <?php echo $editPatient ? 'Update Patient' : 'Add Patient'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function deletePatient(id) {
            if (confirm('Are you sure you want to delete this patient? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_patient">
                    <input type="hidden" name="patient_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Show modal if editing
        <?php if ($editPatient): ?>
        document.addEventListener('DOMContentLoaded', function() {
            new bootstrap.Modal(document.getElementById('patientModal')).show();
        });
        <?php endif; ?>
    </script>
</body>
</html>