<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();
requireRole(['admin', 'nurse', 'doctor']);

$db = Database::getInstance();
$message = '';
$error = '';

// Handle form submissions
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_donor') {
        try {
            $donor_id = 'DON' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $first_name = sanitizeInput($_POST['first_name']);
            $last_name = sanitizeInput($_POST['last_name']);
            $date_of_birth = $_POST['date_of_birth'];
            $gender = $_POST['gender'];
            $blood_group = $_POST['blood_group'];
            $phone = sanitizeInput($_POST['phone']);
            $email = sanitizeInput($_POST['email']) ?: null;
            $address = sanitizeInput($_POST['address']);
            $weight = (float)$_POST['weight'];
            $medical_history = sanitizeInput($_POST['medical_history']) ?: null;
            
            $db->query("
                INSERT INTO blood_donors (
                    donor_id, first_name, last_name, date_of_birth, gender, blood_group,
                    phone, email, address, weight, medical_history
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $donor_id, $first_name, $last_name, $date_of_birth, $gender, $blood_group,
                $phone, $email, $address, $weight, $medical_history
            ]);
            
            logActivity(getCurrentUserId(), 'Blood Donor Added', "Added blood donor: $first_name $last_name ($donor_id)");
            $message = "Blood donor added successfully!";
            
        } catch (Exception $e) {
            $error = "Error adding donor: " . $e->getMessage();
        }
    }
    
    if ($action === 'add_inventory') {
        try {
            $blood_group = $_POST['blood_group'];
            $component_type = $_POST['component_type'];
            $units_available = (int)$_POST['units_available'];
            $expiry_date = $_POST['expiry_date'];
            $donor_id = $_POST['donor_id'] ?: null;
            $collection_date = $_POST['collection_date'];
            $storage_location = sanitizeInput($_POST['storage_location']);
            
            $db->query("
                INSERT INTO blood_inventory (
                    blood_group, component_type, units_available, expiry_date,
                    donor_id, collection_date, storage_location
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ", [
                $blood_group, $component_type, $units_available, $expiry_date,
                $donor_id, $collection_date, $storage_location
            ]);
            
            // Update donor's last donation date if donor is specified
            if ($donor_id) {
                $db->query("UPDATE blood_donors SET last_donation_date = ? WHERE id = ?", [$collection_date, $donor_id]);
            }
            
            logActivity(getCurrentUserId(), 'Blood Inventory Added', "Added blood inventory: $blood_group $component_type - $units_available units");
            $message = "Blood inventory added successfully!";
            
        } catch (Exception $e) {
            $error = "Error adding inventory: " . $e->getMessage();
        }
    }
    
    if ($action === 'create_request') {
        try {
            $patient_id = $_POST['patient_id'];
            $doctor_id = $_POST['doctor_id'];
            $blood_group = $_POST['blood_group'];
            $component_type = $_POST['component_type'];
            $units_requested = (int)$_POST['units_requested'];
            $priority = $_POST['priority'];
            $request_date = $_POST['request_date'];
            $required_date = $_POST['required_date'];
            $reason = sanitizeInput($_POST['reason']);
            
            $db->query("
                INSERT INTO blood_requests (
                    patient_id, doctor_id, blood_group, component_type, units_requested,
                    priority, request_date, required_date, reason
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $patient_id, $doctor_id, $blood_group, $component_type, $units_requested,
                $priority, $request_date, $required_date, $reason
            ]);
            
            logActivity(getCurrentUserId(), 'Blood Request Created', "Created blood request: $blood_group $component_type - $units_requested units");
            $message = "Blood request created successfully!";
            
        } catch (Exception $e) {
            $error = "Error creating request: " . $e->getMessage();
        }
    }
    
    if ($action === 'update_request_status') {
        try {
            $request_id = $_POST['request_id'];
            $status = $_POST['status'];
            $user_id = getCurrentUserId();
            
            if ($status === 'Approved') {
                $db->query("UPDATE blood_requests SET status = ?, approved_by = ? WHERE id = ?", [$status, $user_id, $request_id]);
            } elseif ($status === 'Fulfilled') {
                $db->query("UPDATE blood_requests SET status = ?, fulfilled_by = ? WHERE id = ?", [$status, $user_id, $request_id]);
            } else {
                $db->query("UPDATE blood_requests SET status = ? WHERE id = ?", [$status, $request_id]);
            }
            
            logActivity(getCurrentUserId(), 'Blood Request Updated', "Updated blood request status to: $status");
            $message = "Request status updated successfully!";
            
        } catch (Exception $e) {
            $error = "Error updating request: " . $e->getMessage();
        }
    }
}

