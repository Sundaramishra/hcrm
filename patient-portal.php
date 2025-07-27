<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Check if user is logged in
requireLogin();

$db = Database::getInstance();
$message = '';
$messageType = '';

// Get patient info based on logged in user
$patient = null;
if ($_SESSION['role'] == 'patient') {
    // If logged in as patient, get their info
    $stmt = $db->query("SELECT * FROM patients WHERE user_id = ?", [$_SESSION['user_id']]);
    $patient = $stmt->fetch();
} else if (isset($_GET['patient_id']) && isAdmin()) {
    // If admin is viewing a specific patient
    $stmt = $db->query("SELECT * FROM patients WHERE id = ?", [$_GET['patient_id']]);
    $patient = $stmt->fetch();
} else {
    // Redirect to dashboard if no patient access
    header('Location: dashboard.php');
    exit;
}

if (!$patient) {
    header('Location: dashboard.php?error=patient_not_found');
    exit;
}

// Get current tab
$activeTab = $_GET['tab'] ?? 'profile';

// Get patient data based on active tab
$appointments = $labReports = $bills = $bloodDonations = $organDonations = $medicalRecords = [];

try {
    if ($activeTab == 'appointments' || $activeTab == 'profile') {
        // Get appointments
        $stmt = $db->query(
            "SELECT a.*, d.name as doctor_name, d.specialization, dept.name as department_name
             FROM appointments a
             JOIN doctors d ON a.doctor_id = d.id
             LEFT JOIN departments dept ON d.department_id = dept.id
             WHERE a.patient_id = ?
             ORDER BY a.appointment_date DESC, a.appointment_time DESC
             LIMIT 10",
            [$patient['id']]
        );
        $appointments = $stmt->fetchAll();
    }

    if ($activeTab == 'lab-reports' || $activeTab == 'profile') {
        // Get lab reports
        $stmt = $db->query(
            "SELECT lr.*, d.name as doctor_name,
                    GROUP_CONCAT(CONCAT(lt.name, ': ', lrt.result_value, ' ', COALESCE(lt.unit, ''), ' (', lrt.result_status, ')') SEPARATOR '<br>') as test_results
             FROM lab_requests lr
             JOIN doctors d ON lr.doctor_id = d.id
             LEFT JOIN lab_request_tests lrt ON lr.id = lrt.lab_request_id
             LEFT JOIN lab_tests lt ON lrt.lab_test_id = lt.id
             WHERE lr.patient_id = ? AND lr.status = 'completed'
             GROUP BY lr.id
             ORDER BY lr.created_at DESC
             LIMIT 10",
            [$patient['id']]
        );
        $labReports = $stmt->fetchAll();
    }

    if ($activeTab == 'bills' || $activeTab == 'profile') {
        // Get bills
        $stmt = $db->query(
            "SELECT b.*, p.amount as total_paid
             FROM bills b
             LEFT JOIN (
                 SELECT bill_id, SUM(amount) as amount 
                 FROM payments 
                 GROUP BY bill_id
             ) p ON b.id = p.bill_id
             WHERE b.patient_id = ?
             ORDER BY b.created_at DESC
             LIMIT 10",
            [$patient['id']]
        );
        $bills = $stmt->fetchAll();
    }

    if ($activeTab == 'blood-donations' || $activeTab == 'profile') {
        // Get blood donations
        $stmt = $db->query(
            "SELECT bd.*, br.request_date, br.units_requested, br.status as request_status
             FROM blood_donors bd
             LEFT JOIN blood_requests br ON bd.id = br.donor_id
             WHERE bd.patient_id = ?
             ORDER BY bd.created_at DESC
             LIMIT 10",
            [$patient['id']]
        );
        $bloodDonations = $stmt->fetchAll();
    }

    if ($activeTab == 'organ-donations' || $activeTab == 'profile') {
        // Get organ donations
        $stmt = $db->query(
            "SELECT * FROM organ_donors WHERE patient_id = ? ORDER BY created_at DESC LIMIT 10",
            [$patient['id']]
        );
        $organDonations = $stmt->fetchAll();
    }

    if ($activeTab == 'medical-records' || $activeTab == 'profile') {
        // Get medical records
        $stmt = $db->query(
            "SELECT mr.*, d.name as doctor_name
             FROM medical_records mr
             LEFT JOIN doctors d ON mr.doctor_id = d.id
             WHERE mr.patient_id = ?
             ORDER BY mr.created_at DESC
             LIMIT 10",
            [$patient['id']]
        );
        $medicalRecords = $stmt->fetchAll();
    }

} catch (Exception $e) {
    $message = 'Error loading patient data: ' . $e->getMessage();
    $messageType = 'error';
}

// Get assigned medical team
$assignedDoctors = [];
$assignedNurses = [];

