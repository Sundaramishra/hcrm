<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Check if user is logged in and has access
requireLogin();

// Define allowed roles for different actions
$viewRoles = ['admin', 'doctor', 'lab_technician', 'nurse'];
$manageRoles = ['admin', 'lab_technician']; // Can manage lab operations

requireRole($viewRoles);

$db = Database::getInstance();
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'add_test':
                if (!in_array($_SESSION['role'], $manageRoles)) {
                    throw new Exception('You do not have permission to add lab tests.');
                }
                
                $name = trim($_POST['name']);
                $code = trim($_POST['code']);
                $description = trim($_POST['description']);
                $normal_range = trim($_POST['normal_range']);
                $unit = trim($_POST['unit']);
                $price = (float)$_POST['price'];
                $category = trim($_POST['category']);
                $status = $_POST['status'] ?? 'active';
                
                // Check if test code already exists
                $checkStmt = $db->query("SELECT id FROM lab_tests WHERE code = ?", [$code]);
                if ($checkStmt->fetch()) {
                    throw new Exception("Test code already exists");
                }
                
                $stmt = $db->query(
                    "INSERT INTO lab_tests (name, code, description, normal_range, unit, price, category, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$name, $code, $description, $normal_range, $unit, $price, $category, $status]
                );
                
                logActivity($_SESSION['user_id'], 'Add Lab Test', "Added lab test: $name ($code)");
                $message = 'Lab test added successfully!';
                $messageType = 'success';
                break;
                
            case 'update_test':
                if (!in_array($_SESSION['role'], $manageRoles)) {
                    throw new Exception('You do not have permission to update lab tests.');
                }
                
                $id = (int)$_POST['id'];
                $name = trim($_POST['name']);
                $code = trim($_POST['code']);
                $description = trim($_POST['description']);
                $normal_range = trim($_POST['normal_range']);
                $unit = trim($_POST['unit']);
                $price = (float)$_POST['price'];
                $category = trim($_POST['category']);
                $status = $_POST['status'];
                
                // Check if test code already exists for other tests
                $checkStmt = $db->query("SELECT id FROM lab_tests WHERE code = ? AND id != ?", [$code, $id]);
                if ($checkStmt->fetch()) {
                    throw new Exception("Test code already exists");
                }
                
                $stmt = $db->query(
                    "UPDATE lab_tests SET name = ?, code = ?, description = ?, normal_range = ?, unit = ?, price = ?, category = ?, status = ? WHERE id = ?",
                    [$name, $code, $description, $normal_range, $unit, $price, $category, $status, $id]
                );
                
                logActivity($_SESSION['user_id'], 'Update Lab Test', "Updated lab test: $name ($code)");
                $message = 'Lab test updated successfully!';
                $messageType = 'success';
                break;
                
            case 'create_request':
                if (!in_array($_SESSION['role'], ['admin', 'doctor', 'lab_technician', 'nurse'])) {
                    throw new Exception('You do not have permission to create lab requests.');
                }
                
                $patient_id = (int)$_POST['patient_id'];
                $doctor_id = (int)$_POST['doctor_id'];
                $test_ids = $_POST['test_ids'] ?? [];
                $priority = $_POST['priority'] ?? 'normal';
                $notes = trim($_POST['notes']);
                
                if (empty($test_ids)) {
                    throw new Exception('Please select at least one test');
                }
                
                $db->beginTransaction();
                
                // Create lab request
                $request_id = 'LAB' . date('Ymd') . rand(1000, 9999);
                $stmt = $db->query(
                    "INSERT INTO lab_requests (request_id, patient_id, doctor_id, priority, notes, status) VALUES (?, ?, ?, ?, ?, 'pending')",
                    [$request_id, $patient_id, $doctor_id, $priority, $notes]
                );
                
                $lab_request_id = $db->lastInsertId();
                
                // Add tests to request
                foreach ($test_ids as $test_id) {
                    $db->query(
                        "INSERT INTO lab_request_tests (lab_request_id, lab_test_id, status) VALUES (?, ?, 'pending')",
                        [$lab_request_id, $test_id]
                    );
                }
                
                $db->commit();
                
                logActivity($_SESSION['user_id'], 'Create Lab Request', "Created lab request: $request_id");
                $message = 'Lab request created successfully!';
                $messageType = 'success';
                break;
                
            case 'update_request_status':
                if (!in_array($_SESSION['role'], $manageRoles)) {
                    throw new Exception('You do not have permission to update lab requests.');
                }
                
                $id = (int)$_POST['id'];
                $status = $_POST['status'];
                $technician_notes = trim($_POST['technician_notes'] ?? '');
                
                $stmt = $db->query(
                    "UPDATE lab_requests SET status = ?, technician_notes = ? WHERE id = ?",
                    [$status, $technician_notes, $id]
                );
                
                logActivity($_SESSION['user_id'], 'Update Lab Request', "Updated lab request status to: $status");
                $message = 'Lab request updated successfully!';
                $messageType = 'success';
                break;
                
            case 'add_result':
                if (!in_array($_SESSION['role'], $manageRoles)) {
                    throw new Exception('You do not have permission to add lab results.');
                }
                
                $request_test_id = (int)$_POST['request_test_id'];
                $result_value = trim($_POST['result_value']);
                $result_status = $_POST['result_status'];
                $remarks = trim($_POST['remarks']);
                
                $stmt = $db->query(
                    "UPDATE lab_request_tests SET result_value = ?, result_status = ?, remarks = ?, status = 'completed', completed_at = NOW() WHERE id = ?",
                    [$result_value, $result_status, $remarks, $request_test_id]
                );
                
                // Check if all tests in the request are completed
                $requestStmt = $db->query(
                    "SELECT lr.id FROM lab_requests lr 
                     JOIN lab_request_tests lrt ON lr.id = lrt.lab_request_id 
                     WHERE lrt.id = ?", 
                    [$request_test_id]
                );
                $request = $requestStmt->fetch();
                
                if ($request) {
                    $pendingStmt = $db->query(
                        "SELECT COUNT(*) as pending FROM lab_request_tests WHERE lab_request_id = ? AND status != 'completed'",
                        [$request['id']]
                    );
                    $pending = $pendingStmt->fetch()['pending'];
                    
                    if ($pending == 0) {
                        $db->query(
                            "UPDATE lab_requests SET status = 'completed' WHERE id = ?",
                            [$request['id']]
                        );
                    }
                }
                
                logActivity($_SESSION['user_id'], 'Add Lab Result', "Added result for test ID: $request_test_id");
                $message = 'Lab result added successfully!';
                $messageType = 'success';
                break;
        }
    } catch (Exception $e) {
        if ($db->getConnection()->inTransaction()) {
            $db->rollback();
        }
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Get current tab
$activeTab = $_GET['tab'] ?? 'tests';

// Initialize variables
$tests = $requests = $results = [];

if ($activeTab == 'tests') {
    // Get search parameters for tests
    $search = $_GET['search'] ?? '';
    $category_filter = $_GET['category'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    $page = (int)($_GET['page'] ?? 1);
    $limit = 20;
    $offset = ($page - 1) * $limit;

    // Build query for tests
    $whereConditions = ["1=1"];
    $params = [];

    if ($search) {
        $whereConditions[] = "(name LIKE ? OR code LIKE ? OR description LIKE ?)";
        $searchParam = "%$search%";
        $params = array_merge($params, [$searchParam, $searchParam, $searchParam]);
    }

    if ($category_filter) {
        $whereConditions[] = "category = ?";
        $params[] = $category_filter;
    }

    if ($status_filter) {
        $whereConditions[] = "status = ?";
        $params[] = $status_filter;
    }

    $whereClause = implode(' AND ', $whereConditions);

    // Get total count
    $countStmt = $db->query("SELECT COUNT(*) as total FROM lab_tests WHERE $whereClause", $params);
    $totalRecords = $countStmt->fetch()['total'];
    $totalPages = ceil($totalRecords / $limit);

    // Get tests
    $stmt = $db->query(
        "SELECT * FROM lab_tests WHERE $whereClause ORDER BY name ASC LIMIT $limit OFFSET $offset",
        $params
    );
    $tests = $stmt->fetchAll();
} else if ($activeTab == 'requests') {
    // Get lab requests
    $stmt = $db->query(
        "SELECT lr.*, p.name as patient_name, p.patient_id as patient_number, 
                d.name as doctor_name, d.specialization
         FROM lab_requests lr
         JOIN patients p ON lr.patient_id = p.id
         JOIN doctors d ON lr.doctor_id = d.id
         ORDER BY lr.created_at DESC"
    );
    $requests = $stmt->fetchAll();
}

// Get patients and doctors for dropdowns
$patientsStmt = $db->query("SELECT id, name, patient_id FROM patients WHERE status = 'active' ORDER BY name");
$patients = $patientsStmt->fetchAll();

$doctorsStmt = $db->query("SELECT id, name, specialization FROM doctors WHERE status = 'active' ORDER BY name");
$doctors = $doctorsStmt->fetchAll();

// Get all lab tests for request creation
$allTestsStmt = $db->query("SELECT id, name, code, price FROM lab_tests WHERE status = 'active' ORDER BY name");
$allTests = $allTestsStmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laboratory Management - Hospital Management System</title>
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
        .status-pending { background-color: var(--warning-color); color: white; }
        .status-in-progress { background-color: var(--info-color); color: white; }
        .status-completed { background-color: var(--success-color); color: white; }
        .status-cancelled { background-color: var(--danger-color); color: white; }

        .result-normal { color: var(--success-color); font-weight: 600; }
        .result-abnormal { color: var(--danger-color); font-weight: 600; }
        .result-critical { color: var(--danger-color); font-weight: 600; background-color: #ffeaea; }

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

        .nav-tabs .nav-link {
            border: none;
            color: var(--primary-color);
            font-weight: 500;
        }

        .nav-tabs .nav-link.active {
            background-color: var(--primary-color);
            color: white;
        }

        .test-card {
            border-left: 4px solid var(--secondary-color);
            background: white;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
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
                    <a class="nav-link" href="doctors.php">
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
                    <a class="nav-link active" href="laboratory.php">
                        <i class="fas fa-flask me-2"></i> Laboratory
                    </a>
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
                                    <i class="fas fa-flask me-3"></i>Laboratory Management
                                </h2>
                                <p class="mb-0 mt-2">Manage lab tests, requests, and results</p>
                            </div>
                            <div class="col-auto">
                                <?php if (in_array($_SESSION['role'], $manageRoles)): ?>
                                <button class="btn btn-light me-2" data-bs-toggle="modal" data-bs-target="#addTestModal">
                                    <i class="fas fa-plus me-2"></i>Add Test
                                </button>
                                <?php endif; ?>
                                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createRequestModal">
                                    <i class="fas fa-clipboard-list me-2"></i>New Request
                                </button>
                            </div>
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

                    <!-- Navigation Tabs -->
                    <ul class="nav nav-tabs mb-4">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $activeTab == 'tests' ? 'active' : ''; ?>" href="?tab=tests">
                                <i class="fas fa-vial me-2"></i>Lab Tests
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $activeTab == 'requests' ? 'active' : ''; ?>" href="?tab=requests">
                                <i class="fas fa-clipboard-list me-2"></i>Lab Requests
                            </a>
                        </li>
                    </ul>

                    <!-- Tab Content -->
                    <?php if ($activeTab == 'tests'): ?>
                    <!-- Lab Tests Tab -->
                    <div class="search-filters">
                        <form method="GET" class="row g-3">
                            <input type="hidden" name="tab" value="tests">
                            <div class="col-md-4">
                                <label class="form-label">Search Tests</label>
                                <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, Code, Description...">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Category</label>
                                <select class="form-select" name="category">
                                    <option value="">All Categories</option>
                                    <option value="Hematology" <?php echo $category_filter == 'Hematology' ? 'selected' : ''; ?>>Hematology</option>
                                    <option value="Biochemistry" <?php echo $category_filter == 'Biochemistry' ? 'selected' : ''; ?>>Biochemistry</option>
                                    <option value="Microbiology" <?php echo $category_filter == 'Microbiology' ? 'selected' : ''; ?>>Microbiology</option>
                                    <option value="Pathology" <?php echo $category_filter == 'Pathology' ? 'selected' : ''; ?>>Pathology</option>
                                    <option value="Radiology" <?php echo $category_filter == 'Radiology' ? 'selected' : ''; ?>>Radiology</option>
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

                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Test Code</th>
                                        <th>Test Name</th>
                                        <th>Category</th>
                                        <th>Normal Range</th>
                                        <th>Unit</th>
                                        <th>Price</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($tests)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4">
                                            <i class="fas fa-vial fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">No lab tests found</h5>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($tests as $test): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($test['code']); ?></strong></td>
                                        <td>
                                            <div>
                                                <strong><?php echo htmlspecialchars($test['name']); ?></strong>
                                                <?php if ($test['description']): ?>
                                                <small class="d-block text-muted"><?php echo htmlspecialchars($test['description']); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($test['category']); ?></td>
                                        <td><?php echo htmlspecialchars($test['normal_range'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($test['unit'] ?? 'N/A'); ?></td>
                                        <td><?php echo formatCurrency($test['price']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $test['status']; ?>">
                                                <?php echo ucfirst($test['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if (in_array($_SESSION['role'], $manageRoles)): ?>
                                                <button class="btn btn-sm btn-warning" onclick="editTest(<?php echo $test['id']; ?>)" title="Edit">
                                                    <i class="fas fa-edit"></i>
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

                    <?php elseif ($activeTab == 'requests'): ?>
                    <!-- Lab Requests Tab -->
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Request ID</th>
                                        <th>Patient</th>
                                        <th>Doctor</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($requests)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4">
                                            <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">No lab requests found</h5>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($requests as $request): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($request['request_id']); ?></strong></td>
                                        <td>
                                            <div>
                                                <strong><?php echo htmlspecialchars($request['patient_name']); ?></strong>
                                                <small class="d-block text-muted"><?php echo htmlspecialchars($request['patient_number']); ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <strong><?php echo htmlspecialchars($request['doctor_name']); ?></strong>
                                                <small class="d-block text-muted"><?php echo htmlspecialchars($request['specialization']); ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $request['priority'] == 'urgent' ? 'danger' : ($request['priority'] == 'high' ? 'warning' : 'info'); ?>">
                                                <?php echo ucfirst($request['priority']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $request['status']; ?>">
                                                <?php echo ucfirst($request['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo formatDate($request['created_at'], 'd M Y H:i'); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-info" onclick="viewRequest(<?php echo $request['id']; ?>)" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if (in_array($_SESSION['role'], $manageRoles)): ?>
                                                <button class="btn btn-sm btn-warning" onclick="updateRequestStatus(<?php echo $request['id']; ?>)" title="Update Status">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-success" onclick="addResults(<?php echo $request['id']; ?>)" title="Add Results">
                                                    <i class="fas fa-plus"></i>
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
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Test Modal -->
    <?php if (in_array($_SESSION['role'], $manageRoles)): ?>
    <div class="modal fade" id="addTestModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-vial me-2"></i>Add New Lab Test
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_test">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Test Code *</label>
                                <input type="text" class="form-control" name="code" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Test Name *</label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Category</label>
                                <select class="form-select" name="category">
                                    <option value="Hematology">Hematology</option>
                                    <option value="Biochemistry">Biochemistry</option>
                                    <option value="Microbiology">Microbiology</option>
                                    <option value="Pathology">Pathology</option>
                                    <option value="Radiology">Radiology</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Price</label>
                                <input type="number" class="form-control" name="price" step="0.01" min="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Normal Range</label>
                                <input type="text" class="form-control" name="normal_range" placeholder="e.g., 4.5-5.5">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Unit</label>
                                <input type="text" class="form-control" name="unit" placeholder="e.g., mg/dL, %">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="3"></textarea>
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
                            <i class="fas fa-save me-2"></i>Add Test
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Create Request Modal -->
    <div class="modal fade" id="createRequestModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-clipboard-list me-2"></i>Create Lab Request
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create_request">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Patient *</label>
                                <select class="form-select" name="patient_id" required>
                                    <option value="">Select Patient</option>
                                    <?php foreach ($patients as $patient): ?>
                                    <option value="<?php echo $patient['id']; ?>">
                                        <?php echo htmlspecialchars($patient['name'] . ' (' . $patient['patient_id'] . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Doctor *</label>
                                <select class="form-select" name="doctor_id" required>
                                    <option value="">Select Doctor</option>
                                    <?php foreach ($doctors as $doctor): ?>
                                    <option value="<?php echo $doctor['id']; ?>">
                                        <?php echo htmlspecialchars($doctor['name'] . ' (' . $doctor['specialization'] . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Priority</label>
                                <select class="form-select" name="priority">
                                    <option value="normal">Normal</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Select Tests *</label>
                                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 5px;">
                                    <?php foreach ($allTests as $test): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="test_ids[]" value="<?php echo $test['id']; ?>" id="test_<?php echo $test['id']; ?>">
                                        <label class="form-check-label" for="test_<?php echo $test['id']; ?>">
                                            <?php echo htmlspecialchars($test['name'] . ' (' . $test['code'] . ') - ' . formatCurrency($test['price'])); ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" rows="3" placeholder="Additional notes or instructions"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Create Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editTest(id) {
            // Implementation for editing tests
            alert('Edit test functionality - to be implemented');
        }

        function viewRequest(id) {
            // Implementation for viewing request details
            alert('View request functionality - to be implemented');
        }

        function updateRequestStatus(id) {
            // Implementation for updating request status
            alert('Update status functionality - to be implemented');
        }

        function addResults(id) {
            // Implementation for adding results
            alert('Add results functionality - to be implemented');
        }
    </script>
</body>
</html>