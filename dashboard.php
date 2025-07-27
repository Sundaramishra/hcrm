<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();

$db = Database::getInstance();
$user_role = $_SESSION['role'];
$user_name = $_SESSION['full_name'];

$stats = [];
$notifications = [];

try {
    // Get role-based statistics
    if (isAdmin()) {
        $stats['total_patients'] = $db->query("SELECT COUNT(*) as count FROM patients WHERE is_active = 1")->fetch()['count'];
        $stats['total_doctors'] = $db->query("SELECT COUNT(*) as count FROM doctors WHERE is_available = 1")->fetch()['count'];
        $stats['total_appointments'] = $db->query("SELECT COUNT(*) as count FROM appointments WHERE appointment_date = CURDATE()")->fetch()['count'];
        $stats['total_revenue'] = $db->query("SELECT COALESCE(SUM(total_amount), 0) as revenue FROM bills WHERE DATE(created_at) = CURDATE()")->fetch()['revenue'];
        $stats['available_beds'] = $db->query("SELECT COUNT(*) as count FROM beds WHERE status = 'Available'")->fetch()['count'];
        $stats['blood_units'] = $db->query("SELECT COALESCE(SUM(units_available), 0) as count FROM blood_inventory WHERE status = 'Available'")->fetch()['count'];
        $stats['pending_bills'] = $db->query("SELECT COUNT(*) as count FROM bills WHERE status IN ('Pending', 'Overdue')")->fetch()['count'];
        $stats['insurance_claims'] = $db->query("SELECT COUNT(*) as count FROM insurance_claims WHERE status = 'Submitted'")->fetch()['count'];
        
        // Recent activities for admin
        $notifications = $db->query("
            SELECT CONCAT(u.first_name, ' ', u.last_name) as user, action, details, created_at 
            FROM activity_logs a 
            JOIN users u ON a.user_id = u.id 
            ORDER BY created_at DESC LIMIT 5
        ")->fetchAll();
        
    } elseif (isDoctor()) {
        $doctorId = $db->query("SELECT id FROM doctors WHERE user_id = ?", [getCurrentUserId()])->fetch()['id'] ?? 0;
        
        $stats['my_appointments'] = $db->query("SELECT COUNT(*) as count FROM appointments WHERE doctor_id = ? AND appointment_date = CURDATE()", [$doctorId])->fetch()['count'];
        $stats['my_patients'] = $db->query("SELECT COUNT(DISTINCT patient_id) as count FROM appointments WHERE doctor_id = ?", [$doctorId])->fetch()['count'];
        $stats['pending_prescriptions'] = $db->query("SELECT COUNT(*) as count FROM prescriptions WHERE doctor_id = ? AND status = 'Pending'", [$doctorId])->fetch()['count'];
        $stats['lab_requests'] = $db->query("SELECT COUNT(*) as count FROM lab_requests WHERE doctor_id = ? AND status IN ('Pending', 'In Progress')", [$doctorId])->fetch()['count'];
        
    } elseif (isNurse()) {
        $stats['total_patients'] = $db->query("SELECT COUNT(*) as count FROM patients WHERE is_active = 1")->fetch()['count'];
        $stats['occupied_beds'] = $db->query("SELECT COUNT(*) as count FROM beds WHERE status = 'Occupied'")->fetch()['count'];
        $stats['available_beds'] = $db->query("SELECT COUNT(*) as count FROM beds WHERE status = 'Available'")->fetch()['count'];
        $stats['critical_patients'] = $db->query("SELECT COUNT(*) as count FROM bed_assignments ba JOIN beds b ON ba.bed_id = b.id WHERE ba.is_active = 1 AND b.bed_type = 'ICU'")->fetch()['count'];
        
    } elseif (isReceptionist()) {
        $stats['today_appointments'] = $db->query("SELECT COUNT(*) as count FROM appointments WHERE appointment_date = CURDATE()")->fetch()['count'];
        $stats['pending_appointments'] = $db->query("SELECT COUNT(*) as count FROM appointments WHERE status = 'Scheduled'")->fetch()['count'];
        $stats['total_patients'] = $db->query("SELECT COUNT(*) as count FROM patients WHERE is_active = 1")->fetch()['count'];
        $stats['today_registrations'] = $db->query("SELECT COUNT(*) as count FROM patients WHERE DATE(created_at) = CURDATE()")->fetch()['count'];
        
    } elseif (isAccountant()) {
        $stats['pending_bills'] = $db->query("SELECT COUNT(*) as count FROM bills WHERE status IN ('Pending', 'Overdue')")->fetch()['count'];
        $stats['today_revenue'] = $db->query("SELECT COALESCE(SUM(total_amount), 0) as revenue FROM bills WHERE DATE(created_at) = CURDATE()")->fetch()['revenue'];
        $stats['pending_payments'] = $db->query("SELECT COUNT(*) as count FROM bills WHERE balance_amount > 0")->fetch()['count'];
        $stats['insurance_claims'] = $db->query("SELECT COUNT(*) as count FROM insurance_claims WHERE status = 'Submitted'")->fetch()['count'];
        
    } elseif (isLabTechnician()) {
        $stats['pending_tests'] = $db->query("SELECT COUNT(*) as count FROM lab_requests WHERE status IN ('Pending', 'Sample Collected')")->fetch()['count'];
        $stats['in_progress_tests'] = $db->query("SELECT COUNT(*) as count FROM lab_requests WHERE status = 'In Progress'")->fetch()['count'];
        $stats['completed_today'] = $db->query("SELECT COUNT(*) as count FROM lab_requests WHERE status = 'Completed' AND DATE(updated_at) = CURDATE()")->fetch()['count'];
        $stats['critical_results'] = $db->query("SELECT COUNT(*) as count FROM lab_request_tests WHERE result_status = 'Critical'")->fetch()['count'];
        
    } elseif (isPharmacist()) {
        $stats['pending_prescriptions'] = $db->query("SELECT COUNT(*) as count FROM prescriptions WHERE status = 'Pending'")->fetch()['count'];
        $stats['low_stock_medicines'] = $db->query("SELECT COUNT(*) as count FROM medicines WHERE stock_quantity <= minimum_stock AND is_active = 1")->fetch()['count'];
        $stats['expired_medicines'] = $db->query("SELECT COUNT(*) as count FROM medicines WHERE expiry_date <= CURDATE() AND is_active = 1")->fetch()['count'];
        $stats['today_dispensed'] = $db->query("SELECT COUNT(*) as count FROM prescriptions WHERE status = 'Dispensed' AND DATE(updated_at) = CURDATE()")->fetch()['count'];
    }
    
} catch (Exception $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
}

// Get navigation items based on role
function getNavigationItems($role) {
    $items = [];
    
    if ($role === 'admin') {
        $items = [
            ['Dashboard', 'dashboard.php', 'fas fa-home'],
            ['Patients', 'patients.php', 'fas fa-user-injured'],
            ['Doctors', 'doctors.php', 'fas fa-user-md'],
            ['Appointments', 'appointments.php', 'fas fa-calendar-check'],
            ['Rooms & Beds', 'rooms-beds.php', 'fas fa-bed'],
            ['Pharmacy', 'pharmacy.php', 'fas fa-pills'],
            ['Laboratory', 'laboratory.php', 'fas fa-flask'],
            ['Blood Bank', 'blood-bank.php', 'fas fa-tint'],
            ['Organ Donation', 'organ-donation.php', 'fas fa-heart'],
            ['Insurance', 'insurance.php', 'fas fa-shield-alt'],
            ['Billing', 'billing.php', 'fas fa-file-invoice-dollar'],
            ['Reports', 'reports.php', 'fas fa-chart-bar'],
            ['Staff Management', 'staff.php', 'fas fa-users'],
            ['Settings', 'settings.php', 'fas fa-cog']
        ];
    } elseif ($role === 'doctor') {
        $items = [
            ['Dashboard', 'dashboard.php', 'fas fa-home'],
            ['My Patients', 'my-patients.php', 'fas fa-user-injured'],
            ['Appointments', 'appointments.php', 'fas fa-calendar-check'],
            ['Medical Records', 'medical-records.php', 'fas fa-notes-medical'],
            ['Prescriptions', 'prescriptions.php', 'fas fa-prescription'],
            ['Lab Requests', 'lab-requests.php', 'fas fa-flask'],
            ['Blood Requests', 'blood-requests.php', 'fas fa-tint'],
            ['Organ Recipients', 'organ-recipients.php', 'fas fa-heart']
        ];
    } elseif ($role === 'nurse') {
        $items = [
            ['Dashboard', 'dashboard.php', 'fas fa-home'],
            ['Patients', 'patients.php', 'fas fa-user-injured'],
            ['Appointments', 'appointments.php', 'fas fa-calendar-check'],
            ['Bed Management', 'bed-management.php', 'fas fa-bed'],
            ['Medical Records', 'medical-records.php', 'fas fa-notes-medical'],
            ['Blood Inventory', 'blood-inventory.php', 'fas fa-tint']
        ];
    } elseif ($role === 'receptionist') {
        $items = [
            ['Dashboard', 'dashboard.php', 'fas fa-home'],
            ['Patients', 'patients.php', 'fas fa-user-injured'],
            ['Appointments', 'appointments.php', 'fas fa-calendar-check'],
            ['Billing', 'billing.php', 'fas fa-file-invoice-dollar'],
            ['Payments', 'payments.php', 'fas fa-credit-card']
        ];
    } elseif ($role === 'accountant') {
        $items = [
            ['Dashboard', 'dashboard.php', 'fas fa-home'],
            ['Billing', 'billing.php', 'fas fa-file-invoice-dollar'],
            ['Payments', 'payments.php', 'fas fa-credit-card'],
            ['Insurance Claims', 'insurance-claims.php', 'fas fa-shield-alt'],
            ['Financial Reports', 'financial-reports.php', 'fas fa-chart-line']
        ];
    } elseif ($role === 'lab_technician') {
        $items = [
            ['Dashboard', 'dashboard.php', 'fas fa-home'],
            ['Lab Requests', 'lab-requests.php', 'fas fa-flask'],
            ['Test Results', 'test-results.php', 'fas fa-clipboard-list'],
            ['Lab Tests', 'lab-tests.php', 'fas fa-vial'],
            ['Patients', 'patients.php', 'fas fa-user-injured']
        ];
    } elseif ($role === 'pharmacist') {
        $items = [
            ['Dashboard', 'dashboard.php', 'fas fa-home'],
            ['Medicines', 'medicines.php', 'fas fa-pills'],
            ['Prescriptions', 'prescriptions.php', 'fas fa-prescription'],
            ['Inventory', 'medicine-inventory.php', 'fas fa-boxes'],
            ['Patients', 'patients.php', 'fas fa-user-injured']
        ];
    }
    
    return $items;
}

$navItems = getNavigationItems($user_role);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital Management Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8f9fa; }
        
        .dashboard-container { display: flex; min-height: 100vh; }
        
        .sidebar {
            width: 280px;
            background: linear-gradient(135deg, #004685 0%, #0066cc 100%);
            color: white;
            padding: 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        .sidebar-header {
            padding: 25px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            text-align: center;
        }
        
        .sidebar-header h3 { 
            font-size: 22px; 
            margin-bottom: 5px; 
            font-weight: 300;
        }
        
        .sidebar-header p { 
            font-size: 13px; 
            opacity: 0.8; 
            margin: 0;
        }
        
        .sidebar-menu { 
            list-style: none; 
            padding: 20px 0;
        }
        
        .sidebar-menu li { margin-bottom: 2px; }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 15px 25px;
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }
        
        .sidebar-menu a:hover, .sidebar-menu a.active {
            background: rgba(255,255,255,0.1);
            color: white;
            border-left-color: #64b5f6;
            transform: translateX(5px);
        }
        
        .sidebar-menu a i {
            width: 20px;
            margin-right: 15px;
            font-size: 16px;
        }
        
        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 25px;
            min-height: 100vh;
        }
        
        .dashboard-header {
            background: white;
            padding: 25px 30px;
            border-radius: 15px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .welcome-text h2 {
            color: #2c3e50;
            font-weight: 300;
            margin-bottom: 5px;
        }
        
        .welcome-text p {
            color: #7f8c8d;
            margin: 0;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .role-badge {
            background: linear-gradient(135deg, #004685 0%, #0066cc 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .logout-btn {
            background: #e74c3c;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .logout-btn:hover {
            background: #c0392b;
            transform: translateY(-1px);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.08);
            border-left: 4px solid;
            transition: transform 0.2s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
        }
        
        .stat-card.primary { border-left-color: #3498db; }
        .stat-card.success { border-left-color: #2ecc71; }
        .stat-card.warning { border-left-color: #f39c12; }
        .stat-card.danger { border-left-color: #e74c3c; }
        .stat-card.info { border-left-color: #9b59b6; }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
        }
        
        .stat-icon.primary { background: #3498db; }
        .stat-icon.success { background: #2ecc71; }
        .stat-icon.warning { background: #f39c12; }
        .stat-icon.danger { background: #e74c3c; }
        .stat-icon.info { background: #9b59b6; }
        
        .stat-number {
            font-size: 32px;
            font-weight: 600;
            color: #2c3e50;
            line-height: 1;
        }
        
        .stat-label {
            color: #7f8c8d;
            font-size: 14px;
            margin-top: 5px;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .action-btn {
            background: white;
            padding: 20px;
            border-radius: 12px;
            text-decoration: none;
            color: #2c3e50;
            box-shadow: 0 2px 15px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            text-align: center;
            border: 1px solid #e9ecef;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 25px rgba(0,0,0,0.1);
            color: #004685;
        }
        
        .action-btn i {
            font-size: 24px;
            margin-bottom: 10px;
            color: #004685;
        }
        
        .recent-activity {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.08);
        }
        
        .activity-item {
            padding: 15px 0;
            border-bottom: 1px solid #f8f9fa;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #004685;
        }
        
        .activity-content h6 {
            margin: 0 0 5px 0;
            color: #2c3e50;
            font-size: 14px;
        }
        
        .activity-content p {
            margin: 0;
            color: #7f8c8d;
            font-size: 12px;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .dashboard-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <div class="sidebar-header">
                <h3><i class="fas fa-hospital me-2"></i>MediCare</h3>
                <p><?php echo htmlspecialchars($_SESSION['role_display']); ?> Portal</p>
            </div>
            
            <ul class="sidebar-menu">
                <?php foreach ($navItems as $item): ?>
                <li>
                    <a href="<?php echo $item[1]; ?>" 
                       class="<?php echo basename($_SERVER['PHP_SELF']) === $item[1] ? 'active' : ''; ?>">
                        <i class="<?php echo $item[2]; ?>"></i>
                        <?php echo $item[0]; ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        
        <div class="main-content">
            <div class="dashboard-header">
                <div class="welcome-text">
                    <h2>Welcome back, <?php echo htmlspecialchars($user_name); ?>!</h2>
                    <p>Here's what's happening in your hospital today</p>
                </div>
                
                <div class="user-info">
                    <span class="role-badge"><?php echo htmlspecialchars($_SESSION['role_display']); ?></span>
                    <a href="logout.php" class="logout-btn">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                    </a>
                </div>
            </div>
            
            <div class="stats-grid">
                <?php if (isAdmin()): ?>
                    <div class="stat-card primary">
                        <div class="stat-header">
                            <div class="stat-icon primary">
                                <i class="fas fa-user-injured"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['total_patients'] ?? 0); ?></div>
                        <div class="stat-label">Total Patients</div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-header">
                            <div class="stat-icon success">
                                <i class="fas fa-user-md"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['total_doctors'] ?? 0); ?></div>
                        <div class="stat-label">Active Doctors</div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-header">
                            <div class="stat-icon warning">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['total_appointments'] ?? 0); ?></div>
                        <div class="stat-label">Today's Appointments</div>
                    </div>
                    <div class="stat-card info">
                        <div class="stat-header">
                            <div class="stat-icon info">
                                <i class="fas fa-rupee-sign"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo formatCurrency($stats['total_revenue'] ?? 0); ?></div>
                        <div class="stat-label">Today's Revenue</div>
                    </div>
                    <div class="stat-card primary">
                        <div class="stat-header">
                            <div class="stat-icon primary">
                                <i class="fas fa-bed"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['available_beds'] ?? 0); ?></div>
                        <div class="stat-label">Available Beds</div>
                    </div>
                    <div class="stat-card danger">
                        <div class="stat-header">
                            <div class="stat-icon danger">
                                <i class="fas fa-tint"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['blood_units'] ?? 0); ?></div>
                        <div class="stat-label">Blood Units Available</div>
                    </div>
                    
                <?php elseif (isDoctor()): ?>
                    <div class="stat-card primary">
                        <div class="stat-header">
                            <div class="stat-icon primary">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['my_appointments'] ?? 0); ?></div>
                        <div class="stat-label">Today's Appointments</div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-header">
                            <div class="stat-icon success">
                                <i class="fas fa-user-injured"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['my_patients'] ?? 0); ?></div>
                        <div class="stat-label">My Patients</div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-header">
                            <div class="stat-icon warning">
                                <i class="fas fa-prescription"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['pending_prescriptions'] ?? 0); ?></div>
                        <div class="stat-label">Pending Prescriptions</div>
                    </div>
                    <div class="stat-card info">
                        <div class="stat-header">
                            <div class="stat-icon info">
                                <i class="fas fa-flask"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['lab_requests'] ?? 0); ?></div>
                        <div class="stat-label">Lab Requests</div>
                    </div>
                    
                <?php elseif (isNurse()): ?>
                    <div class="stat-card primary">
                        <div class="stat-header">
                            <div class="stat-icon primary">
                                <i class="fas fa-user-injured"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['total_patients'] ?? 0); ?></div>
                        <div class="stat-label">Total Patients</div>
                    </div>
                    <div class="stat-card danger">
                        <div class="stat-header">
                            <div class="stat-icon danger">
                                <i class="fas fa-bed"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['occupied_beds'] ?? 0); ?></div>
                        <div class="stat-label">Occupied Beds</div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-header">
                            <div class="stat-icon success">
                                <i class="fas fa-bed"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['available_beds'] ?? 0); ?></div>
                        <div class="stat-label">Available Beds</div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-header">
                            <div class="stat-icon warning">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['critical_patients'] ?? 0); ?></div>
                        <div class="stat-label">ICU Patients</div>
                    </div>
                    
                <?php elseif (isReceptionist()): ?>
                    <div class="stat-card primary">
                        <div class="stat-header">
                            <div class="stat-icon primary">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['today_appointments'] ?? 0); ?></div>
                        <div class="stat-label">Today's Appointments</div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-header">
                            <div class="stat-icon warning">
                                <i class="fas fa-clock"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['pending_appointments'] ?? 0); ?></div>
                        <div class="stat-label">Pending Appointments</div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-header">
                            <div class="stat-icon success">
                                <i class="fas fa-user-injured"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['total_patients'] ?? 0); ?></div>
                        <div class="stat-label">Total Patients</div>
                    </div>
                    <div class="stat-card info">
                        <div class="stat-header">
                            <div class="stat-icon info">
                                <i class="fas fa-user-plus"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['today_registrations'] ?? 0); ?></div>
                        <div class="stat-label">Today's Registrations</div>
                    </div>
                    
                <?php elseif (isAccountant()): ?>
                    <div class="stat-card warning">
                        <div class="stat-header">
                            <div class="stat-icon warning">
                                <i class="fas fa-file-invoice-dollar"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['pending_bills'] ?? 0); ?></div>
                        <div class="stat-label">Pending Bills</div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-header">
                            <div class="stat-icon success">
                                <i class="fas fa-rupee-sign"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo formatCurrency($stats['today_revenue'] ?? 0); ?></div>
                        <div class="stat-label">Today's Revenue</div>
                    </div>
                    <div class="stat-card danger">
                        <div class="stat-header">
                            <div class="stat-icon danger">
                                <i class="fas fa-credit-card"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['pending_payments'] ?? 0); ?></div>
                        <div class="stat-label">Pending Payments</div>
                    </div>
                    <div class="stat-card info">
                        <div class="stat-header">
                            <div class="stat-icon info">
                                <i class="fas fa-shield-alt"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['insurance_claims'] ?? 0); ?></div>
                        <div class="stat-label">Insurance Claims</div>
                    </div>
                    
                <?php elseif (isLabTechnician()): ?>
                    <div class="stat-card warning">
                        <div class="stat-header">
                            <div class="stat-icon warning">
                                <i class="fas fa-flask"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['pending_tests'] ?? 0); ?></div>
                        <div class="stat-label">Pending Tests</div>
                    </div>
                    <div class="stat-card primary">
                        <div class="stat-header">
                            <div class="stat-icon primary">
                                <i class="fas fa-spinner"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['in_progress_tests'] ?? 0); ?></div>
                        <div class="stat-label">In Progress Tests</div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-header">
                            <div class="stat-icon success">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['completed_today'] ?? 0); ?></div>
                        <div class="stat-label">Completed Today</div>
                    </div>
                    <div class="stat-card danger">
                        <div class="stat-header">
                            <div class="stat-icon danger">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['critical_results'] ?? 0); ?></div>
                        <div class="stat-label">Critical Results</div>
                    </div>
                    
                <?php elseif (isPharmacist()): ?>
                    <div class="stat-card primary">
                        <div class="stat-header">
                            <div class="stat-icon primary">
                                <i class="fas fa-prescription"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['pending_prescriptions'] ?? 0); ?></div>
                        <div class="stat-label">Pending Prescriptions</div>
                    </div>
                    <div class="stat-card warning">
                        <div class="stat-header">
                            <div class="stat-icon warning">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['low_stock_medicines'] ?? 0); ?></div>
                        <div class="stat-label">Low Stock Medicines</div>
                    </div>
                    <div class="stat-card danger">
                        <div class="stat-header">
                            <div class="stat-icon danger">
                                <i class="fas fa-calendar-times"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['expired_medicines'] ?? 0); ?></div>
                        <div class="stat-label">Expired Medicines</div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-header">
                            <div class="stat-icon success">
                                <i class="fas fa-check-circle"></i>
                            </div>
                        </div>
                        <div class="stat-number"><?php echo number_format($stats['today_dispensed'] ?? 0); ?></div>
                        <div class="stat-label">Today Dispensed</div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Quick Actions -->
            <div class="quick-actions">
                <?php if (isAdmin() || isReceptionist()): ?>
                <a href="patients.php?action=new" class="action-btn">
                    <i class="fas fa-user-plus"></i>
                    <div>Add New Patient</div>
                </a>
                <?php endif; ?>
                
                <?php if (isAdmin() || isReceptionist() || isDoctor()): ?>
                <a href="appointments.php?action=new" class="action-btn">
                    <i class="fas fa-calendar-plus"></i>
                    <div>Book Appointment</div>
                </a>
                <?php endif; ?>
                
                <?php if (isAdmin() || isAccountant() || isReceptionist()): ?>
                <a href="billing.php?action=new" class="action-btn">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <div>Generate Bill</div>
                </a>
                <?php endif; ?>
                
                <?php if (isAdmin() || isDoctor()): ?>
                <a href="lab-requests.php?action=new" class="action-btn">
                    <i class="fas fa-flask"></i>
                    <div>Lab Request</div>
                </a>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($notifications) && isAdmin()): ?>
            <div class="recent-activity">
                <h5 class="mb-4"><i class="fas fa-clock me-2"></i>Recent Activities</h5>
                <?php foreach ($notifications as $activity): ?>
                <div class="activity-item">
                    <div class="activity-icon">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div class="activity-content">
                        <h6><?php echo htmlspecialchars($activity['user']); ?> - <?php echo htmlspecialchars($activity['action']); ?></h6>
                        <p><?php echo htmlspecialchars($activity['details']); ?> • <?php echo timeAgo($activity['created_at']); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>