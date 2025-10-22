<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Check if user is logged in and is admin
requireLogin();
requireRole(['admin']);

$db = Database::getInstance();
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'add_room':
                $room_number = trim($_POST['room_number']);
                $room_type = $_POST['room_type'];
                $floor = (int)$_POST['floor'];
                $department_id = $_POST['department_id'] ? (int)$_POST['department_id'] : null;
                $capacity = (int)$_POST['capacity'];
                $rate_per_day = (float)$_POST['rate_per_day'];
                $facilities = trim($_POST['facilities']);
                $status = $_POST['status'] ?? 'available';
                
                // Check if room number already exists
                $checkStmt = $db->query("SELECT id FROM rooms WHERE room_number = ?", [$room_number]);
                if ($checkStmt->fetch()) {
                    throw new Exception("Room number already exists");
                }
                
                $stmt = $db->query(
                    "INSERT INTO rooms (room_number, room_type, floor, department_id, capacity, rate_per_day, facilities, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$room_number, $room_type, $floor, $department_id, $capacity, $rate_per_day, $facilities, $status]
                );
                
                logActivity($_SESSION['user_id'], 'Add Room', "Added room: $room_number");
                $message = 'Room added successfully!';
                $messageType = 'success';
                break;
                
            case 'update_room':
                $id = (int)$_POST['id'];
                $room_number = trim($_POST['room_number']);
                $room_type = $_POST['room_type'];
                $floor = (int)$_POST['floor'];
                $department_id = $_POST['department_id'] ? (int)$_POST['department_id'] : null;
                $capacity = (int)$_POST['capacity'];
                $rate_per_day = (float)$_POST['rate_per_day'];
                $facilities = trim($_POST['facilities']);
                $status = $_POST['status'];
                
                // Check if room number already exists for other rooms
                $checkStmt = $db->query("SELECT id FROM rooms WHERE room_number = ? AND id != ?", [$room_number, $id]);
                if ($checkStmt->fetch()) {
                    throw new Exception("Room number already exists");
                }
                
                $stmt = $db->query(
                    "UPDATE rooms SET room_number = ?, room_type = ?, floor = ?, department_id = ?, capacity = ?, rate_per_day = ?, facilities = ?, status = ? WHERE id = ?",
                    [$room_number, $room_type, $floor, $department_id, $capacity, $rate_per_day, $facilities, $status, $id]
                );
                
                logActivity($_SESSION['user_id'], 'Update Room', "Updated room: $room_number");
                $message = 'Room updated successfully!';
                $messageType = 'success';
                break;
                
            case 'add_bed':
                $room_id = (int)$_POST['room_id'];
                $bed_number = trim($_POST['bed_number']);
                $bed_type = $_POST['bed_type'];
                $status = $_POST['status'] ?? 'available';
                
                // Check if bed number already exists in the room
                $checkStmt = $db->query("SELECT id FROM beds WHERE room_id = ? AND bed_number = ?", [$room_id, $bed_number]);
                if ($checkStmt->fetch()) {
                    throw new Exception("Bed number already exists in this room");
                }
                
                $stmt = $db->query(
                    "INSERT INTO beds (room_id, bed_number, bed_type, status) VALUES (?, ?, ?, ?)",
                    [$room_id, $bed_number, $bed_type, $status]
                );
                
                logActivity($_SESSION['user_id'], 'Add Bed', "Added bed: $bed_number");
                $message = 'Bed added successfully!';
                $messageType = 'success';
                break;
                
            case 'update_bed':
                $id = (int)$_POST['id'];
                $bed_number = trim($_POST['bed_number']);
                $bed_type = $_POST['bed_type'];
                $status = $_POST['status'];
                
                $stmt = $db->query(
                    "UPDATE beds SET bed_number = ?, bed_type = ?, status = ? WHERE id = ?",
                    [$bed_number, $bed_type, $status, $id]
                );
                
                logActivity($_SESSION['user_id'], 'Update Bed', "Updated bed: $bed_number");
                $message = 'Bed updated successfully!';
                $messageType = 'success';
                break;
                
            case 'assign_bed':
                $bed_id = (int)$_POST['bed_id'];
                $patient_id = (int)$_POST['patient_id'];
                $assigned_date = $_POST['assigned_date'];
                $notes = trim($_POST['notes']);
                
                $db->beginTransaction();
                
                // Check if bed is available
                $bedStmt = $db->query("SELECT status FROM beds WHERE id = ?", [$bed_id]);
                $bed = $bedStmt->fetch();
                if (!$bed || $bed['status'] !== 'available') {
                    throw new Exception("Bed is not available");
                }
                
                // Update bed status
                $db->query("UPDATE beds SET status = 'occupied' WHERE id = ?", [$bed_id]);
                
                // Create bed assignment
                $stmt = $db->query(
                    "INSERT INTO bed_assignments (bed_id, patient_id, assigned_date, notes, status) VALUES (?, ?, ?, ?, 'active')",
                    [$bed_id, $patient_id, $assigned_date, $notes]
                );
                
                $db->commit();
                
                logActivity($_SESSION['user_id'], 'Assign Bed', "Assigned bed to patient ID: $patient_id");
                $message = 'Bed assigned successfully!';
                $messageType = 'success';
                break;
                
            case 'discharge_bed':
                $assignment_id = (int)$_POST['assignment_id'];
                $discharge_date = $_POST['discharge_date'];
                $discharge_notes = trim($_POST['discharge_notes']);
                
                $db->beginTransaction();
                
                // Get assignment details
                $assignStmt = $db->query("SELECT bed_id FROM bed_assignments WHERE id = ?", [$assignment_id]);
                $assignment = $assignStmt->fetch();
                
                if ($assignment) {
                    // Update assignment
                    $db->query(
                        "UPDATE bed_assignments SET status = 'discharged', discharge_date = ?, discharge_notes = ? WHERE id = ?",
                        [$discharge_date, $discharge_notes, $assignment_id]
                    );
                    
                    // Update bed status
                    $db->query("UPDATE beds SET status = 'available' WHERE id = ?", [$assignment['bed_id']]);
                }
                
                $db->commit();
                
                logActivity($_SESSION['user_id'], 'Discharge Bed', "Discharged patient from bed");
                $message = 'Patient discharged successfully!';
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
$activeTab = $_GET['tab'] ?? 'rooms';

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$floor_filter = $_GET['floor'] ?? '';
$page = (int)($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

// Initialize data arrays
$rooms = $beds = $assignments = [];

if ($activeTab == 'rooms') {
    // Build query for rooms
    $whereConditions = ["1=1"];
    $params = [];

    if ($search) {
        $whereConditions[] = "(r.room_number LIKE ? OR r.facilities LIKE ?)";
        $searchParam = "%$search%";
        $params = array_merge($params, [$searchParam, $searchParam]);
    }

    if ($type_filter) {
        $whereConditions[] = "r.room_type = ?";
        $params[] = $type_filter;
    }

    if ($status_filter) {
        $whereConditions[] = "r.status = ?";
        $params[] = $status_filter;
    }

    if ($floor_filter) {
        $whereConditions[] = "r.floor = ?";
        $params[] = $floor_filter;
    }

    $whereClause = implode(' AND ', $whereConditions);

    // Get total count
    $countStmt = $db->query(
        "SELECT COUNT(*) as total FROM rooms r WHERE $whereClause",
        $params
    );
    $totalRecords = $countStmt->fetch()['total'];
    $totalPages = ceil($totalRecords / $limit);

    // Get rooms
    $stmt = $db->query(
        "SELECT r.*, d.name as department_name,
                (SELECT COUNT(*) FROM beds WHERE room_id = r.id) as total_beds,
                (SELECT COUNT(*) FROM beds WHERE room_id = r.id AND status = 'occupied') as occupied_beds
         FROM rooms r 
         LEFT JOIN departments d ON r.department_id = d.id 
         WHERE $whereClause 
         ORDER BY r.floor ASC, r.room_number ASC 
         LIMIT $limit OFFSET $offset",
        $params
    );
    $rooms = $stmt->fetchAll();
    
} else if ($activeTab == 'beds') {
    // Get beds with room information
    $stmt = $db->query(
        "SELECT b.*, r.room_number, r.room_type, r.floor
         FROM beds b
         JOIN rooms r ON b.room_id = r.id
         ORDER BY r.floor ASC, r.room_number ASC, b.bed_number ASC"
    );
    $beds = $stmt->fetchAll();
    
} else if ($activeTab == 'assignments') {
    // Get bed assignments
    $stmt = $db->query(
        "SELECT ba.*, p.name as patient_name, p.patient_id as patient_number,
                r.room_number, b.bed_number
         FROM bed_assignments ba
         JOIN patients p ON ba.patient_id = p.id
         JOIN beds b ON ba.bed_id = b.id
         JOIN rooms r ON b.room_id = r.id
         ORDER BY ba.assigned_date DESC"
    );
    $assignments = $stmt->fetchAll();
}

// Get departments for dropdown
$deptStmt = $db->query("SELECT * FROM departments WHERE status = 'active' ORDER BY name");
$departments = $deptStmt->fetchAll();

// Get patients for dropdown
$patientsStmt = $db->query("SELECT id, name, patient_id FROM patients WHERE status = 'active' ORDER BY name");
$patients = $patientsStmt->fetchAll();

// Get available beds for assignment
$availableBedsStmt = $db->query(
    "SELECT b.id, b.bed_number, r.room_number 
     FROM beds b 
     JOIN rooms r ON b.room_id = r.id 
     WHERE b.status = 'available' 
     ORDER BY r.room_number, b.bed_number"
);
$availableBeds = $availableBedsStmt->fetchAll();

// Get room statistics
$stats = [
    'total_rooms' => 0,
    'available_rooms' => 0,
    'occupied_rooms' => 0,
    'maintenance_rooms' => 0,
    'total_beds' => 0,
    'available_beds' => 0,
    'occupied_beds' => 0
];

try {
    $statsStmt = $db->query("
        SELECT 
            COUNT(*) as total_rooms,
            COUNT(CASE WHEN status = 'available' THEN 1 END) as available_rooms,
            COUNT(CASE WHEN status = 'occupied' THEN 1 END) as occupied_rooms,
            COUNT(CASE WHEN status = 'maintenance' THEN 1 END) as maintenance_rooms
        FROM rooms
    ");
    $roomStats = $statsStmt->fetch();
    
    $bedStatsStmt = $db->query("
        SELECT 
            COUNT(*) as total_beds,
            COUNT(CASE WHEN status = 'available' THEN 1 END) as available_beds,
            COUNT(CASE WHEN status = 'occupied' THEN 1 END) as occupied_beds
        FROM beds
    ");
    $bedStats = $bedStatsStmt->fetch();
    
    $stats = array_merge($roomStats, $bedStats);
} catch (Exception $e) {
    // Handle silently
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rooms & Beds Management - Hospital Management System</title>
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--secondary-color), #5dade2);
            color: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }

        .stat-card h3 {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-card.success { background: linear-gradient(135deg, var(--success-color), #2ecc71); }
        .stat-card.warning { background: linear-gradient(135deg, var(--warning-color), #f1c40f); }
        .stat-card.danger { background: linear-gradient(135deg, var(--danger-color), #e74c3c); }

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

        .status-available { background-color: var(--success-color); color: white; }
        .status-occupied { background-color: var(--danger-color); color: white; }
        .status-maintenance { background-color: var(--warning-color); color: white; }
        .status-active { background-color: var(--success-color); color: white; }
        .status-discharged { background-color: var(--info-color); color: white; }

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

        .room-card {
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
                    <a class="nav-link" href="appointments.php">
                        <i class="fas fa-calendar-check me-2"></i> Appointments
                    </a>
                    <a class="nav-link" href="pharmacy.php">
                        <i class="fas fa-pills me-2"></i> Pharmacy
                    </a>
                    <a class="nav-link" href="laboratory.php">
                        <i class="fas fa-flask me-2"></i> Laboratory
                    </a>
                    <a class="nav-link" href="blood-bank.php">
                        <i class="fas fa-tint me-2"></i> Blood Bank
                    </a>
                    <a class="nav-link" href="organ-donation.php">
                        <i class="fas fa-heart me-2"></i> Organ Donation
                    </a>
                    <a class="nav-link" href="billing.php">
                        <i class="fas fa-file-invoice-dollar me-2"></i> Billing
                    </a>
                    <a class="nav-link active" href="rooms-beds.php">
                        <i class="fas fa-bed me-2"></i> Rooms & Beds
                    </a>
                    <a class="nav-link" href="users.php">
                        <i class="fas fa-users me-2"></i> Users
                    </a>
                    <a class="nav-link" href="reports.php">
                        <i class="fas fa-chart-bar me-2"></i> Reports
                    </a>
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
                                    <i class="fas fa-bed me-3"></i>Rooms & Beds Management
                                </h2>
                                <p class="mb-0 mt-2">Manage hospital rooms, beds, and patient assignments</p>
                            </div>
                            <div class="col-auto">
                                <button class="btn btn-light me-2" data-bs-toggle="modal" data-bs-target="#addRoomModal">
                                    <i class="fas fa-plus me-2"></i>Add Room
                                </button>
                                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#assignBedModal">
                                    <i class="fas fa-user-plus me-2"></i>Assign Bed
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Statistics -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <h3><?php echo $stats['total_rooms']; ?></h3>
                            <p class="mb-0">Total Rooms</p>
                        </div>
                        <div class="stat-card success">
                            <h3><?php echo $stats['available_rooms']; ?></h3>
                            <p class="mb-0">Available Rooms</p>
                        </div>
                        <div class="stat-card danger">
                            <h3><?php echo $stats['occupied_rooms']; ?></h3>
                            <p class="mb-0">Occupied Rooms</p>
                        </div>
                        <div class="stat-card">
                            <h3><?php echo $stats['total_beds']; ?></h3>
                            <p class="mb-0">Total Beds</p>
                        </div>
                        <div class="stat-card success">
                            <h3><?php echo $stats['available_beds']; ?></h3>
                            <p class="mb-0">Available Beds</p>
                        </div>
                        <div class="stat-card danger">
                            <h3><?php echo $stats['occupied_beds']; ?></h3>
                            <p class="mb-0">Occupied Beds</p>
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
                            <a class="nav-link <?php echo $activeTab == 'rooms' ? 'active' : ''; ?>" href="?tab=rooms">
                                <i class="fas fa-door-open me-2"></i>Rooms
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $activeTab == 'beds' ? 'active' : ''; ?>" href="?tab=beds">
                                <i class="fas fa-bed me-2"></i>Beds
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $activeTab == 'assignments' ? 'active' : ''; ?>" href="?tab=assignments">
                                <i class="fas fa-user-check me-2"></i>Assignments
                            </a>
                        </li>
                    </ul>

                    <!-- Tab Content -->
                    <?php if ($activeTab == 'rooms'): ?>
                    <!-- Rooms Tab -->
                    <div class="search-filters">
                        <form method="GET" class="row g-3">
                            <input type="hidden" name="tab" value="rooms">
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Room number, facilities...">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Room Type</label>
                                <select class="form-select" name="type">
                                    <option value="">All Types</option>
                                    <option value="General" <?php echo $type_filter == 'General' ? 'selected' : ''; ?>>General</option>
                                    <option value="Private" <?php echo $type_filter == 'Private' ? 'selected' : ''; ?>>Private</option>
                                    <option value="ICU" <?php echo $type_filter == 'ICU' ? 'selected' : ''; ?>>ICU</option>
                                    <option value="Emergency" <?php echo $type_filter == 'Emergency' ? 'selected' : ''; ?>>Emergency</option>
                                    <option value="Operation Theater" <?php echo $type_filter == 'Operation Theater' ? 'selected' : ''; ?>>Operation Theater</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="">All Status</option>
                                    <option value="available" <?php echo $status_filter == 'available' ? 'selected' : ''; ?>>Available</option>
                                    <option value="occupied" <?php echo $status_filter == 'occupied' ? 'selected' : ''; ?>>Occupied</option>
                                    <option value="maintenance" <?php echo $status_filter == 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Floor</label>
                                <input type="number" class="form-control" name="floor" value="<?php echo htmlspecialchars($floor_filter); ?>" min="0">
                            </div>
                            <div class="col-md-3">
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
                                        <th>Room Number</th>
                                        <th>Type</th>
                                        <th>Floor</th>
                                        <th>Department</th>
                                        <th>Capacity</th>
                                        <th>Beds</th>
                                        <th>Rate/Day</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($rooms)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4">
                                            <i class="fas fa-door-open fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">No rooms found</h5>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($rooms as $room): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($room['room_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($room['room_type']); ?></td>
                                        <td><?php echo $room['floor']; ?></td>
                                        <td><?php echo htmlspecialchars($room['department_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo $room['capacity']; ?></td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $room['occupied_beds']; ?></span>
                                            /
                                            <span class="badge bg-secondary"><?php echo $room['total_beds']; ?></span>
                                        </td>
                                        <td><?php echo formatCurrency($room['rate_per_day']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $room['status']; ?>">
                                                <?php echo ucfirst($room['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-info" onclick="viewRoom(<?php echo $room['id']; ?>)" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-warning" onclick="editRoom(<?php echo $room['id']; ?>)" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-success" onclick="addBedToRoom(<?php echo $room['id']; ?>)" title="Add Bed">
                                                    <i class="fas fa-bed"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php elseif ($activeTab == 'beds'): ?>
                    <!-- Beds Tab -->
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Room Number</th>
                                        <th>Bed Number</th>
                                        <th>Room Type</th>
                                        <th>Bed Type</th>
                                        <th>Floor</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($beds)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4">
                                            <i class="fas fa-bed fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">No beds found</h5>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($beds as $bed): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($bed['room_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($bed['bed_number']); ?></td>
                                        <td><?php echo htmlspecialchars($bed['room_type']); ?></td>
                                        <td><?php echo htmlspecialchars($bed['bed_type']); ?></td>
                                        <td><?php echo $bed['floor']; ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $bed['status']; ?>">
                                                <?php echo ucfirst($bed['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-warning" onclick="editBed(<?php echo $bed['id']; ?>)" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php if ($bed['status'] == 'available'): ?>
                                                <button class="btn btn-sm btn-success" onclick="assignBed(<?php echo $bed['id']; ?>)" title="Assign">
                                                    <i class="fas fa-user-plus"></i>
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

                    <?php elseif ($activeTab == 'assignments'): ?>
                    <!-- Assignments Tab -->
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Patient</th>
                                        <th>Room</th>
                                        <th>Bed</th>
                                        <th>Assigned Date</th>
                                        <th>Discharge Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($assignments)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4">
                                            <i class="fas fa-user-check fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">No bed assignments found</h5>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($assignments as $assignment): ?>
                                    <tr>
                                        <td>
                                            <div>
                                                <strong><?php echo htmlspecialchars($assignment['patient_name']); ?></strong>
                                                <small class="d-block text-muted"><?php echo htmlspecialchars($assignment['patient_number']); ?></small>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($assignment['room_number']); ?></td>
                                        <td><?php echo htmlspecialchars($assignment['bed_number']); ?></td>
                                        <td><?php echo formatDate($assignment['assigned_date'], 'd M Y'); ?></td>
                                        <td><?php echo $assignment['discharge_date'] ? formatDate($assignment['discharge_date'], 'd M Y') : 'N/A'; ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $assignment['status']; ?>">
                                                <?php echo ucfirst($assignment['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($assignment['status'] == 'active'): ?>
                                                <button class="btn btn-sm btn-danger" onclick="dischargeBed(<?php echo $assignment['id']; ?>)" title="Discharge">
                                                    <i class="fas fa-sign-out-alt"></i>
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

                    <!-- Pagination -->
                    <?php if (isset($totalPages) && $totalPages > 1): ?>
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

    <!-- Add Room Modal -->
    <div class="modal fade" id="addRoomModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-door-open me-2"></i>Add New Room
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_room">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Room Number *</label>
                                <input type="text" class="form-control" name="room_number" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Room Type *</label>
                                <select class="form-select" name="room_type" required>
                                    <option value="">Select Type</option>
                                    <option value="General">General</option>
                                    <option value="Private">Private</option>
                                    <option value="ICU">ICU</option>
                                    <option value="Emergency">Emergency</option>
                                    <option value="Operation Theater">Operation Theater</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Floor *</label>
                                <input type="number" class="form-control" name="floor" required min="0">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Department</label>
                                <select class="form-select" name="department_id">
                                    <option value="">Select Department</option>
                                    <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Capacity *</label>
                                <input type="number" class="form-control" name="capacity" required min="1">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Rate per Day</label>
                                <input type="number" class="form-control" name="rate_per_day" step="0.01" min="0">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Facilities</label>
                                <textarea class="form-control" name="facilities" rows="3" placeholder="AC, TV, Private Bathroom, etc."></textarea>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="available">Available</option>
                                    <option value="maintenance">Maintenance</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Add Room
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Assign Bed Modal -->
    <div class="modal fade" id="assignBedModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus me-2"></i>Assign Bed
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="assign_bed">
                        <div class="mb-3">
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
                        <div class="mb-3">
                            <label class="form-label">Available Bed *</label>
                            <select class="form-select" name="bed_id" required>
                                <option value="">Select Bed</option>
                                <?php foreach ($availableBeds as $bed): ?>
                                <option value="<?php echo $bed['id']; ?>">
                                    Room <?php echo htmlspecialchars($bed['room_number']); ?> - Bed <?php echo htmlspecialchars($bed['bed_number']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Assigned Date *</label>
                            <input type="date" class="form-control" name="assigned_date" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="Assignment notes"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Assign Bed
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editRoom(id) {
            // Implementation for editing room
            alert('Edit room functionality - to be implemented');
        }

        function viewRoom(id) {
            // Implementation for viewing room details
            alert('View room functionality - to be implemented');
        }

        function addBedToRoom(roomId) {
            // Implementation for adding bed to room
            alert('Add bed functionality - to be implemented');
        }

        function editBed(id) {
            // Implementation for editing bed
            alert('Edit bed functionality - to be implemented');
        }

        function assignBed(bedId) {
            // Set the bed in assign modal
            document.querySelector('[name="bed_id"]').value = bedId;
            new bootstrap.Modal(document.getElementById('assignBedModal')).show();
        }

        function dischargeBed(assignmentId) {
            if (confirm('Are you sure you want to discharge this patient?')) {
                // Implementation for discharge
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="discharge_bed">
                    <input type="hidden" name="assignment_id" value="${assignmentId}">
                    <input type="hidden" name="discharge_date" value="${new Date().toISOString().split('T')[0]}">
                    <input type="hidden" name="discharge_notes" value="Patient discharged">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>