<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Check if user is logged in and has access
requireLogin();

// Define allowed roles for different actions
$viewRoles = ['admin', 'doctor', 'nurse', 'receptionist'];
$manageRoles = ['admin', 'doctor']; // Only admin and doctor can add/edit doctors

requireRole($viewRoles);

$db = Database::getInstance();
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!in_array($_SESSION['role'], $manageRoles)) {
        $message = 'You do not have permission to perform this action.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        
        try {
            switch ($action) {
                case 'add_doctor':
                    $employee_id = trim($_POST['employee_id']);
                    $name = trim($_POST['name']);
                    $email = trim($_POST['email']);
                    $phone = trim($_POST['phone']);
                    $specialization = trim($_POST['specialization']);
                    $department_id = (int)$_POST['department_id'];
                    $qualification = trim($_POST['qualification']);
                    $experience = (int)$_POST['experience'];
                    $consultation_fee = (float)$_POST['consultation_fee'];
                    $address = trim($_POST['address']);
                    $date_of_birth = $_POST['date_of_birth'];
                    $gender = $_POST['gender'];
                    $emergency_contact = trim($_POST['emergency_contact']);
                    $license_number = trim($_POST['license_number']);
                    $status = $_POST['status'] ?? 'active';
                    
                    // Check if employee ID already exists
                    $checkStmt = $db->query("SELECT id FROM doctors WHERE employee_id = ?", [$employee_id]);
                    if ($checkStmt->fetch()) {
                        throw new Exception("Employee ID already exists");
                    }
                    
                    // Check if email already exists
                    $checkStmt = $db->query("SELECT id FROM doctors WHERE email = ?", [$email]);
                    if ($checkStmt->fetch()) {
                        throw new Exception("Email already exists");
                    }
                    
                    $stmt = $db->query(
                        "INSERT INTO doctors (employee_id, name, email, phone, specialization, department_id, qualification, experience, consultation_fee, address, date_of_birth, gender, emergency_contact, license_number, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$employee_id, $name, $email, $phone, $specialization, $department_id, $qualification, $experience, $consultation_fee, $address, $date_of_birth, $gender, $emergency_contact, $license_number, $status]
                    );
                    
                    logActivity($_SESSION['user_id'], 'Add Doctor', "Added doctor: $name (ID: $employee_id)");
                    $message = 'Doctor added successfully!';
                    $messageType = 'success';
                    break;
                    
                case 'update_doctor':
                    $id = (int)$_POST['id'];
                    $employee_id = trim($_POST['employee_id']);
                    $name = trim($_POST['name']);
                    $email = trim($_POST['email']);
                    $phone = trim($_POST['phone']);
                    $specialization = trim($_POST['specialization']);
                    $department_id = (int)$_POST['department_id'];
                    $qualification = trim($_POST['qualification']);
                    $experience = (int)$_POST['experience'];
                    $consultation_fee = (float)$_POST['consultation_fee'];
                    $address = trim($_POST['address']);
                    $date_of_birth = $_POST['date_of_birth'];
                    $gender = $_POST['gender'];
                    $emergency_contact = trim($_POST['emergency_contact']);
                    $license_number = trim($_POST['license_number']);
                    $status = $_POST['status'];
                    
                    // Check if employee ID already exists for other doctors
                    $checkStmt = $db->query("SELECT id FROM doctors WHERE employee_id = ? AND id != ?", [$employee_id, $id]);
                    if ($checkStmt->fetch()) {
                        throw new Exception("Employee ID already exists");
                    }
                    
                    // Check if email already exists for other doctors
                    $checkStmt = $db->query("SELECT id FROM doctors WHERE email = ? AND id != ?", [$email, $id]);
                    if ($checkStmt->fetch()) {
                        throw new Exception("Email already exists");
                    }
                    
                    $stmt = $db->query(
                        "UPDATE doctors SET employee_id = ?, name = ?, email = ?, phone = ?, specialization = ?, department_id = ?, qualification = ?, experience = ?, consultation_fee = ?, address = ?, date_of_birth = ?, gender = ?, emergency_contact = ?, license_number = ?, status = ? WHERE id = ?",
                        [$employee_id, $name, $email, $phone, $specialization, $department_id, $qualification, $experience, $consultation_fee, $address, $date_of_birth, $gender, $emergency_contact, $license_number, $status, $id]
                    );
                    
                    logActivity($_SESSION['user_id'], 'Update Doctor', "Updated doctor: $name (ID: $employee_id)");
                    $message = 'Doctor updated successfully!';
                    $messageType = 'success';
                    break;
                    
                case 'delete_doctor':
                    $id = (int)$_POST['id'];
                    
                    // Get doctor info for logging
                    $doctorStmt = $db->query("SELECT name, employee_id FROM doctors WHERE id = ?", [$id]);
                    $doctor = $doctorStmt->fetch();
                    
                    if ($doctor) {
                        // Soft delete
                        $stmt = $db->query("UPDATE doctors SET status = 'inactive', deleted_at = NOW() WHERE id = ?", [$id]);
                        
                        logActivity($_SESSION['user_id'], 'Delete Doctor', "Deleted doctor: {$doctor['name']} (ID: {$doctor['employee_id']})");
                        $message = 'Doctor deleted successfully!';
                        $messageType = 'success';
                    } else {
                        throw new Exception("Doctor not found");
                    }
                    break;
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$department_filter = $_GET['department'] ?? '';
$status_filter = $_GET['status'] ?? '';
$page = (int)($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

// Build query
$whereConditions = ["d.deleted_at IS NULL"];
$params = [];

if ($search) {
    $whereConditions[] = "(d.name LIKE ? OR d.employee_id LIKE ? OR d.email LIKE ? OR d.phone LIKE ? OR d.specialization LIKE ?)";
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
}

if ($department_filter) {
    $whereConditions[] = "d.department_id = ?";
    $params[] = $department_filter;
}

if ($status_filter) {
    $whereConditions[] = "d.status = ?";
    $params[] = $status_filter;
}

$whereClause = implode(' AND ', $whereConditions);

// Get total count
$countStmt = $db->query(
    "SELECT COUNT(*) as total FROM doctors d WHERE $whereClause",
    $params
);
$totalRecords = $countStmt->fetch()['total'];
$totalPages = ceil($totalRecords / $limit);

// Get doctors
$stmt = $db->query(
    "SELECT d.*, dept.name as department_name 
     FROM doctors d 
     LEFT JOIN departments dept ON d.department_id = dept.id 
     WHERE $whereClause 
     ORDER BY d.name ASC 
     LIMIT $limit OFFSET $offset",
    $params
);
$doctors = $stmt->fetchAll();

// Get departments for dropdown
$deptStmt = $db->query("SELECT * FROM departments WHERE status = 'active' ORDER BY name");
$departments = $deptStmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctors Management - Hospital Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #27ae60;
            --danger-color: #e74c3c;
            --warning-color: #f39c12;
            --info-color: #17a2b8;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
        }

        body {
            background-color: #f5f6fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .sidebar {
            background: linear-gradient(180deg, var(--primary-color) 0%, #34495e 100%);
            min-height: 100vh;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }

        .sidebar .nav-link {
            color: #bdc3c7;
            padding: 12px 20px;
            margin: 5px 15px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            color: white;
            background-color: rgba(255,255,255,0.1);
            transform: translateX(5px);
        }

        .main-content {
            background-color: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin: 20px;
            padding: 30px;
        }

        .stats-card {
            background: linear-gradient(135deg, var(--secondary-color), #5dade2);
            border-radius: 15px;
            padding: 25px;
            color: white;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .table-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }

        .table th {
            background-color: var(--primary-color);
            color: white;
            font-weight: 600;
            border: none;
            padding: 15px;
        }

        .table td {
            padding: 12px 15px;
            vertical-align: middle;
            border-color: #eee;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color), #3498db);
            border: none;
            border-radius: 8px;
            padding: 10px 25px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.4);
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 600;
        }

        .status-active { background-color: var(--success-color); color: white; }
        .status-inactive { background-color: var(--danger-color); color: white; }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), #34495e);
            color: white;
            border-radius: 10px 10px 0 0;
        }

        .form-control:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }

        .search-filters {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .page-header {
            background: linear-gradient(135deg, var(--primary-color), #34495e);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
        }

        .action-buttons .btn {
            margin: 0 2px;
            padding: 5px 10px;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 sidebar p-0">
                <div class="p-4">
                    <h4 class="text-white text-center mb-4">
                        <i class="fas fa-hospital"></i> HMS
                    </h4>
                </div>
                <nav class="nav flex-column">
                    <a class="nav-link" href="dashboard.php">
                        <i class="fas fa-dashboard me-2"></i> Dashboard
                    </a>
                    <a class="nav-link" href="patients.php">
                        <i class="fas fa-user-injured me-2"></i> Patients
                    </a>
                    <a class="nav-link active" href="doctors.php">
                        <i class="fas fa-user-md me-2"></i> Doctors
                    </a>
                    <?php if (in_array($_SESSION['role'], ['admin', 'nurse', 'receptionist'])): ?>
                    <a class="nav-link" href="appointments.php">
                        <i class="fas fa-calendar-check me-2"></i> Appointments
                    </a>
                    <?php endif; ?>
                    <?php if (in_array($_SESSION['role'], ['admin', 'pharmacist'])): ?>
                    <a class="nav-link" href="pharmacy.php">
                        <i class="fas fa-pills me-2"></i> Pharmacy
                    </a>
                    <?php endif; ?>
                    <?php if (in_array($_SESSION['role'], ['admin', 'lab_technician'])): ?>
                    <a class="nav-link" href="laboratory.php">
                        <i class="fas fa-flask me-2"></i> Laboratory
                    </a>
                    <?php endif; ?>
                    <?php if (in_array($_SESSION['role'], ['admin', 'nurse'])): ?>
                    <a class="nav-link" href="blood-bank.php">
                        <i class="fas fa-tint me-2"></i> Blood Bank
                    </a>
                    <a class="nav-link" href="organ-donation.php">
                        <i class="fas fa-heart me-2"></i> Organ Donation
                    </a>
                    <?php endif; ?>
                    <?php if (in_array($_SESSION['role'], ['admin', 'accountant'])): ?>
                    <a class="nav-link" href="billing.php">
                        <i class="fas fa-file-invoice-dollar me-2"></i> Billing
                    </a>
                    <?php endif; ?>
                    <?php if (isAdmin()): ?>
                    <a class="nav-link" href="rooms-beds.php">
                        <i class="fas fa-bed me-2"></i> Rooms & Beds
                    </a>
                    <a class="nav-link" href="users.php">
                        <i class="fas fa-users me-2"></i> Users
                    </a>
                    <a class="nav-link" href="reports.php">
                        <i class="fas fa-chart-bar me-2"></i> Reports
                    </a>
                    <?php endif; ?>
                    <a class="nav-link" href="logout.php">
                        <i class="fas fa-sign-out-alt me-2"></i> Logout
                    </a>
                </nav>
            </div>

            <!-- Main Content -->
            <div class="col-md-10">
                <div class="main-content">
                    <!-- Page Header -->
                    <div class="page-header">
                        <div class="row align-items-center">
                            <div class="col">
                                <h2 class="mb-0">
                                    <i class="fas fa-user-md me-3"></i>Doctors Management
                                </h2>
                                <p class="mb-0 mt-2">Manage doctor profiles and information</p>
                            </div>
                            <?php if (in_array($_SESSION['role'], $manageRoles)): ?>
                            <div class="col-auto">
                                <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#addDoctorModal">
                                    <i class="fas fa-plus me-2"></i>Add New Doctor
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType == 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show">
                        <i class="fas fa-<?php echo $messageType == 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Search and Filters -->
                    <div class="search-filters">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Search Doctors</label>
                                <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, ID, Email, Phone...">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Department</label>
                                <select class="form-select" name="department">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>" <?php echo $department_filter == $dept['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="">All Status</option>
                                    <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search"></i> Search
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Doctors Table -->
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Employee ID</th>
                                        <th>Name</th>
                                        <th>Specialization</th>
                                        <th>Department</th>
                                        <th>Phone</th>
                                        <th>Email</th>
                                        <th>Consultation Fee</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($doctors)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4">
                                            <i class="fas fa-user-md fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">No doctors found</h5>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($doctors as $doctor): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($doctor['employee_id']); ?></strong></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                                    <i class="fas fa-user-md"></i>
                                                </div>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($doctor['name']); ?></strong>
                                                    <small class="d-block text-muted"><?php echo htmlspecialchars($doctor['qualification']); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($doctor['specialization']); ?></td>
                                        <td><?php echo htmlspecialchars($doctor['department_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($doctor['phone']); ?></td>
                                        <td><?php echo htmlspecialchars($doctor['email']); ?></td>
                                        <td><?php echo formatCurrency($doctor['consultation_fee']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $doctor['status']; ?>">
                                                <?php echo ucfirst($doctor['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-info" onclick="viewDoctor(<?php echo $doctor['id']; ?>)" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if (in_array($_SESSION['role'], $manageRoles)): ?>
                                                <button class="btn btn-sm btn-warning" onclick="editDoctor(<?php echo $doctor['id']; ?>)" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger" onclick="deleteDoctor(<?php echo $doctor['id']; ?>, '<?php echo htmlspecialchars($doctor['name']); ?>')" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <nav class="mt-4">
                        <ul class="pagination justify-content-center">
                            <?php
                            $queryParams = $_GET;
                            for ($i = 1; $i <= $totalPages; $i++):
                                $queryParams['page'] = $i;
                                $url = '?' . http_build_query($queryParams);
                            ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo $url; ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Doctor Modal -->
    <?php if (in_array($_SESSION['role'], $manageRoles)): ?>
    <div class="modal fade" id="addDoctorModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-md me-2"></i>Add New Doctor
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_doctor">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Employee ID *</label>
                                <input type="text" class="form-control" name="employee_id" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name *</label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email *</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone *</label>
                                <input type="text" class="form-control" name="phone" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Specialization *</label>
                                <input type="text" class="form-control" name="specialization" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Department *</label>
                                <select class="form-select" name="department_id" required>
                                    <option value="">Select Department</option>
                                    <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Qualification *</label>
                                <input type="text" class="form-control" name="qualification" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Experience (Years)</label>
                                <input type="number" class="form-control" name="experience" min="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Consultation Fee</label>
                                <input type="number" class="form-control" name="consultation_fee" step="0.01" min="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" class="form-control" name="date_of_birth">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Gender</label>
                                <select class="form-select" name="gender">
                                    <option value="">Select Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">License Number</label>
                                <input type="text" class="form-control" name="license_number">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Address</label>
                                <textarea class="form-control" name="address" rows="3"></textarea>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Emergency Contact</label>
                                <input type="text" class="form-control" name="emergency_contact">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Add Doctor
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Doctor Modal -->
    <div class="modal fade" id="editDoctorModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Doctor
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editDoctorForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_doctor">
                        <input type="hidden" name="id" id="edit_doctor_id">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Employee ID *</label>
                                <input type="text" class="form-control" name="employee_id" id="edit_employee_id" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name *</label>
                                <input type="text" class="form-control" name="name" id="edit_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email *</label>
                                <input type="email" class="form-control" name="email" id="edit_email" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone *</label>
                                <input type="text" class="form-control" name="phone" id="edit_phone" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Specialization *</label>
                                <input type="text" class="form-control" name="specialization" id="edit_specialization" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Department *</label>
                                <select class="form-select" name="department_id" id="edit_department_id" required>
                                    <option value="">Select Department</option>
                                    <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Qualification *</label>
                                <input type="text" class="form-control" name="qualification" id="edit_qualification" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Experience (Years)</label>
                                <input type="number" class="form-control" name="experience" id="edit_experience" min="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Consultation Fee</label>
                                <input type="number" class="form-control" name="consultation_fee" id="edit_consultation_fee" step="0.01" min="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" class="form-control" name="date_of_birth" id="edit_date_of_birth">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Gender</label>
                                <select class="form-select" name="gender" id="edit_gender">
                                    <option value="">Select Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">License Number</label>
                                <input type="text" class="form-control" name="license_number" id="edit_license_number">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Address</label>
                                <textarea class="form-control" name="address" id="edit_address" rows="3"></textarea>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Emergency Contact</label>
                                <input type="text" class="form-control" name="emergency_contact" id="edit_emergency_contact">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="edit_status">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Doctor
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- View Doctor Modal -->
    <div class="modal fade" id="viewDoctorModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-eye me-2"></i>Doctor Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewDoctorContent">
                    <!-- Content will be loaded via JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Edit Doctor
        function editDoctor(id) {
            fetch(`get-doctor-details.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const doctor = data.doctor;
                        document.getElementById('edit_doctor_id').value = doctor.id;
                        document.getElementById('edit_employee_id').value = doctor.employee_id;
                        document.getElementById('edit_name').value = doctor.name;
                        document.getElementById('edit_email').value = doctor.email;
                        document.getElementById('edit_phone').value = doctor.phone;
                        document.getElementById('edit_specialization').value = doctor.specialization;
                        document.getElementById('edit_department_id').value = doctor.department_id;
                        document.getElementById('edit_qualification').value = doctor.qualification;
                        document.getElementById('edit_experience').value = doctor.experience;
                        document.getElementById('edit_consultation_fee').value = doctor.consultation_fee;
                        document.getElementById('edit_date_of_birth').value = doctor.date_of_birth;
                        document.getElementById('edit_gender').value = doctor.gender;
                        document.getElementById('edit_license_number').value = doctor.license_number;
                        document.getElementById('edit_address').value = doctor.address;
                        document.getElementById('edit_emergency_contact').value = doctor.emergency_contact;
                        document.getElementById('edit_status').value = doctor.status;
                        
                        new bootstrap.Modal(document.getElementById('editDoctorModal')).show();
                    } else {
                        alert('Error loading doctor details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading doctor details');
                });
        }

        // View Doctor
        function viewDoctor(id) {
            fetch(`get-doctor-details.php?id=${id}&view=true`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('viewDoctorContent').innerHTML = data.html;
                        new bootstrap.Modal(document.getElementById('viewDoctorModal')).show();
                    } else {
                        alert('Error loading doctor details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading doctor details');
                });
        }

        // Delete Doctor
        function deleteDoctor(id, name) {
            if (confirm(`Are you sure you want to delete Dr. ${name}?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_doctor">
                    <input type="hidden" name="id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>