try {
    // Get doctors from recent appointments
    $stmt = $db->query(
        "SELECT DISTINCT d.id, d.name, d.specialization, d.phone, d.email, dept.name as department_name
         FROM appointments a
         JOIN doctors d ON a.doctor_id = d.id
         LEFT JOIN departments dept ON d.department_id = dept.id
         WHERE a.patient_id = ? AND a.appointment_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
         ORDER BY a.appointment_date DESC",
        [$patient['id']]
    );
    $assignedDoctors = $stmt->fetchAll();

    // Get nurses from recent assignments (if table exists)
    // This would need a patient_assignments table in real implementation
    
} catch (Exception $e) {
    // Handle silently
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Portal - <?php echo htmlspecialchars($patient['name']); ?></title>
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

        .patient-header {
            background: linear-gradient(135deg, var(--primary-color), #34495e);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
        }

        .info-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            border-left: 4px solid var(--secondary-color);
        }

        .stat-card {
            background: linear-gradient(135deg, var(--secondary-color), #5dade2);
            color: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
        }

        .stat-card h3 {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .nav-tabs .nav-link {
            border: none;
            color: var(--primary-color);
            font-weight: 500;
            margin-right: 10px;
        }

        .nav-tabs .nav-link.active {
            background-color: var(--primary-color);
            color: white;
            border-radius: 8px;
        }

        .table-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
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
        .status-paid { background-color: var(--success-color); color: white; }
        .status-pending { background-color: var(--warning-color); color: white; }
        .status-overdue { background-color: var(--danger-color); color: white; }

        .result-normal { color: var(--success-color); font-weight: 600; }
        .result-abnormal { color: var(--danger-color); font-weight: 600; }
        .result-critical { color: var(--danger-color); font-weight: 600; background-color: #ffeaea; padding: 2px 8px; border-radius: 4px; }

        .profile-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .team-member {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .team-member:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transform: translateY(-2px);
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

        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
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
                    <?php if ($_SESSION['role'] != 'patient'): ?>
                    <a class="nav-link" href="dashboard.php">
                        <i class="fas fa-dashboard me-2"></i> Dashboard
                    </a>
                    <?php endif; ?>
                    <a class="nav-link active" href="patient-portal.php">
                        <i class="fas fa-user-injured me-2"></i> Patient Portal
                    </a>
                    <?php if ($_SESSION['role'] != 'patient'): ?>
                    <a class="nav-link" href="patients.php">
                        <i class="fas fa-users me-2"></i> All Patients
                    </a>
                    <a class="nav-link" href="doctors.php">
                        <i class="fas fa-user-md me-2"></i> Doctors
                    </a>
                    <a class="nav-link" href="appointments.php">
                        <i class="fas fa-calendar-check me-2"></i> Appointments
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
                    <!-- Patient Header -->
                    <div class="patient-header">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <div class="d-flex align-items-center">
                                    <div class="bg-white text-primary rounded-circle d-flex align-items-center justify-content-center me-4" style="width: 80px; height: 80px; font-size: 2rem;">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <div>
                                        <h2 class="mb-1"><?php echo htmlspecialchars($patient['name']); ?></h2>
                                        <p class="mb-1"><strong>Patient ID:</strong> <?php echo htmlspecialchars($patient['patient_id']); ?></p>
                                        <p class="mb-0">
                                            <i class="fas fa-birthday-cake me-2"></i><?php echo getAgeFromDOB($patient['date_of_birth']); ?> years old
                                            <span class="ms-3"><i class="fas fa-venus-mars me-2"></i><?php echo htmlspecialchars($patient['gender']); ?></span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <p class="mb-1"><i class="fas fa-phone me-2"></i><?php echo htmlspecialchars($patient['phone']); ?></p>
                                <p class="mb-1"><i class="fas fa-envelope me-2"></i><?php echo htmlspecialchars($patient['email']); ?></p>
                                <p class="mb-0"><i class="fas fa-map-marker-alt me-2"></i><?php echo htmlspecialchars($patient['address']); ?></p>
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
                            <a class="nav-link <?php echo $activeTab == 'profile' ? 'active' : ''; ?>" href="?tab=profile<?php echo isset($_GET['patient_id']) ? '&patient_id=' . $_GET['patient_id'] : ''; ?>">
                                <i class="fas fa-user me-2"></i>Overview
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $activeTab == 'appointments' ? 'active' : ''; ?>" href="?tab=appointments<?php echo isset($_GET['patient_id']) ? '&patient_id=' . $_GET['patient_id'] : ''; ?>">
                                <i class="fas fa-calendar-check me-2"></i>Appointments
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $activeTab == 'lab-reports' ? 'active' : ''; ?>" href="?tab=lab-reports<?php echo isset($_GET['patient_id']) ? '&patient_id=' . $_GET['patient_id'] : ''; ?>">
                                <i class="fas fa-flask me-2"></i>Lab Reports
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $activeTab == 'bills' ? 'active' : ''; ?>" href="?tab=bills<?php echo isset($_GET['patient_id']) ? '&patient_id=' . $_GET['patient_id'] : ''; ?>">
                                <i class="fas fa-file-invoice-dollar me-2"></i>Bills & Payments
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $activeTab == 'medical-records' ? 'active' : ''; ?>" href="?tab=medical-records<?php echo isset($_GET['patient_id']) ? '&patient_id=' . $_GET['patient_id'] : ''; ?>">
                                <i class="fas fa-notes-medical me-2"></i>Medical Records
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $activeTab == 'blood-donations' ? 'active' : ''; ?>" href="?tab=blood-donations<?php echo isset($_GET['patient_id']) ? '&patient_id=' . $_GET['patient_id'] : ''; ?>">
                                <i class="fas fa-tint me-2"></i>Blood Donations
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $activeTab == 'organ-donations' ? 'active' : ''; ?>" href="?tab=organ-donations<?php echo isset($_GET['patient_id']) ? '&patient_id=' . $_GET['patient_id'] : ''; ?>">
                                <i class="fas fa-heart me-2"></i>Organ Donations
                            </a>
                        </li>
                    </ul>

                    <!-- Tab Content -->
                    <?php if ($activeTab == 'profile'): ?>
                    <!-- Overview Tab -->
                    <div class="dashboard-stats">
                        <div class="stat-card">
                            <h3><?php echo count($appointments); ?></h3>
                            <p class="mb-0">Total Appointments</p>
                        </div>
                        <div class="stat-card" style="background: linear-gradient(135deg, var(--success-color), #2ecc71);">
                            <h3><?php echo count($labReports); ?></h3>
                            <p class="mb-0">Lab Reports</p>
                        </div>
                        <div class="stat-card" style="background: linear-gradient(135deg, var(--warning-color), #f1c40f);">
                            <h3><?php echo count($bills); ?></h3>
                            <p class="mb-0">Bills</p>
                        </div>
                        <div class="stat-card" style="background: linear-gradient(135deg, var(--danger-color), #e74c3c);">
                            <h3><?php echo count($bloodDonations); ?></h3>
                            <p class="mb-0">Blood Donations</p>
                        </div>
                    </div>

                    <div class="row">
                        <!-- Recent Appointments -->
                        <div class="col-md-6">
                            <div class="info-card">
                                <h5 class="text-primary mb-3"><i class="fas fa-calendar-check me-2"></i>Recent Appointments</h5>
                                <?php if (empty($appointments)): ?>
                                <p class="text-muted">No appointments found</p>
                                <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Doctor</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($appointments, 0, 5) as $appointment): ?>
                                            <tr>
                                                <td><?php echo formatDate($appointment['appointment_date'], 'd M Y'); ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($appointment['doctor_name']); ?></strong>
                                                    <small class="d-block text-muted"><?php echo htmlspecialchars($appointment['specialization']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $appointment['status']; ?>">
                                                        <?php echo ucfirst($appointment['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Medical Team -->
                        <div class="col-md-6">
                            <div class="info-card">
                                <h5 class="text-primary mb-3"><i class="fas fa-user-md me-2"></i>Your Medical Team</h5>
                                <?php if (empty($assignedDoctors)): ?>
                                <p class="text-muted">No assigned doctors found</p>
                                <?php else: ?>
                                <?php foreach (array_slice($assignedDoctors, 0, 3) as $doctor): ?>
                                <div class="team-member">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                            <i class="fas fa-user-md"></i>
                                        </div>
                                        <div>
                                            <strong><?php echo htmlspecialchars($doctor['name']); ?></strong>
                                            <small class="d-block text-muted"><?php echo htmlspecialchars($doctor['specialization']); ?></small>
                                            <small class="d-block text-muted"><?php echo htmlspecialchars($doctor['department_name'] ?? ''); ?></small>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Lab Reports -->
                    <div class="info-card">
                        <h5 class="text-primary mb-3"><i class="fas fa-flask me-2"></i>Recent Lab Reports</h5>
                        <?php if (empty($labReports)): ?>
                        <p class="text-muted">No lab reports found</p>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Request ID</th>
                                        <th>Doctor</th>
                                        <th>Test Results</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($labReports, 0, 5) as $report): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($report['request_id']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($report['doctor_name']); ?></td>
                                        <td><?php echo $report['test_results'] ?: 'Results pending'; ?></td>
                                        <td><?php echo formatDate($report['created_at'], 'd M Y'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php elseif ($activeTab == 'appointments'): ?>
                    <!-- Appointments Tab -->
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Appointment ID</th>
                                        <th>Doctor</th>
                                        <th>Date & Time</th>
                                        <th>Reason</th>
                                        <th>Status</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($appointments)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4">
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
                                            <?php if ($appointment['consultation_notes']): ?>
                                            <small><?php echo htmlspecialchars($appointment['consultation_notes']); ?></small>
                                            <?php else: ?>
                                            <small class="text-muted">No notes</small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php elseif ($activeTab == 'lab-reports'): ?>
                    <!-- Lab Reports Tab -->
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Request ID</th>
                                        <th>Doctor</th>
                                        <th>Priority</th>
                                        <th>Test Results</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($labReports)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4">
                                            <i class="fas fa-flask fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">No lab reports found</h5>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($labReports as $report): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($report['request_id']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($report['doctor_name']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $report['priority'] == 'urgent' ? 'danger' : ($report['priority'] == 'high' ? 'warning' : 'info'); ?>">
                                                <?php echo ucfirst($report['priority']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $report['test_results'] ?: '<span class="text-muted">Results pending</span>'; ?></td>
                                        <td><?php echo formatDate($report['created_at'], 'd M Y H:i'); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $report['status']; ?>">
                                                <?php echo ucfirst($report['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php elseif ($activeTab == 'bills'): ?>
                    <!-- Bills Tab -->
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Bill Number</th>
                                        <th>Date</th>
                                        <th>Total Amount</th>
                                        <th>Paid Amount</th>
                                        <th>Balance</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($bills)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4">
                                            <i class="fas fa-file-invoice fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">No bills found</h5>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($bills as $bill): ?>
                                    <?php 
                                    $totalPaid = $bill['total_paid'] ?? 0;
                                    $balance = $bill['total_amount'] - $totalPaid;
                                    $status = $balance <= 0 ? 'paid' : ($bill['status'] ?? 'pending');
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($bill['bill_number']); ?></strong></td>
                                        <td><?php echo formatDate($bill['created_at'], 'd M Y'); ?></td>
                                        <td><?php echo formatCurrency($bill['total_amount']); ?></td>
                                        <td><?php echo formatCurrency($totalPaid); ?></td>
                                        <td><?php echo formatCurrency($balance); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $status; ?>">
                                                <?php echo ucfirst($status); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php elseif ($activeTab == 'medical-records'): ?>
                    <!-- Medical Records Tab -->
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Doctor</th>
                                        <th>Diagnosis</th>
                                        <th>Treatment</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($medicalRecords)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4">
                                            <i class="fas fa-notes-medical fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">No medical records found</h5>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($medicalRecords as $record): ?>
                                    <tr>
                                        <td><?php echo formatDate($record['created_at'], 'd M Y'); ?></td>
                                        <td><?php echo htmlspecialchars($record['doctor_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($record['diagnosis'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($record['treatment'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($record['notes'] ?? 'N/A'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php elseif ($activeTab == 'blood-donations'): ?>
                    <!-- Blood Donations Tab -->
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Donation Date</th>
                                        <th>Blood Group</th>
                                        <th>Units Donated</th>
                                        <th>Hemoglobin</th>
                                        <th>Status</th>
                                        <th>Next Eligible</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($bloodDonations)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4">
                                            <i class="fas fa-tint fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">No blood donations found</h5>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($bloodDonations as $donation): ?>
                                    <tr>
                                        <td><?php echo formatDate($donation['last_donation_date'] ?? $donation['created_at'], 'd M Y'); ?></td>
                                        <td><strong><?php echo htmlspecialchars($donation['blood_group']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($donation['units_donated'] ?? '1'); ?></td>
                                        <td><?php echo htmlspecialchars($donation['hemoglobin_level'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $donation['status']; ?>">
                                                <?php echo ucfirst($donation['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            if ($donation['last_donation_date']) {
                                                $nextEligible = date('d M Y', strtotime($donation['last_donation_date'] . ' +3 months'));
                                                echo $nextEligible;
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php elseif ($activeTab == 'organ-donations'): ?>
                    <!-- Organ Donations Tab -->
                    <div class="table-container">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Registration Date</th>
                                        <th>Organs to Donate</th>
                                        <th>Consent Status</th>
                                        <th>Emergency Contact</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($organDonations)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4">
                                            <i class="fas fa-heart fa-3x text-muted mb-3"></i>
                                            <h5 class="text-muted">No organ donation registrations found</h5>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($organDonations as $donation): ?>
                                    <tr>
                                        <td><?php echo formatDate($donation['created_at'], 'd M Y'); ?></td>
                                        <td><?php echo htmlspecialchars($donation['organs_to_donate']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $donation['consent_signed'] ? 'success' : 'warning'; ?>">
                                                <?php echo $donation['consent_signed'] ? 'Signed' : 'Pending'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($donation['emergency_contact']); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $donation['status']; ?>">
                                                <?php echo ucfirst($donation['status']); ?>
                                            </span>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>