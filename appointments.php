<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Check if user is logged in and has access
requireLogin();

// Define allowed roles for different actions
$viewRoles = ['admin', 'doctor', 'nurse', 'receptionist'];
$manageRoles = ['admin', 'nurse', 'receptionist']; // Can book/update appointments

requireRole($viewRoles);

$db = Database::getInstance();
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'book_appointment':
                if (!in_array($_SESSION['role'], $manageRoles)) {
                    throw new Exception('You do not have permission to book appointments.');
                }
                
                $appointment_id = generateAppointmentId();
                $patient_id = (int)$_POST['patient_id'];
                $doctor_id = (int)$_POST['doctor_id'];
                $appointment_date = $_POST['appointment_date'];
                $appointment_time = $_POST['appointment_time'];
                $reason = trim($_POST['reason']);
                $notes = trim($_POST['notes']);
                $status = $_POST['status'] ?? 'scheduled';
                
                // Check if doctor is available at that time
                $checkStmt = $db->query(
                    "SELECT id FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status != 'cancelled'",
                    [$doctor_id, $appointment_date, $appointment_time]
                );
                if ($checkStmt->fetch()) {
                    throw new Exception("Doctor is not available at the selected time.");
                }
                
                $stmt = $db->query(
                    "INSERT INTO appointments (appointment_id, patient_id, doctor_id, appointment_date, appointment_time, reason, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                    [$appointment_id, $patient_id, $doctor_id, $appointment_date, $appointment_time, $reason, $notes, $status]
                );
                
                logActivity($_SESSION['user_id'], 'Book Appointment', "Booked appointment: $appointment_id");
                $message = 'Appointment booked successfully!';
                $messageType = 'success';
                break;
                
            case 'update_appointment':
                if (!in_array($_SESSION['role'], array_merge($manageRoles, ['doctor']))) {
                    throw new Exception('You do not have permission to update appointments.');
                }
                
                $id = (int)$_POST['id'];
                $patient_id = (int)$_POST['patient_id'];
                $doctor_id = (int)$_POST['doctor_id'];
                $appointment_date = $_POST['appointment_date'];
                $appointment_time = $_POST['appointment_time'];
                $reason = trim($_POST['reason']);
                $notes = trim($_POST['notes']);
                $status = $_POST['status'];
                
                // Check if doctor is available at that time (excluding current appointment)
                $checkStmt = $db->query(
                    "SELECT id FROM appointments WHERE doctor_id = ? AND appointment_date = ? AND appointment_time = ? AND status != 'cancelled' AND id != ?",
                    [$doctor_id, $appointment_date, $appointment_time, $id]
                );
                if ($checkStmt->fetch()) {
                    throw new Exception("Doctor is not available at the selected time.");
                }
                
                $stmt = $db->query(
                    "UPDATE appointments SET patient_id = ?, doctor_id = ?, appointment_date = ?, appointment_time = ?, reason = ?, notes = ?, status = ? WHERE id = ?",
                    [$patient_id, $doctor_id, $appointment_date, $appointment_time, $reason, $notes, $status, $id]
                );
                
                logActivity($_SESSION['user_id'], 'Update Appointment', "Updated appointment ID: $id");
                $message = 'Appointment updated successfully!';
                $messageType = 'success';
                break;
                
            case 'cancel_appointment':
                if (!in_array($_SESSION['role'], array_merge($manageRoles, ['doctor']))) {
                    throw new Exception('You do not have permission to cancel appointments.');
                }
                
                $id = (int)$_POST['id'];
                $cancellation_reason = trim($_POST['cancellation_reason'] ?? '');
                
                $stmt = $db->query(
                    "UPDATE appointments SET status = 'cancelled', cancellation_reason = ? WHERE id = ?",
                    [$cancellation_reason, $id]
                );
                
                logActivity($_SESSION['user_id'], 'Cancel Appointment', "Cancelled appointment ID: $id");
                $message = 'Appointment cancelled successfully!';
                $messageType = 'success';
                break;
                
            case 'complete_appointment':
                if (!in_array($_SESSION['role'], ['admin', 'doctor'])) {
                    throw new Exception('Only doctors can mark appointments as completed.');
                }
                
                $id = (int)$_POST['id'];
                $consultation_notes = trim($_POST['consultation_notes'] ?? '');
                
                $stmt = $db->query(
                    "UPDATE appointments SET status = 'completed', consultation_notes = ? WHERE id = ?",
                    [$consultation_notes, $id]
                );
                
                logActivity($_SESSION['user_id'], 'Complete Appointment', "Completed appointment ID: $id");
                $message = 'Appointment marked as completed!';
                $messageType = 'success';
                break;
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$doctor_filter = $_GET['doctor'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_filter = $_GET['date'] ?? '';
$page = (int)($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

// Build query conditions
$whereConditions = ["1=1"];
$params = [];

if ($search) {
    $whereConditions[] = "(p.name LIKE ? OR p.patient_id LIKE ? OR d.name LIKE ? OR a.appointment_id LIKE ?)";
    $searchParam = "%$search%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
}

if ($doctor_filter) {
    $whereConditions[] = "a.doctor_id = ?";
    $params[] = $doctor_filter;
}

if ($status_filter) {
    $whereConditions[] = "a.status = ?";
    $params[] = $status_filter;
}

if ($date_filter) {
    $whereConditions[] = "a.appointment_date = ?";
    $params[] = $date_filter;
}

// If user is a doctor, only show their appointments
if ($_SESSION['role'] == 'doctor') {
    $whereConditions[] = "d.employee_id = ?";
    $params[] = $_SESSION['employee_id'];
}

$whereClause = implode(' AND ', $whereConditions);

// Get total count
$countStmt = $db->query(
    "SELECT COUNT(*) as total 
     FROM appointments a 
     JOIN patients p ON a.patient_id = p.id 
     JOIN doctors d ON a.doctor_id = d.id 
     WHERE $whereClause",
    $params
);
$totalRecords = $countStmt->fetch()['total'];
$totalPages = ceil($totalRecords / $limit);

// Get appointments
$stmt = $db->query(
    "SELECT a.*, p.name as patient_name, p.patient_id as patient_number, p.phone as patient_phone,
            d.name as doctor_name, d.specialization, dept.name as department_name
     FROM appointments a 
     JOIN patients p ON a.patient_id = p.id 
     JOIN doctors d ON a.doctor_id = d.id 
     LEFT JOIN departments dept ON d.department_id = dept.id
     WHERE $whereClause 
     ORDER BY a.appointment_date DESC, a.appointment_time DESC 
     LIMIT $limit OFFSET $offset",
    $params
);
$appointments = $stmt->fetchAll();

// Get patients for dropdown
$patientsStmt = $db->query("SELECT id, name, patient_id FROM patients WHERE status = 'active' ORDER BY name");
$patients = $patientsStmt->fetchAll();

// Get doctors for dropdown
$doctorsStmt = $db->query("SELECT id, name, employee_id, specialization FROM doctors WHERE status = 'active' ORDER BY name");
$doctors = $doctorsStmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointments Management - Hospital Management System</title>
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

        .status-scheduled { background-color: var(--info-color); color: white; }
        .status-confirmed { background-color: var(--secondary-color); color: white; }
        .status-completed { background-color: var(--success-color); color: white; }
        .status-cancelled { background-color: var(--danger-color); color: white; }
        .status-no-show { background-color: var(--warning-color); color: white; }

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

        .appointment-card {
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
                    <a class="nav-link active" href="appointments.php">
                        <i class="fas fa-calendar-check me-2"></i> Appointments
                    </a>
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
                                    <i class="fas fa-calendar-check me-3"></i>Appointments Management
                                </h2>
                                <p class="mb-0 mt-2">Manage patient appointments and schedules</p>
                            </div>
                            <?php if (in_array($_SESSION['role'], $manageRoles)): ?>
                            <div class="col-auto">
                                <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#bookAppointmentModal">
                                    <i class="fas fa-plus me-2"></i>Book Appointment
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
                            <div class="col-md-3">
                                <label class="form-label">Search</label>
                                <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Patient, Doctor, ID...">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Doctor</label>
                                <select class="form-select" name="doctor">
                                    <option value="">All Doctors</option>
                                    <?php foreach ($doctors as $doctor): ?>
                                    <option value="<?php echo $doctor['id']; ?>" <?php echo $doctor_filter == $doctor['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($doctor['name'] . ' (' . $doctor['specialization'] . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="">All Status</option>
                                    <option value="scheduled" <?php echo $status_filter == 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                    <option value="confirmed" <?php echo $status_filter == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                    <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Date</label>
                                <input type="date" class="form-control" name="date" value="<?php echo htmlspecialchars($date_filter); ?>">
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

                    <!-- Appointments Table -->
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Appointment ID</th>
                                        <th>Patient</th>
                                        <th>Doctor</th>
                                        <th>Date & Time</th>
                                        <th>Reason</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($appointments)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4">
                                            <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">No appointments found</h5>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($appointments as $appointment): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($appointment['appointment_id']); ?></strong></td>
                                        <td>
                                            <div>
                                                <strong><?php echo htmlspecialchars($appointment['patient_name']); ?></strong>
                                                <small class="d-block text-muted"><?php echo htmlspecialchars($appointment['patient_number']); ?></small>
                                                <small class="d-block text-muted"><?php echo htmlspecialchars($appointment['patient_phone']); ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <strong><?php echo htmlspecialchars($appointment['doctor_name']); ?></strong>
                                                <small class="d-block text-muted"><?php echo htmlspecialchars($appointment['specialization']); ?></small>
                                                <small class="d-block text-muted"><?php echo htmlspecialchars($appointment['department_name'] ?? ''); ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <strong><?php echo formatDate($appointment['appointment_date'], 'd M Y'); ?></strong>
                                                <small class="d-block text-muted"><?php echo date('h:i A', strtotime($appointment['appointment_time'])); ?></small>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($appointment['reason']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $appointment['status']; ?>">
                                                <?php echo ucfirst($appointment['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-info" onclick="viewAppointment(<?php echo $appointment['id']; ?>)" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if (in_array($_SESSION['role'], array_merge($manageRoles, ['doctor'])) && $appointment['status'] != 'cancelled'): ?>
                                                <button class="btn btn-sm btn-warning" onclick="editAppointment(<?php echo $appointment['id']; ?>)" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php endif; ?>
                                                <?php if (in_array($_SESSION['role'], ['admin', 'doctor']) && $appointment['status'] == 'confirmed'): ?>
                                                <button class="btn btn-sm btn-success" onclick="completeAppointment(<?php echo $appointment['id']; ?>)" title="Mark as Completed">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <?php endif; ?>
                                                <?php if (in_array($_SESSION['role'], array_merge($manageRoles, ['doctor'])) && !in_array($appointment['status'], ['completed', 'cancelled'])): ?>
                                                <button class="btn btn-sm btn-danger" onclick="cancelAppointment(<?php echo $appointment['id']; ?>)" title="Cancel">
                                                    <i class="fas fa-times"></i>
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

    <!-- Book Appointment Modal -->
    <?php if (in_array($_SESSION['role'], $manageRoles)): ?>
    <div class="modal fade" id="bookAppointmentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-plus me-2"></i>Book New Appointment
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="book_appointment">
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
                                <label class="form-label">Appointment Date *</label>
                                <input type="date" class="form-control" name="appointment_date" required min="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Appointment Time *</label>
                                <input type="time" class="form-control" name="appointment_time" required>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Reason for Visit *</label>
                                <textarea class="form-control" name="reason" rows="3" required placeholder="Describe the reason for this appointment"></textarea>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" rows="2" placeholder="Additional notes (optional)"></textarea>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="scheduled">Scheduled</option>
                                    <option value="confirmed">Confirmed</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Book Appointment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Edit Appointment Modal -->
    <div class="modal fade" id="editAppointmentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Appointment
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editAppointmentForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_appointment">
                        <input type="hidden" name="id" id="edit_appointment_id">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Patient *</label>
                                <select class="form-select" name="patient_id" id="edit_patient_id" required>
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
                                <select class="form-select" name="doctor_id" id="edit_doctor_id" required>
                                    <option value="">Select Doctor</option>
                                    <?php foreach ($doctors as $doctor): ?>
                                    <option value="<?php echo $doctor['id']; ?>">
                                        <?php echo htmlspecialchars($doctor['name'] . ' (' . $doctor['specialization'] . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Appointment Date *</label>
                                <input type="date" class="form-control" name="appointment_date" id="edit_appointment_date" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Appointment Time *</label>
                                <input type="time" class="form-control" name="appointment_time" id="edit_appointment_time" required>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Reason for Visit *</label>
                                <textarea class="form-control" name="reason" id="edit_reason" rows="3" required></textarea>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" id="edit_notes" rows="2"></textarea>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="edit_status">
                                    <option value="scheduled">Scheduled</option>
                                    <option value="confirmed">Confirmed</option>
                                    <option value="completed">Completed</option>
                                    <option value="no-show">No Show</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Appointment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Appointment Modal -->
    <div class="modal fade" id="viewAppointmentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-eye me-2"></i>Appointment Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewAppointmentContent">
                    <!-- Content will be loaded via JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Cancel Appointment Modal -->
    <div class="modal fade" id="cancelAppointmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-times me-2"></i>Cancel Appointment
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="cancelAppointmentForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="cancel_appointment">
                        <input type="hidden" name="id" id="cancel_appointment_id">
                        <div class="mb-3">
                            <label class="form-label">Cancellation Reason</label>
                            <textarea class="form-control" name="cancellation_reason" rows="3" placeholder="Please provide a reason for cancellation"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-times me-2"></i>Cancel Appointment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Complete Appointment Modal -->
    <div class="modal fade" id="completeAppointmentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-check me-2"></i>Complete Appointment
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="completeAppointmentForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="complete_appointment">
                        <input type="hidden" name="id" id="complete_appointment_id">
                        <div class="mb-3">
                            <label class="form-label">Consultation Notes</label>
                            <textarea class="form-control" name="consultation_notes" rows="4" placeholder="Enter consultation notes and recommendations"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check me-2"></i>Mark as Completed
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Edit Appointment
        function editAppointment(id) {
            fetch(`get-appointment-details.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const appointment = data.appointment;
                        document.getElementById('edit_appointment_id').value = appointment.id;
                        document.getElementById('edit_patient_id').value = appointment.patient_id;
                        document.getElementById('edit_doctor_id').value = appointment.doctor_id;
                        document.getElementById('edit_appointment_date').value = appointment.appointment_date;
                        document.getElementById('edit_appointment_time').value = appointment.appointment_time;
                        document.getElementById('edit_reason').value = appointment.reason;
                        document.getElementById('edit_notes').value = appointment.notes || '';
                        document.getElementById('edit_status').value = appointment.status;
                        
                        new bootstrap.Modal(document.getElementById('editAppointmentModal')).show();
                    } else {
                        alert('Error loading appointment details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading appointment details');
                });
        }

        // View Appointment
        function viewAppointment(id) {
            fetch(`get-appointment-details.php?id=${id}&view=true`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('viewAppointmentContent').innerHTML = data.html;
                        new bootstrap.Modal(document.getElementById('viewAppointmentModal')).show();
                    } else {
                        alert('Error loading appointment details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading appointment details');
                });
        }

        // Cancel Appointment
        function cancelAppointment(id) {
            document.getElementById('cancel_appointment_id').value = id;
            new bootstrap.Modal(document.getElementById('cancelAppointmentModal')).show();
        }

        // Complete Appointment
        function completeAppointment(id) {
            document.getElementById('complete_appointment_id').value = id;
            new bootstrap.Modal(document.getElementById('completeAppointmentModal')).show();
        }
    </script>
</body>
</html>