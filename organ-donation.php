<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();
requireRole(['admin', 'doctor']);

$db = Database::getInstance();
$message = '';
$error = '';

// Handle form submissions
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_donor') {
        try {
            $donor_id = 'ORG' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $first_name = sanitizeInput($_POST['first_name']);
            $last_name = sanitizeInput($_POST['last_name']);
            $date_of_birth = $_POST['date_of_birth'];
            $gender = $_POST['gender'];
            $blood_group = $_POST['blood_group'];
            $phone = sanitizeInput($_POST['phone']);
            $email = sanitizeInput($_POST['email']) ?: null;
            $address = sanitizeInput($_POST['address']);
            $emergency_contact_name = sanitizeInput($_POST['emergency_contact_name']);
            $emergency_contact_phone = sanitizeInput($_POST['emergency_contact_phone']);
            $medical_history = sanitizeInput($_POST['medical_history']) ?: null;
            $organs_to_donate = json_encode($_POST['organs_to_donate'] ?? []);
            $consent_date = $_POST['consent_date'];
            
            // Handle file upload for consent document
            $consent_document = null;
            if (!empty($_FILES['consent_document']['name'])) {
                $consent_document = uploadFile($_FILES['consent_document'], 'uploads/consent/');
            }
            
            $db->query("
                INSERT INTO organ_donors (
                    donor_id, first_name, last_name, date_of_birth, gender, blood_group,
                    phone, email, address, emergency_contact_name, emergency_contact_phone,
                    medical_history, organs_to_donate, consent_date, consent_document
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $donor_id, $first_name, $last_name, $date_of_birth, $gender, $blood_group,
                $phone, $email, $address, $emergency_contact_name, $emergency_contact_phone,
                $medical_history, $organs_to_donate, $consent_date, $consent_document
            ]);
            
            logActivity(getCurrentUserId(), 'Organ Donor Added', "Added organ donor: $first_name $last_name ($donor_id)");
            $message = "Organ donor added successfully!";
            
        } catch (Exception $e) {
            $error = "Error adding donor: " . $e->getMessage();
        }
    }
    
    if ($action === 'add_recipient') {
        try {
            $patient_id = $_POST['patient_id'];
            $organ_needed = $_POST['organ_needed'];
            $blood_group = $_POST['blood_group'];
            $priority = $_POST['priority'];
            $registration_date = $_POST['registration_date'];
            $medical_urgency = sanitizeInput($_POST['medical_urgency']);
            $compatibility_notes = sanitizeInput($_POST['compatibility_notes']) ?: null;
            $doctor_id = $_POST['doctor_id'];
            
            $db->query("
                INSERT INTO organ_recipients (
                    patient_id, organ_needed, blood_group, priority, registration_date,
                    medical_urgency, compatibility_notes, doctor_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $patient_id, $organ_needed, $blood_group, $priority, $registration_date,
                $medical_urgency, $compatibility_notes, $doctor_id
            ]);
            
            logActivity(getCurrentUserId(), 'Organ Recipient Added', "Added organ recipient for: $organ_needed");
            $message = "Organ recipient added successfully!";
            
        } catch (Exception $e) {
            $error = "Error adding recipient: " . $e->getMessage();
        }
    }
    
    if ($action === 'update_recipient_status') {
        try {
            $recipient_id = $_POST['recipient_id'];
            $status = $_POST['status'];
            
            $db->query("UPDATE organ_recipients SET status = ? WHERE id = ?", [$status, $recipient_id]);
            
            logActivity(getCurrentUserId(), 'Recipient Status Updated', "Updated organ recipient status to: $status");
            $message = "Recipient status updated successfully!";
            
        } catch (Exception $e) {
            $error = "Error updating status: " . $e->getMessage();
        }
    }
}

// Get data based on tab
$tab = $_GET['tab'] ?? 'donors';
$search = $_GET['search'] ?? '';
$organ_filter = $_GET['organ'] ?? '';
$blood_filter = $_GET['blood_group'] ?? '';
$page = (int)($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

$searchCondition = '';
$searchParams = [];

if ($search) {
    if ($tab === 'donors') {
        $searchCondition .= " AND (first_name LIKE ? OR last_name LIKE ? OR donor_id LIKE ? OR phone LIKE ?)";
    } else {
        $searchCondition .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.patient_id LIKE ?)";
    }
    $searchTerm = "%$search%";
    $searchParams = [$searchTerm, $searchTerm, $searchTerm];
    if ($tab === 'donors') $searchParams[] = $searchTerm;
}