// Get donors with search and pagination
$search = $_GET['search'] ?? '';
$blood_filter = $_GET['blood_group'] ?? '';
$tab = $_GET['tab'] ?? 'donors';
$page = (int)($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

$searchCondition = '';
$searchParams = [];

if ($search) {
    $searchCondition .= " AND (first_name LIKE ? OR last_name LIKE ? OR donor_id LIKE ? OR phone LIKE ?)";
    $searchTerm = "%$search%";
    $searchParams = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
}

if ($blood_filter) {
    $searchCondition .= " AND blood_group = ?";
    $searchParams[] = $blood_filter;
}

if ($tab === 'donors') {
    $donors = $db->query("
        SELECT * FROM blood_donors 
        WHERE is_active = 1 $searchCondition
        ORDER BY created_at DESC 
        LIMIT $limit OFFSET $offset
    ", $searchParams)->fetchAll();
    
    $totalRecords = $db->query("
        SELECT COUNT(*) as count FROM blood_donors 
        WHERE is_active = 1 $searchCondition
    ", $searchParams)->fetch()['count'];
} elseif ($tab === 'inventory') {
    $inventory = $db->query("
        SELECT bi.*, bd.first_name, bd.last_name, bd.donor_id
        FROM blood_inventory bi
        LEFT JOIN blood_donors bd ON bi.donor_id = bd.id
        WHERE 1=1 $searchCondition
        ORDER BY bi.collection_date DESC 
        LIMIT $limit OFFSET $offset
    ", $searchParams)->fetchAll();
    
    $totalRecords = $db->query("
        SELECT COUNT(*) as count FROM blood_inventory bi
        LEFT JOIN blood_donors bd ON bi.donor_id = bd.id
        WHERE 1=1 $searchCondition
    ", $searchParams)->fetch()['count'];
} elseif ($tab === 'requests') {
    $requests = $db->query("
        SELECT br.*, 
               CONCAT(p.first_name, ' ', p.last_name) as patient_name,
               p.patient_id,
               CONCAT(u.first_name, ' ', u.last_name) as doctor_name
        FROM blood_requests br
        JOIN patients p ON br.patient_id = p.id
        JOIN doctors d ON br.doctor_id = d.id
        JOIN users u ON d.user_id = u.id
        WHERE 1=1 $searchCondition
        ORDER BY br.created_at DESC 
        LIMIT $limit OFFSET $offset
    ", $searchParams)->fetchAll();
    
    $totalRecords = $db->query("
        SELECT COUNT(*) as count FROM blood_requests br
        JOIN patients p ON br.patient_id = p.id
        JOIN doctors d ON br.doctor_id = d.id
        JOIN users u ON d.user_id = u.id
        WHERE 1=1 $searchCondition
    ", $searchParams)->fetch()['count'];
}

$totalPages = ceil($totalRecords / $limit);

// Get blood donors for dropdown
$bloodDonors = $db->query("SELECT id, donor_id, first_name, last_name FROM blood_donors WHERE is_active = 1 ORDER BY first_name")->fetchAll();

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
    <title>Blood Bank Management - Hospital System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .container-fluid { padding: 2rem; }
        .card { border: none; border-radius: 15px; box-shadow: 0 0 20px rgba(0,0,0,0.08); margin-bottom: 2rem; }
        .card-header { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); color: white; border-radius: 15px 15px 0 0; padding: 1.5rem; }
        .btn { border-radius: 8px; padding: 0.5rem 1.5rem; font-weight: 500; }
        .btn-primary { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); border: none; }
        .btn-success { background: linear-gradient(135deg, #56ab2f 0%, #a8e6cf 100%); border: none; }
        .btn-danger { background: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%); border: none; }
        .btn-warning { background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%); border: none; color: #333; }
        .form-control, .form-select { border-radius: 8px; border: 2px solid #e9ecef; padding: 0.75rem; }
        .form-control:focus, .form-select:focus { border-color: #e74c3c; box-shadow: 0 0 0 0.2rem rgba(231, 76, 60, 0.25); }
        .search-box { background: white; border-radius: 10px; padding: 1.5rem; margin-bottom: 2rem; box-shadow: 0 0 10px rgba(0,0,0,0.05); }
        .donor-card, .inventory-card, .request-card { background: white; border-radius: 10px; padding: 1.5rem; margin-bottom: 1rem; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .blood-type { font-size: 1.5rem; font-weight: 700; color: #e74c3c; }
        .status-pending { color: #f39c12; }
        .status-approved { color: #3498db; }
        .status-fulfilled { color: #27ae60; }
        .status-cancelled { color: #e74c3c; }
        .priority-critical { color: #e74c3c; font-weight: 600; }
        .priority-high { color: #f39c12; }
        .priority-medium { color: #3498db; }
        .priority-low { color: #95a5a6; }
        .nav-tabs .nav-link.active { background: #e74c3c; color: white; border-color: #e74c3c; }
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
                <h2><i class="fas fa-tint text-danger me-2"></i>Blood Bank Management</h2>
                <p class="text-muted">Manage blood donors, inventory and requests</p>
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
                            <i class="fas fa-user me-2"></i>Add Donor</a></li>
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#inventoryModal">
                            <i class="fas fa-flask me-2"></i>Add Inventory</a></li>
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#requestModal">
                            <i class="fas fa-hand-paper me-2"></i>Create Request</a></li>
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
                        <i class="fas fa-users fa-2x text-danger mb-2"></i>
                        <h4><?php echo $db->query("SELECT COUNT(*) as count FROM blood_donors WHERE is_active = 1")->fetch()['count']; ?></h4>
                        <p class="text-muted mb-0">Total Donors</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-flask fa-2x text-primary mb-2"></i>
                        <h4><?php echo $db->query("SELECT COALESCE(SUM(units_available), 0) as total FROM blood_inventory WHERE status = 'Available'")->fetch()['total']; ?></h4>
                        <p class="text-muted mb-0">Available Units</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-hand-paper fa-2x text-warning mb-2"></i>
                        <h4><?php echo $db->query("SELECT COUNT(*) as count FROM blood_requests WHERE status = 'Pending'")->fetch()['count']; ?></h4>
                        <p class="text-muted mb-0">Pending Requests</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-exclamation-triangle fa-2x text-danger mb-2"></i>
                        <h4><?php echo $db->query("SELECT COUNT(*) as count FROM blood_inventory WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetch()['count']; ?></h4>
                        <p class="text-muted mb-0">Expiring Soon</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link <?php echo $tab === 'donors' ? 'active' : ''; ?>" href="?tab=donors">
                    <i class="fas fa-users me-2"></i>Blood Donors
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $tab === 'inventory' ? 'active' : ''; ?>" href="?tab=inventory">
                    <i class="fas fa-flask me-2"></i>Blood Inventory
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $tab === 'requests' ? 'active' : ''; ?>" href="?tab=requests">
                    <i class="fas fa-hand-paper me-2"></i>Blood Requests
                </a>
            </li>
        </ul>

        <!-- Search and Filter -->
        <div class="search-box">
            <form method="GET" class="row g-3">
                <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="blood_group" class="form-select">
                        <option value="">All Blood Groups</option>
                        <?php foreach (getBloodGroups() as $bg): ?>
                        <option value="<?php echo $bg; ?>" <?php echo $blood_filter === $bg ? 'selected' : ''; ?>>
                            <?php echo $bg; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
            </form>
        </div>

        <!-- Content based on tab -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <?php if ($tab === 'donors'): ?>
                        <i class="fas fa-users me-2"></i>Blood Donors
                        <span class="badge bg-light text-dark ms-2"><?php echo number_format($totalRecords ?? 0); ?> donors</span>
                    <?php elseif ($tab === 'inventory'): ?>
                        <i class="fas fa-flask me-2"></i>Blood Inventory
                        <span class="badge bg-light text-dark ms-2"><?php echo number_format($totalRecords ?? 0); ?> entries</span>
                    <?php elseif ($tab === 'requests'): ?>
                        <i class="fas fa-hand-paper me-2"></i>Blood Requests
                        <span class="badge bg-light text-dark ms-2"><?php echo number_format($totalRecords ?? 0); ?> requests</span>
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
                                        <small><i class="fas fa-tint me-1"></i>Blood Group: <span class="blood-type"><?php echo htmlspecialchars($donor['blood_group']); ?></span></small><br>
                                        <small><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($donor['phone']); ?></small><br>
                                        <small><i class="fas fa-weight me-1"></i>Weight: <?php echo $donor['weight']; ?> kg</small>
                                    </div>
                                    <div class="col-md-6">
                                        <small><i class="fas fa-birthday-cake me-1"></i>Age: <?php echo getAgeFromDOB($donor['date_of_birth']); ?> years</small><br>
                                        <small><i class="fas fa-venus-mars me-1"></i><?php echo htmlspecialchars($donor['gender']); ?></small><br>
                                        <?php if ($donor['last_donation_date']): ?>
                                        <small><i class="fas fa-calendar me-1"></i>Last donation: <?php echo formatDate($donor['last_donation_date'], 'd M Y'); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="text-end">
                                <?php if ($donor['is_eligible']): ?>
                                <span class="badge bg-success mb-2">Eligible</span>
                                <?php else: ?>
                                <span class="badge bg-danger mb-2">Not Eligible</span>
                                <?php endif; ?>
                                <br>
                                <button class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                <?php elseif ($tab === 'inventory' && !empty($inventory)): ?>
                    <?php foreach ($inventory as $item): ?>
                    <div class="inventory-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5>
                                    <span class="blood-type"><?php echo htmlspecialchars($item['blood_group']); ?></span>
                                    - <?php echo htmlspecialchars($item['component_type']); ?>
                                </h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <small><i class="fas fa-vial me-1"></i>Available: <?php echo $item['units_available']; ?> units</small><br>
                                        <small><i class="fas fa-calendar me-1"></i>Collection: <?php echo formatDate($item['collection_date'], 'd M Y'); ?></small><br>
                                        <small><i class="fas fa-calendar-times me-1"></i>Expiry: <?php echo formatDate($item['expiry_date'], 'd M Y'); ?></small>
                                    </div>
                                    <div class="col-md-6">
                                        <small><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($item['storage_location']); ?></small><br>
                                        <?php if ($item['donor_id']): ?>
                                        <small><i class="fas fa-user me-1"></i>Donor: <?php echo htmlspecialchars($item['first_name'] . ' ' . $item['last_name']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-<?php 
                                    echo $item['status'] === 'Available' ? 'success' : 
                                        ($item['status'] === 'Reserved' ? 'warning' : 'secondary'); 
                                ?>">
                                    <?php echo $item['status']; ?>
                                </span>
                                <?php if (strtotime($item['expiry_date']) <= strtotime('+7 days')): ?>
                                <br><span class="badge bg-danger mt-1">Expiring Soon</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                <?php elseif ($tab === 'requests' && !empty($requests)): ?>
                    <?php foreach ($requests as $request): ?>
                    <div class="request-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5>
                                    <span class="blood-type"><?php echo htmlspecialchars($request['blood_group']); ?></span>
                                    - <?php echo htmlspecialchars($request['component_type']); ?>
                                </h5>
                                <p class="text-muted mb-1">Patient: <?php echo htmlspecialchars($request['patient_name']); ?> (<?php echo htmlspecialchars($request['patient_id']); ?>)</p>
                                <div class="row">
                                    <div class="col-md-6">
                                        <small><i class="fas fa-vial me-1"></i>Units needed: <?php echo $request['units_requested']; ?></small><br>
                                        <small><i class="fas fa-user-md me-1"></i>Doctor: <?php echo htmlspecialchars($request['doctor_name']); ?></small><br>
                                        <small><i class="fas fa-calendar me-1"></i>Required by: <?php echo formatDate($request['required_date'], 'd M Y'); ?></small>
                                    </div>
                                    <div class="col-md-6">
                                        <small><i class="fas fa-exclamation me-1"></i>Priority: <span class="priority-<?php echo strtolower($request['priority']); ?>"><?php echo $request['priority']; ?></span></small><br>
                                        <small><i class="fas fa-clipboard me-1"></i>Reason: <?php echo htmlspecialchars($request['reason']); ?></small>
                                    </div>
                                </div>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-<?php 
                                    echo $request['status'] === 'Pending' ? 'warning' : 
                                        ($request['status'] === 'Approved' ? 'info' : 
                                        ($request['status'] === 'Fulfilled' ? 'success' : 'danger')); 
                                ?>">
                                    <?php echo $request['status']; ?>
                                </span>
                                <br>
                                <?php if ($request['status'] === 'Pending' && isAdmin()): ?>
                                <div class="btn-group mt-2" role="group">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="update_request_status">
                                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                        <input type="hidden" name="status" value="Approved">
                                        <button type="submit" class="btn btn-sm btn-success">Approve</button>
                                    </form>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="update_request_status">
                                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                        <input type="hidden" name="status" value="Cancelled">
                                        <button type="submit" class="btn btn-sm btn-danger">Cancel</button>
                                    </form>
                                </div>
                                <?php elseif ($request['status'] === 'Approved' && isAdmin()): ?>
                                <form method="POST" class="d-inline mt-2">
                                    <input type="hidden" name="action" value="update_request_status">
                                    <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                    <input type="hidden" name="status" value="Fulfilled">
                                    <button type="submit" class="btn btn-sm btn-primary">Mark Fulfilled</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-tint fa-3x text-muted mb-3"></i>
                    <h5>No records found</h5>
                    <p class="text-muted">Try adjusting your search criteria or add new records.</p>
                </div>
                <?php endif; ?>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="d-flex justify-content-center mt-4">
                    <?php echo getPagination($page, $totalPages, "?tab=$tab&search=" . urlencode($search) . "&blood_group=" . urlencode($blood_filter)); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modals -->
    
    <!-- Add Donor Modal -->
    <div class="modal fade" id="donorModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user me-2"></i>Add Blood Donor
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
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
                            <div class="col-md-6">
                                <label class="form-label">Weight (kg) <span class="text-danger">*</span></label>
                                <input type="number" name="weight" class="form-control" step="0.1" min="45" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Address <span class="text-danger">*</span></label>
                                <textarea name="address" class="form-control" rows="2" required></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Medical History</label>
                                <textarea name="medical_history" class="form-control" rows="2"></textarea>
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

    <!-- Add Inventory Modal -->
    <div class="modal fade" id="inventoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-flask me-2"></i>Add Blood Inventory
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_inventory">
                        
                        <div class="row g-3">
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
                                <label class="form-label">Component Type <span class="text-danger">*</span></label>
                                <select name="component_type" class="form-select" required>
                                    <option value="">Select Component</option>
                                    <option value="Whole Blood">Whole Blood</option>
                                    <option value="Red Blood Cells">Red Blood Cells</option>
                                    <option value="Plasma">Plasma</option>
                                    <option value="Platelets">Platelets</option>
                                    <option value="White Blood Cells">White Blood Cells</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Units Available <span class="text-danger">*</span></label>
                                <input type="number" name="units_available" class="form-control" min="1" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Collection Date <span class="text-danger">*</span></label>
                                <input type="date" name="collection_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Expiry Date <span class="text-danger">*</span></label>
                                <input type="date" name="expiry_date" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Storage Location <span class="text-danger">*</span></label>
                                <input type="text" name="storage_location" class="form-control" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Donor (Optional)</label>
                                <select name="donor_id" class="form-select">
                                    <option value="">Select Donor</option>
                                    <?php foreach ($bloodDonors as $donor): ?>
                                    <option value="<?php echo $donor['id']; ?>">
                                        <?php echo htmlspecialchars($donor['first_name'] . ' ' . $donor['last_name'] . ' (' . $donor['donor_id'] . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Add Inventory
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Create Request Modal -->
    <div class="modal fade" id="requestModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-hand-paper me-2"></i>Create Blood Request
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create_request">
                        
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
                                <label class="form-label">Blood Group <span class="text-danger">*</span></label>
                                <select name="blood_group" class="form-select" required>
                                    <option value="">Select Blood Group</option>
                                    <?php foreach (getBloodGroups() as $bg): ?>
                                    <option value="<?php echo $bg; ?>"><?php echo $bg; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Component Type <span class="text-danger">*</span></label>
                                <select name="component_type" class="form-select" required>
                                    <option value="">Select Component</option>
                                    <option value="Whole Blood">Whole Blood</option>
                                    <option value="Red Blood Cells">Red Blood Cells</option>
                                    <option value="Plasma">Plasma</option>
                                    <option value="Platelets">Platelets</option>
                                    <option value="White Blood Cells">White Blood Cells</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Units Needed <span class="text-danger">*</span></label>
                                <input type="number" name="units_requested" class="form-control" min="1" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Priority <span class="text-danger">*</span></label>
                                <select name="priority" class="form-select" required>
                                    <option value="">Select Priority</option>
                                    <option value="Low">Low</option>
                                    <option value="Medium">Medium</option>
                                    <option value="High">High</option>
                                    <option value="Critical">Critical</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Request Date <span class="text-danger">*</span></label>
                                <input type="date" name="request_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Required Date <span class="text-danger">*</span></label>
                                <input type="date" name="required_date" class="form-control" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Reason <span class="text-danger">*</span></label>
                                <textarea name="reason" class="form-control" rows="3" required></textarea>
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
</body>
</html>