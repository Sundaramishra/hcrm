<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();
requireRole(['admin', 'accountant', 'receptionist']);

$db = Database::getInstance();
$message = '';
$error = '';

// Handle form submissions
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_bill') {
        try {
            $db->beginTransaction();
            
            $bill_number = generateBillNumber();
            $patient_id = $_POST['patient_id'];
            $appointment_id = $_POST['appointment_id'] ?: null;
            $bill_date = $_POST['bill_date'];
            $due_date = $_POST['due_date'];
            $payment_terms = sanitizeInput($_POST['payment_terms']);
            $notes = sanitizeInput($_POST['notes']);
            $discount_amount = (float)($_POST['discount_amount'] ?? 0);
            $tax_rate = (float)($_POST['tax_rate'] ?? 0);
            
            // Calculate totals
            $subtotal = 0;
            $services = $_POST['services'] ?? [];
            $quantities = $_POST['quantities'] ?? [];
            $unit_prices = $_POST['unit_prices'] ?? [];
            
            // Insert bill
            $billId = $db->query("
                INSERT INTO bills (
                    bill_number, patient_id, appointment_id, bill_date, due_date,
                    subtotal, tax_amount, discount_amount, total_amount, balance_amount,
                    payment_terms, notes, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $bill_number, $patient_id, $appointment_id, $bill_date, $due_date,
                0, 0, $discount_amount, 0, 0, $payment_terms, $notes, getCurrentUserId()
            ]);
            $billId = $db->lastInsertId();
            
            // Add bill items
            foreach ($services as $index => $service_id) {
                if (!empty($service_id) && !empty($quantities[$index]) && !empty($unit_prices[$index])) {
                    $quantity = (int)$quantities[$index];
                    $unit_price = (float)$unit_prices[$index];
                    $total_price = $quantity * $unit_price;
                    $subtotal += $total_price;
                    
                    // Get service details
                    $service = $db->query("SELECT service_name FROM services WHERE id = ?", [$service_id])->fetch();
                    $item_name = $service['service_name'] ?? 'Service';
                    
                    $db->query("
                        INSERT INTO bill_items (bill_id, service_id, item_name, quantity, unit_price, total_price)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ", [$billId, $service_id, $item_name, $quantity, $unit_price, $total_price]);
                }
            }
            
            // Update bill totals
            $tax_amount = ($subtotal * $tax_rate) / 100;
            $total_amount = $subtotal + $tax_amount - $discount_amount;
            
            $db->query("
                UPDATE bills SET 
                    subtotal = ?, tax_amount = ?, total_amount = ?, balance_amount = ?
                WHERE id = ?
            ", [$subtotal, $tax_amount, $total_amount, $total_amount, $billId]);
            
            $db->commit();
            
            logActivity(getCurrentUserId(), 'Bill Created', "Created bill: $bill_number for patient ID: $patient_id");
            $message = "Bill created successfully!";
            
        } catch (Exception $e) {
            $db->rollback();
            $error = "Error creating bill: " . $e->getMessage();
        }
    }
    
    if ($action === 'record_payment') {
        try {
            $bill_id = $_POST['bill_id'];
            $amount = (float)$_POST['amount'];
            $payment_method = $_POST['payment_method'];
            $payment_date = $_POST['payment_date'];
            $transaction_id = sanitizeInput($_POST['transaction_id']);
            $reference_number = sanitizeInput($_POST['reference_number']);
            $notes = sanitizeInput($_POST['notes']);
            
            $payment_id = 'PAY' . date('Y') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            // Insert payment
            $db->query("
                INSERT INTO payments (
                    payment_id, bill_id, amount, payment_method, payment_date,
                    transaction_id, reference_number, notes, received_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $payment_id, $bill_id, $amount, $payment_method, $payment_date,
                $transaction_id, $reference_number, $notes, getCurrentUserId()
            ]);
            
            // Update bill balance
            $bill = $db->query("SELECT total_amount, paid_amount FROM bills WHERE id = ?", [$bill_id])->fetch();
            $new_paid_amount = $bill['paid_amount'] + $amount;
            $new_balance = $bill['total_amount'] - $new_paid_amount;
            $new_status = $new_balance <= 0 ? 'Paid' : 'Partially Paid';
            
            $db->query("
                UPDATE bills SET 
                    paid_amount = ?, balance_amount = ?, status = ?
                WHERE id = ?
            ", [$new_paid_amount, $new_balance, $new_status, $bill_id]);
            
            logActivity(getCurrentUserId(), 'Payment Recorded', "Recorded payment: $payment_id for bill ID: $bill_id");
            $message = "Payment recorded successfully!";
            
        } catch (Exception $e) {
            $error = "Error recording payment: " . $e->getMessage();
        }
    }
}

// Get bills with search and pagination
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$page = (int)($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

$searchCondition = '';
$searchParams = [];

if ($search) {
    $searchCondition .= " AND (b.bill_number LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR p.patient_id LIKE ?)";
    $searchTerm = "%$search%";
    $searchParams = array_merge($searchParams, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

if ($status_filter) {
    $searchCondition .= " AND b.status = ?";
    $searchParams[] = $status_filter;
}

$bills = $db->query("
    SELECT b.*, 
           CONCAT(p.first_name, ' ', p.last_name) as patient_name,
           p.patient_id,
           p.phone as patient_phone
    FROM bills b
    JOIN patients p ON b.patient_id = p.id
    WHERE 1=1 $searchCondition
    ORDER BY b.created_at DESC 
    LIMIT $limit OFFSET $offset
", $searchParams)->fetchAll();

$totalBills = $db->query("
    SELECT COUNT(*) as count FROM bills b
    JOIN patients p ON b.patient_id = p.id
    WHERE 1=1 $searchCondition
", $searchParams)->fetch()['count'];

$totalPages = ceil($totalBills / $limit);

// Get services for dropdown
$services = $db->query("SELECT * FROM services WHERE is_active = 1 ORDER BY service_name")->fetchAll();

// Get patients for dropdown
$patients = $db->query("SELECT id, patient_id, first_name, last_name FROM patients WHERE is_active = 1 ORDER BY first_name")->fetchAll();

// Get bill for payment
$paymentBill = null;
if (isset($_GET['pay'])) {
    $paymentBill = $db->query("
        SELECT b.*, CONCAT(p.first_name, ' ', p.last_name) as patient_name
        FROM bills b
        JOIN patients p ON b.patient_id = p.id
        WHERE b.id = ?
    ", [$_GET['pay']])->fetch();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing Management - Hospital System</title>
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
        .bill-card { background: white; border-radius: 10px; padding: 1.5rem; margin-bottom: 1rem; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .bill-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .bill-number { font-size: 1.2rem; font-weight: 600; color: #2c3e50; }
        .bill-amount { font-size: 1.5rem; font-weight: 700; }
        .status-pending { color: #f39c12; }
        .status-paid { color: #27ae60; }
        .status-overdue { color: #e74c3c; }
        .status-partially-paid { color: #3498db; }
        .item-row { border-bottom: 1px solid #eee; padding: 0.5rem 0; }
        .item-row:last-child { border-bottom: none; }
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
                <h2><i class="fas fa-file-invoice-dollar text-primary me-2"></i>Billing Management</h2>
                <p class="text-muted">Manage invoices, bills and payments</p>
            </div>
            <div>
                <a href="dashboard.php" class="btn btn-outline-secondary me-2">
                    <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
                </a>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#billModal">
                    <i class="fas fa-plus me-2"></i>Create New Bill
                </button>
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
                        <i class="fas fa-file-invoice fa-2x text-primary mb-2"></i>
                        <h4><?php echo $db->query("SELECT COUNT(*) as count FROM bills")->fetch()['count']; ?></h4>
                        <p class="text-muted mb-0">Total Bills</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-hourglass-half fa-2x text-warning mb-2"></i>
                        <h4><?php echo $db->query("SELECT COUNT(*) as count FROM bills WHERE status = 'Pending'")->fetch()['count']; ?></h4>
                        <p class="text-muted mb-0">Pending Bills</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-rupee-sign fa-2x text-success mb-2"></i>
                        <h4><?php echo formatCurrency($db->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM bills WHERE DATE(created_at) = CURDATE()")->fetch()['total']); ?></h4>
                        <p class="text-muted mb-0">Today's Revenue</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-exclamation-triangle fa-2x text-danger mb-2"></i>
                        <h4><?php echo $db->query("SELECT COUNT(*) as count FROM bills WHERE status = 'Overdue'")->fetch()['count']; ?></h4>
                        <p class="text-muted mb-0">Overdue Bills</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search and Filter -->
        <div class="search-box">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search by bill number, patient name..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <?php foreach (getBillStatuses() as $status): ?>
                        <option value="<?php echo $status; ?>" <?php echo $status_filter === $status ? 'selected' : ''; ?>>
                            <?php echo $status; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
                <div class="col-md-3 text-end">
                    <a href="reports.php?type=billing" class="btn btn-outline-primary">
                        <i class="fas fa-chart-bar me-1"></i>View Reports
                    </a>
                </div>
            </form>
        </div>

        <!-- Bills List -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Bills List
                    <span class="badge bg-light text-dark ms-2"><?php echo number_format($totalBills); ?> bills</span>
                </h5>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($bills)): ?>
                    <?php foreach ($bills as $bill): ?>
                    <div class="bill-card">
                        <div class="bill-header">
                            <div>
                                <div class="bill-number"><?php echo htmlspecialchars($bill['bill_number']); ?></div>
                                <div class="text-muted">
                                    <?php echo htmlspecialchars($bill['patient_name']); ?> 
                                    (<?php echo htmlspecialchars($bill['patient_id']); ?>)
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="bill-amount status-<?php echo strtolower(str_replace(' ', '-', $bill['status'])); ?>">
                                    <?php echo formatCurrency($bill['total_amount']); ?>
                                </div>
                                <span class="badge bg-<?php 
                                    echo $bill['status'] === 'Paid' ? 'success' : 
                                        ($bill['status'] === 'Pending' ? 'warning' : 
                                        ($bill['status'] === 'Overdue' ? 'danger' : 'info')); 
                                ?>">
                                    <?php echo $bill['status']; ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <small class="text-muted">
                                    <i class="fas fa-calendar me-1"></i>Bill Date: <?php echo formatDate($bill['bill_date'], 'd M Y'); ?>
                                </small><br>
                                <small class="text-muted">
                                    <i class="fas fa-calendar-times me-1"></i>Due Date: <?php echo formatDate($bill['due_date'], 'd M Y'); ?>
                                </small>
                            </div>
                            <div class="col-md-6 text-end">
                                <small class="text-muted">
                                    Paid: <?php echo formatCurrency($bill['paid_amount']); ?> | 
                                    Balance: <?php echo formatCurrency($bill['balance_amount']); ?>
                                </small>
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <a href="bill-details.php?id=<?php echo $bill['id']; ?>" class="btn btn-sm btn-outline-primary me-1">
                                <i class="fas fa-eye"></i> View
                            </a>
                            <a href="print-bill.php?id=<?php echo $bill['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary me-1">
                                <i class="fas fa-print"></i> Print
                            </a>
                            <?php if ($bill['balance_amount'] > 0): ?>
                            <a href="?pay=<?php echo $bill['id']; ?>" class="btn btn-sm btn-success me-1">
                                <i class="fas fa-credit-card"></i> Record Payment
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="d-flex justify-content-center mt-4">
                        <?php echo getPagination($page, $totalPages, "?search=" . urlencode($search) . "&status=" . urlencode($status_filter)); ?>
                    </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-file-invoice-dollar fa-3x text-muted mb-3"></i>
                    <h5>No bills found</h5>
                    <p class="text-muted">Try adjusting your search criteria or create a new bill.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Create Bill Modal -->
    <div class="modal fade" id="billModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus me-2"></i>Create New Bill
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="billForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create_bill">
                        
                        <div class="row g-3 mb-4">
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
                                <label class="form-label">Appointment (Optional)</label>
                                <select name="appointment_id" class="form-select">
                                    <option value="">Select Appointment</option>
                                    <!-- Appointments will be loaded via AJAX based on patient selection -->
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Bill Date <span class="text-danger">*</span></label>
                                <input type="date" name="bill_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Due Date <span class="text-danger">*</span></label>
                                <input type="date" name="due_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Payment Terms</label>
                                <input type="text" name="payment_terms" class="form-control" value="Net 30 days">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tax Rate (%)</label>
                                <input type="number" name="tax_rate" class="form-control" value="0" min="0" max="100" step="0.01">
                            </div>
                        </div>

                        <!-- Services Section -->
                        <h6 class="mb-3">Bill Items</h6>
                        <div id="billItems">
                            <div class="item-row">
                                <div class="row g-2">
                                    <div class="col-md-5">
                                        <select name="services[]" class="form-select" required>
                                            <option value="">Select Service</option>
                                            <?php foreach ($services as $service): ?>
                                            <option value="<?php echo $service['id']; ?>" data-price="<?php echo $service['price']; ?>">
                                                <?php echo htmlspecialchars($service['service_name'] . ' - ' . formatCurrency($service['price'])); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <input type="number" name="quantities[]" class="form-control" placeholder="Qty" value="1" min="1" required>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="number" name="unit_prices[]" class="form-control" placeholder="Unit Price" step="0.01" required>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="button" class="btn btn-outline-danger" onclick="removeItem(this)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <button type="button" class="btn btn-outline-primary mt-2" onclick="addItem()">
                            <i class="fas fa-plus me-1"></i>Add Item
                        </button>
                        
                        <div class="row g-3 mt-4">
                            <div class="col-md-6">
                                <label class="form-label">Discount Amount</label>
                                <input type="number" name="discount_amount" class="form-control" value="0" min="0" step="0.01">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Create Bill
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Payment Modal -->
    <?php if ($paymentBill): ?>
    <div class="modal fade" id="paymentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-credit-card me-2"></i>Record Payment
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="record_payment">
                        <input type="hidden" name="bill_id" value="<?php echo $paymentBill['id']; ?>">
                        
                        <div class="mb-3">
                            <strong>Bill: <?php echo htmlspecialchars($paymentBill['bill_number']); ?></strong><br>
                            <span class="text-muted">Patient: <?php echo htmlspecialchars($paymentBill['patient_name']); ?></span><br>
                            <span class="text-muted">Total Amount: <?php echo formatCurrency($paymentBill['total_amount']); ?></span><br>
                            <span class="text-muted">Balance: <?php echo formatCurrency($paymentBill['balance_amount']); ?></span>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Payment Amount <span class="text-danger">*</span></label>
                                <input type="number" name="amount" class="form-control" max="<?php echo $paymentBill['balance_amount']; ?>" step="0.01" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                                <select name="payment_method" class="form-select" required>
                                    <option value="">Select Method</option>
                                    <?php foreach (getPaymentMethods() as $method): ?>
                                    <option value="<?php echo $method; ?>"><?php echo $method; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                                <input type="date" name="payment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Transaction ID</label>
                                <input type="text" name="transaction_id" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Reference Number</label>
                                <input type="text" name="reference_number" class="form-control">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save me-2"></i>Record Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function addItem() {
            const billItems = document.getElementById('billItems');
            const newItem = document.createElement('div');
            newItem.className = 'item-row';
            newItem.innerHTML = `
                <div class="row g-2">
                    <div class="col-md-5">
                        <select name="services[]" class="form-select" required>
                            <option value="">Select Service</option>
                            <?php foreach ($services as $service): ?>
                            <option value="<?php echo $service['id']; ?>" data-price="<?php echo $service['price']; ?>">
                                <?php echo htmlspecialchars($service['service_name'] . ' - ' . formatCurrency($service['price'])); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="number" name="quantities[]" class="form-control" placeholder="Qty" value="1" min="1" required>
                    </div>
                    <div class="col-md-3">
                        <input type="number" name="unit_prices[]" class="form-control" placeholder="Unit Price" step="0.01" required>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-outline-danger" onclick="removeItem(this)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `;
            billItems.appendChild(newItem);
            
            // Add event listener for service selection
            const serviceSelect = newItem.querySelector('select[name="services[]"]');
            const priceInput = newItem.querySelector('input[name="unit_prices[]"]');
            
            serviceSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const price = selectedOption.getAttribute('data-price');
                if (price) {
                    priceInput.value = price;
                }
            });
        }
        
        function removeItem(button) {
            button.closest('.item-row').remove();
        }
        
        // Add event listeners for existing items
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('select[name="services[]"]').forEach(select => {
                const priceInput = select.closest('.item-row').querySelector('input[name="unit_prices[]"]');
                
                select.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    const price = selectedOption.getAttribute('data-price');
                    if (price) {
                        priceInput.value = price;
                    }
                });
            });
        });

        // Show payment modal if needed
        <?php if ($paymentBill): ?>
        document.addEventListener('DOMContentLoaded', function() {
            new bootstrap.Modal(document.getElementById('paymentModal')).show();
        });
        <?php endif; ?>
    </script>
</body>
</html>