if ($blood_filter) {
    $searchCondition .= " AND blood_group = ?";
    $searchParams[] = $blood_filter;
}

if ($organ_filter && $tab === 'recipients') {
    $searchCondition .= " AND organ_needed = ?";
    $searchParams[] = $organ_filter;
}

if ($tab === 'donors') {
    $donors = $db->query("
        SELECT * FROM organ_donors 
        WHERE is_active = 1 $searchCondition
        ORDER BY created_at DESC 
        LIMIT $limit OFFSET $offset
    ", $searchParams)->fetchAll();
    
    $totalRecords = $db->query("
        SELECT COUNT(*) as count FROM organ_donors 
        WHERE is_active = 1 $searchCondition
    ", $searchParams)->fetch()['count'];
} else {
    $recipients = $db->query("
        SELECT or.*, 
               CONCAT(p.first_name, ' ', p.last_name) as patient_name,
               p.patient_id,
               CONCAT(u.first_name, ' ', u.last_name) as doctor_name
        FROM organ_recipients or
        JOIN patients p ON or.patient_id = p.id
        JOIN doctors d ON or.doctor_id = d.id
        JOIN users u ON d.user_id = u.id
        WHERE 1=1 $searchCondition
        ORDER BY or.created_at DESC 
        LIMIT $limit OFFSET $offset
    ", $searchParams)->fetchAll();
    
    $totalRecords = $db->query("
        SELECT COUNT(*) as count FROM organ_recipients or
        JOIN patients p ON or.patient_id = p.id
        WHERE 1=1 $searchCondition
    ", $searchParams)->fetch()['count'];
}

$totalPages = ceil($totalRecords / $limit);

// Get available organs
$availableOrgans = ['Heart', 'Liver', 'Kidney', 'Lung', 'Pancreas', 'Cornea', 'Skin', 'Bone', 'Heart Valve', 'Intestine'];

// Get patients for dropdown
$patients = $db->query("SELECT id, patient_id, first_name, last_name FROM patients WHERE is_active = 1 ORDER BY first_name")->fetchAll();

// Get doctors for dropdown
$doctors = $db->query("
    SELECT d.id, u.first_name, u.last_name 
    FROM doctors d 
    JOIN users u ON d.user_id = u.id 
    WHERE d.is_available = 1 
    ORDER BY u.first_name
")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organ Donation Management - Hospital System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .container-fluid { padding: 2rem; }
        .card { border: none; border-radius: 15px; box-shadow: 0 0 20px rgba(0,0,0,0.08); margin-bottom: 2rem; }
        .card-header { background: linear-gradient(135deg, #e67e22 0%, #d35400 100%); color: white; border-radius: 15px 15px 0 0; padding: 1.5rem; }
        .btn { border-radius: 8px; padding: 0.5rem 1.5rem; font-weight: 500; }
        .btn-primary { background: linear-gradient(135deg, #e67e22 0%, #d35400 100%); border: none; }
        .btn-success { background: linear-gradient(135deg, #56ab2f 0%, #a8e6cf 100%); border: none; }
        .btn-danger { background: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%); border: none; }
        .btn-warning { background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%); border: none; color: #333; }
        .form-control, .form-select { border-radius: 8px; border: 2px solid #e9ecef; padding: 0.75rem; }
        .form-control:focus, .form-select:focus { border-color: #e67e22; box-shadow: 0 0 0 0.2rem rgba(230, 126, 34, 0.25); }
        .search-box { background: white; border-radius: 10px; padding: 1.5rem; margin-bottom: 2rem; box-shadow: 0 0 10px rgba(0,0,0,0.05); }
        .donor-card, .recipient-card { background: white; border-radius: 10px; padding: 1.5rem; margin-bottom: 1rem; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .organ-list { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem; }
        .organ-badge { background: #e67e22; color: white; padding: 0.2rem 0.6rem; border-radius: 12px; font-size: 0.8rem; }
        .status-waiting { color: #f39c12; }
        .status-matched { color: #3498db; }
        .status-transplanted { color: #27ae60; }
        .status-cancelled { color: #e74c3c; }
        .priority-critical { color: #e74c3c; font-weight: 600; }
        .priority-high { color: #f39c12; }
        .priority-medium { color: #3498db; }
        .priority-low { color: #95a5a6; }
        .nav-tabs .nav-link.active { background: #e67e22; color: white; border-color: #e67e22; }
        @media (max-width: 768px) {
            .container-fluid { padding: 1rem; }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-heart text-warning me-2"></i>Organ Donation Management</h2>
                <p class="text-muted">Manage organ donors and recipients</p>
            </div>
            <div>
                <a href="dashboard.php" class="btn btn-outline-secondary me-2">
                    <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
                </a>
                <div class="dropdown d-inline">
                    <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-plus me-2"></i>Add New
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#donorModal">
                            <i class="fas fa-user me-2"></i>Add Organ Donor</a></li>
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#recipientModal">
                            <i class="fas fa-user-injured me-2"></i>Add Recipient</a></li>
                    </ul>
                </div>
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

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-users fa-2x text-warning mb-2"></i>
                        <h4><?php echo $db->query("SELECT COUNT(*) as count FROM organ_donors WHERE is_active = 1")->fetch()['count']; ?></h4>
                        <p class="text-muted mb-0">Total Donors</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-user-injured fa-2x text-primary mb-2"></i>
                        <h4><?php echo $db->query("SELECT COUNT(*) as count FROM organ_recipients WHERE status = 'Waiting'")->fetch()['count']; ?></h4>
                        <p class="text-muted mb-0">Waiting Recipients</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-handshake fa-2x text-info mb-2"></i>
                        <h4><?php echo $db->query("SELECT COUNT(*) as count FROM organ_recipients WHERE status = 'Matched'")->fetch()['count']; ?></h4>
                        <p class="text-muted mb-0">Matched Recipients</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                        <h4><?php echo $db->query("SELECT COUNT(*) as count FROM organ_recipients WHERE status = 'Transplanted'")->fetch()['count']; ?></h4>
                        <p class="text-muted mb-0">Successful Transplants</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link <?php echo $tab === 'donors' ? 'active' : ''; ?>" href="?tab=donors">
                    <i class="fas fa-user me-2"></i>Organ Donors
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $tab === 'recipients' ? 'active' : ''; ?>" href="?tab=recipients">
                    <i class="fas fa-user-injured me-2"></i>Recipients
                </a>
            </li>
        </ul>

        <!-- Search and Filter -->
        <div class="search-box">
            <form method="GET" class="row g-3">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <select name="blood_group" class="form-select">
                        <option value="">All Blood Groups</option>
                        <?php foreach (getBloodGroups() as $bg): ?>
                        <option value="<?php echo $bg; ?>" <?php echo $blood_filter === $bg ? 'selected' : ''; ?>>
                            <?php echo $bg; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($tab === 'recipients'): ?>
                <div class="col-md-3">
                    <select name="organ" class="form-select">
                        <option value="">All Organs</option>
                        <?php foreach ($availableOrgans as $organ): ?>
                        <option value="<?php echo $organ; ?>" <?php echo $organ_filter === $organ ? 'selected' : ''; ?>>
                            <?php echo $organ; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
                <?php else: ?>
                <div class="col-md-6">
                    <button type="submit" class="btn btn-primary">Filter</button>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Content based on tab -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <?php if ($tab === 'donors'): ?>
                        <i class="fas fa-user me-2"></i>Organ Donors
                        <span class="badge bg-light text-dark ms-2"><?php echo number_format($totalRecords ?? 0); ?> donors</span>
                    <?php else: ?>
                        <i class="fas fa-user-injured me-2"></i>Organ Recipients
                        <span class="badge bg-light text-dark ms-2"><?php echo number_format($totalRecords ?? 0); ?> recipients</span>
                    <?php endif; ?>
                </h5>
            </div>
            <div class="card-body p-0">
                <?php if ($tab === 'donors' && !empty($donors)): ?>
                    <?php foreach ($donors as $donor): ?>
                    <div class="donor-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5><?php echo htmlspecialchars($donor['first_name'] . ' ' . $donor['last_name']); ?></h5>
                                <p class="text-muted mb-1">ID: <?php echo htmlspecialchars($donor['donor_id']); ?></p>
                                <div class="row">
                                    <div class="col-md-6">
                                        <small><i class="fas fa-tint me-1"></i>Blood Group: <?php echo htmlspecialchars($donor['blood_group']); ?></small><br>
                                        <small><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor['phone']); ?></small><br>
                                        <small><i class="fas fa-birthday-cake me-1"></i>Age: <?php echo getAgeFromDOB($donor['date_of_birth']); ?> years</small>
                                    </div>
                                    <div class="col-md-6">
                                        <small><i class="fas fa-venus-mars me-1"></i><?php echo htmlspecialchars($donor['gender']); ?></small><br>
                                        <small><i class="fas fa-calendar me-1"></i>Consent: <?php echo formatDate($donor['consent_date'], 'd M Y'); ?></small><br>
                                        <small><i class="fas fa-user me-1"></i>Emergency: <?php echo htmlspecialchars($donor['emergency_contact_name']); ?></small>
                                    </div>
                                </div>
                                <?php 
                                $organs = json_decode($donor['organs_to_donate'], true);
                                if (!empty($organs)): 
                                ?>
                                <div class="organ-list">
                                    <?php foreach ($organs as $organ): ?>
                                    <span class="organ-badge"><?php echo htmlspecialchars($organ); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="text-end">
                                <?php if ($donor['consent_document']): ?>
                                <a href="uploads/consent/<?php echo $donor['consent_document']; ?>" target="_blank" class="btn btn-sm btn-outline-info mb-2">
                                    <i class="fas fa-file-pdf"></i> Consent
                                </a>
                                <br>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                <?php elseif ($tab === 'recipients' && !empty($recipients)): ?>
                    <?php foreach ($recipients as $recipient): ?>
                    <div class="recipient-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5><?php echo htmlspecialchars($recipient['patient_name']); ?></h5>
                                <p class="text-muted mb-1">Patient ID: <?php echo htmlspecialchars($recipient['patient_id']); ?></p>
                                <div class="row">
                                    <div class="col-md-6">
                                        <small><i class="fas fa-heart me-1"></i>Organ: <strong><?php echo htmlspecialchars($recipient['organ_needed']); ?></strong></small><br>
                                        <small><i class="fas fa-tint me-1"></i>Blood Group: <?php echo htmlspecialchars($recipient['blood_group']); ?></small><br>
                                        <small><i class="fas fa-calendar me-1"></i>Registered: <?php echo formatDate($recipient['registration_date'], 'd M Y'); ?></small>
                                    </div>
                                    <div class="col-md-6">
                                        <small><i class="fas fa-exclamation me-1"></i>Priority: <span class="priority-<?php echo strtolower($recipient['priority']); ?>"><?php echo $recipient['priority']; ?></span></small><br>
                                        <small><i class="fas fa-user-md me-1"></i>Doctor: <?php echo htmlspecialchars($recipient['doctor_name']); ?></small><br>
                                        <small><i class="fas fa-clipboard me-1"></i>Urgency: <?php echo htmlspecialchars($recipient['medical_urgency']); ?></small>
                                    </div>
                                </div>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-<?php 
                                    echo $recipient['status'] === 'Waiting' ? 'warning' : 
                                        ($recipient['status'] === 'Matched' ? 'info' : 
                                        ($recipient['status'] === 'Transplanted' ? 'success' : 'secondary')); 
                                ?>">
                                    <?php echo $recipient['status']; ?>
                                </span>
                                <br>
                                <?php if ($recipient['status'] === 'Waiting' && isAdmin()): ?>
                                <div class="mt-2">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="update_recipient_status">
                                        <input type="hidden" name="recipient_id" value="<?php echo $recipient['id']; ?>">
                                        <input type="hidden" name="status" value="Matched">
                                        <button type="submit" class="btn btn-sm btn-info">Mark Matched</button>
                                    </form>
                                </div>
                                <?php elseif ($recipient['status'] === 'Matched' && isAdmin()): ?>
                                <div class="mt-2">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="update_recipient_status">
                                        <input type="hidden" name="recipient_id" value="<?php echo $recipient['id']; ?>">
                                        <input type="hidden" name="status" value="Transplanted">
                                        <button type="submit" class="btn btn-sm btn-success">Mark Transplanted</button>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-heart fa-3x text-muted mb-3"></i>
                    <h5>No records found</h5>
                    <p class="text-muted">Try adjusting your search criteria or add new records.</p>
                </div>
                <?php endif; ?>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="d-flex justify-content-center mt-4">
                    <?php echo getPagination($page, $totalPages, "?tab=$tab&search=" . urlencode($search) . "&blood_group=" . urlencode($blood_filter) . "&organ=" . urlencode($organ_filter)); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modals -->
    
    <!-- Add Donor Modal -->
    <div class="modal fade" id="donorModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user me-2"></i>Add Organ Donor
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_donor">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" name="first_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                                <input type="text" name="last_name" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Date of Birth <span class="text-danger">*</span></label>
                                <input type="date" name="date_of_birth" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Gender <span class="text-danger">*</span></label>
                                <select name="gender" class="form-select" required>
                                    <option value="">Select Gender</option>
                                    <?php foreach (getGenders() as $gender): ?>
                                    <option value="<?php echo $gender; ?>"><?php echo $gender; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Blood Group <span class="text-danger">*</span></label>
                                <select name="blood_group" class="form-select" required>
                                    <option value="">Select Blood Group</option>
                                    <?php foreach (getBloodGroups() as $bg): ?>
                                    <option value="<?php echo $bg; ?>"><?php echo $bg; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone <span class="text-danger">*</span></label>
                                <input type="tel" name="phone" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Address <span class="text-danger">*</span></label>
                                <textarea name="address" class="form-control" rows="2" required></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Emergency Contact Name <span class="text-danger">*</span></label>
                                <input type="text" name="emergency_contact_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Emergency Contact Phone <span class="text-danger">*</span></label>
                                <input type="tel" name="emergency_contact_phone" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Consent Date <span class="text-danger">*</span></label>
                                <input type="date" name="consent_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Consent Document</label>
                                <input type="file" name="consent_document" class="form-control" accept=".pdf,.doc,.docx">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Organs to Donate <span class="text-danger">*</span></label>
                                <div class="row">
                                    <?php foreach ($availableOrgans as $organ): ?>
                                    <div class="col-md-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="organs_to_donate[]" value="<?php echo $organ; ?>" id="organ_<?php echo str_replace(' ', '_', $organ); ?>">
                                            <label class="form-check-label" for="organ_<?php echo str_replace(' ', '_', $organ); ?>">
                                                <?php echo $organ; ?>
                                            </label>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Medical History</label>
                                <textarea name="medical_history" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Add Donor
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Recipient Modal -->
    <div class="modal fade" id="recipientModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-injured me-2"></i>Add Organ Recipient
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_recipient">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Patient <span class="text-danger">*</span></label>
                                <select name="patient_id" class="form-select" required>
                                    <option value="">Select Patient</option>
                                    <?php foreach ($patients as $patient): ?>
                                    <option value="<?php echo $patient['id']; ?>">
                                        <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name'] . ' (' . $patient['patient_id'] . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Doctor <span class="text-danger">*</span></label>
                                <select name="doctor_id" class="form-select" required>
                                    <option value="">Select Doctor</option>
                                    <?php foreach ($doctors as $doctor): ?>
                                    <option value="<?php echo $doctor['id']; ?>">
                                        <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Organ Needed <span class="text-danger">*</span></label>
                                <select name="organ_needed" class="form-select" required>
                                    <option value="">Select Organ</option>
                                    <?php foreach ($availableOrgans as $organ): ?>
                                    <option value="<?php echo $organ; ?>"><?php echo $organ; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Blood Group <span class="text-danger">*</span></label>
                                <select name="blood_group" class="form-select" required>
                                    <option value="">Select Blood Group</option>
                                    <?php foreach (getBloodGroups() as $bg): ?>
                                    <option value="<?php echo $bg; ?>"><?php echo $bg; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Priority <span class="text-danger">*</span></label>
                                <select name="priority" class="form-select" required>
                                    <option value="">Select Priority</option>
                                    <option value="Low">Low</option>
                                    <option value="Medium">Medium</option>
                                    <option value="High">High</option>
                                    <option value="Critical">Critical</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Registration Date <span class="text-danger">*</span></label>
                                <input type="date" name="registration_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Medical Urgency <span class="text-danger">*</span></label>
                                <textarea name="medical_urgency" class="form-control" rows="3" required></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Compatibility Notes</label>
                                <textarea name="compatibility_notes" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Add Recipient
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>