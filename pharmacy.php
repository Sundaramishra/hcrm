<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();
requireRole(['admin', 'pharmacist', 'doctor']);

$db = Database::getInstance();
$message = '';
$error = '';

// Handle form submissions
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_medicine') {
        try {
            $name = sanitizeInput($_POST['name']);
            $generic_name = sanitizeInput($_POST['generic_name']);
            $manufacturer = sanitizeInput($_POST['manufacturer']);
            $category = sanitizeInput($_POST['category']);
            $strength = sanitizeInput($_POST['strength']);
            $unit = sanitizeInput($_POST['unit']);
            $purchase_price = (float)$_POST['purchase_price'];
            $selling_price = (float)$_POST['selling_price'];
            $stock_quantity = (int)$_POST['stock_quantity'];
            $minimum_stock = (int)$_POST['minimum_stock'];
            $expiry_date = $_POST['expiry_date'];
            $batch_number = sanitizeInput($_POST['batch_number']);
            $description = sanitizeInput($_POST['description']);
            
            $db->query("
                INSERT INTO medicines (
                    name, generic_name, manufacturer, category, strength, unit,
                    purchase_price, selling_price, stock_quantity, minimum_stock,
                    expiry_date, batch_number, description
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $name, $generic_name, $manufacturer, $category, $strength, $unit,
                $purchase_price, $selling_price, $stock_quantity, $minimum_stock,
                $expiry_date, $batch_number, $description
            ]);
            
            logActivity(getCurrentUserId(), 'Medicine Added', "Added medicine: $name");
            $message = "Medicine added successfully!";
            
        } catch (Exception $e) {
            $error = "Error adding medicine: " . $e->getMessage();
        }
    }
    
    if ($action === 'update_medicine') {
        try {
            $id = $_POST['medicine_id'];
            $name = sanitizeInput($_POST['name']);
            $generic_name = sanitizeInput($_POST['generic_name']);
            $manufacturer = sanitizeInput($_POST['manufacturer']);
            $category = sanitizeInput($_POST['category']);
            $strength = sanitizeInput($_POST['strength']);
            $unit = sanitizeInput($_POST['unit']);
            $purchase_price = (float)$_POST['purchase_price'];
            $selling_price = (float)$_POST['selling_price'];
            $stock_quantity = (int)$_POST['stock_quantity'];
            $minimum_stock = (int)$_POST['minimum_stock'];
            $expiry_date = $_POST['expiry_date'];
            $batch_number = sanitizeInput($_POST['batch_number']);
            $description = sanitizeInput($_POST['description']);
            
            $db->query("
                UPDATE medicines SET 
                    name = ?, generic_name = ?, manufacturer = ?, category = ?, strength = ?, unit = ?,
                    purchase_price = ?, selling_price = ?, stock_quantity = ?, minimum_stock = ?,
                    expiry_date = ?, batch_number = ?, description = ?, updated_at = NOW()
                WHERE id = ?
            ", [
                $name, $generic_name, $manufacturer, $category, $strength, $unit,
                $purchase_price, $selling_price, $stock_quantity, $minimum_stock,
                $expiry_date, $batch_number, $description, $id
            ]);
            
            logActivity(getCurrentUserId(), 'Medicine Updated', "Updated medicine: $name");
            $message = "Medicine updated successfully!";
            
        } catch (Exception $e) {
            $error = "Error updating medicine: " . $e->getMessage();
        }
    }
    
    if ($action === 'update_stock') {
        try {
            $medicine_id = $_POST['medicine_id'];
            $quantity_change = (int)$_POST['quantity_change'];
            $operation = $_POST['operation']; // 'add' or 'subtract'
            $notes = sanitizeInput($_POST['notes']);
            
            $medicine = $db->query("SELECT name, stock_quantity FROM medicines WHERE id = ?", [$medicine_id])->fetch();
            
            if ($operation === 'add') {
                $new_quantity = $medicine['stock_quantity'] + $quantity_change;
            } else {
                $new_quantity = max(0, $medicine['stock_quantity'] - $quantity_change);
            }
            
            $db->query("UPDATE medicines SET stock_quantity = ? WHERE id = ?", [$new_quantity, $medicine_id]);
            
            logActivity(getCurrentUserId(), 'Stock Updated', "Updated stock for {$medicine['name']}: $operation $quantity_change units");
            $message = "Stock updated successfully!";
            
        } catch (Exception $e) {
            $error = "Error updating stock: " . $e->getMessage();
        }
    }
}

// Get medicines with search and filters
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';
$stock_filter = $_GET['stock'] ?? '';
$page = (int)($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

$searchCondition = '';
$searchParams = [];

if ($search) {
    $searchCondition .= " AND (name LIKE ? OR generic_name LIKE ? OR manufacturer LIKE ? OR batch_number LIKE ?)";
    $searchTerm = "%$search%";
    $searchParams = array_merge($searchParams, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

if ($category_filter) {
    $searchCondition .= " AND category = ?";
    $searchParams[] = $category_filter;
}

if ($stock_filter === 'low') {
    $searchCondition .= " AND stock_quantity <= minimum_stock";
} elseif ($stock_filter === 'out') {
    $searchCondition .= " AND stock_quantity = 0";
} elseif ($stock_filter === 'expired') {
    $searchCondition .= " AND expiry_date <= CURDATE()";
}

$medicines = $db->query("
    SELECT * FROM medicines 
    WHERE is_active = 1 $searchCondition
    ORDER BY name 
    LIMIT $limit OFFSET $offset
", $searchParams)->fetchAll();

$totalMedicines = $db->query("
    SELECT COUNT(*) as count FROM medicines 
    WHERE is_active = 1 $searchCondition
", $searchParams)->fetch()['count'];

$totalPages = ceil($totalMedicines / $limit);

// Get categories for filter
$categories = $db->query("SELECT DISTINCT category FROM medicines WHERE is_active = 1 AND category IS NOT NULL ORDER BY category")->fetchAll();

// Get medicine for editing
$editMedicine = null;
if (isset($_GET['edit'])) {
    $editMedicine = $db->query("SELECT * FROM medicines WHERE id = ?", [$_GET['edit']])->fetch();
}

// Get medicine for stock update
$stockMedicine = null;
if (isset($_GET['stock'])) {
    $stockMedicine = $db->query("SELECT * FROM medicines WHERE id = ?", [$_GET['stock']])->fetch();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pharmacy Management - Hospital System</title>
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
        .search-box { background: white; border-radius: 10px; padding: 1.5rem; margin-bottom: 2rem; box-shadow: 0 0 10px rgba(0,0,0,0.05); }
        .medicine-card { background: white; border-radius: 10px; padding: 1.5rem; margin-bottom: 1rem; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .medicine-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem; }
        .medicine-name { font-size: 1.2rem; font-weight: 600; color: #2c3e50; }
        .medicine-generic { color: #7f8c8d; font-size: 0.9rem; }
        .medicine-info { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
        .info-item { display: flex; align-items: center; gap: 0.5rem; }
        .info-icon { width: 20px; color: #667eea; }
        .stock-low { color: #f39c12; }
        .stock-out { color: #e74c3c; }
        .stock-normal { color: #27ae60; }
        .expired { background: #fee; border-left: 4px solid #e74c3c; }
        .low-stock { background: #fff8dc; border-left: 4px solid #f39c12; }
        @media (max-width: 768px) {
            .container-fluid { padding: 1rem; }
            .medicine-info { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-pills text-primary me-2"></i>Pharmacy Management</h2>
                <p class="text-muted">Manage medicines, inventory and prescriptions</p>
            </div>
            <div>
                <a href="dashboard.php" class="btn btn-outline-secondary me-2">
                    <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
                </a>
                <?php if (isAdmin() || isPharmacist()): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#medicineModal">
                    <i class="fas fa-plus me-2"></i>Add Medicine
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

        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-pills fa-2x text-primary mb-2"></i>
                        <h4><?php echo $db->query("SELECT COUNT(*) as count FROM medicines WHERE is_active = 1")->fetch()['count']; ?></h4>
                        <p class="text-muted mb-0">Total Medicines</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-exclamation-triangle fa-2x text-warning mb-2"></i>
                        <h4><?php echo $db->query("SELECT COUNT(*) as count FROM medicines WHERE stock_quantity <= minimum_stock AND is_active = 1")->fetch()['count']; ?></h4>
                        <p class="text-muted mb-0">Low Stock</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-calendar-times fa-2x text-danger mb-2"></i>
                        <h4><?php echo $db->query("SELECT COUNT(*) as count FROM medicines WHERE expiry_date <= CURDATE() AND is_active = 1")->fetch()['count']; ?></h4>
                        <p class="text-muted mb-0">Expired</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <i class="fas fa-prescription fa-2x text-success mb-2"></i>
                        <h4><?php echo $db->query("SELECT COUNT(*) as count FROM prescriptions WHERE status = 'Pending'")->fetch()['count']; ?></h4>
                        <p class="text-muted mb-0">Pending Prescriptions</p>
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
                        <input type="text" name="search" class="form-control" placeholder="Search medicines..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-2">
                    <select name="category" class="form-select">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat['category']); ?>" <?php echo $category_filter === $cat['category'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['category']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="stock" class="form-select">
                        <option value="">All Stock</option>
                        <option value="low" <?php echo $stock_filter === 'low' ? 'selected' : ''; ?>>Low Stock</option>
                        <option value="out" <?php echo $stock_filter === 'out' ? 'selected' : ''; ?>>Out of Stock</option>
                        <option value="expired" <?php echo $stock_filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Filter</button>
                </div>
                <div class="col-md-2 text-end">
                    <a href="prescriptions.php" class="btn btn-outline-success w-100">
                        <i class="fas fa-prescription me-1"></i>Prescriptions
                    </a>
                </div>
            </form>
        </div>

        <!-- Medicines List -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Medicine Inventory
                    <span class="badge bg-light text-dark ms-2"><?php echo number_format($totalMedicines); ?> medicines</span>
                </h5>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($medicines)): ?>
                    <?php foreach ($medicines as $medicine): ?>
                    <?php 
                    $isExpired = strtotime($medicine['expiry_date']) <= time();
                    $isLowStock = $medicine['stock_quantity'] <= $medicine['minimum_stock'];
                    $isOutOfStock = $medicine['stock_quantity'] == 0;
                    
                    $cardClass = '';
                    if ($isExpired) $cardClass = 'expired';
                    elseif ($isLowStock) $cardClass = 'low-stock';
                    ?>
                    <div class="medicine-card <?php echo $cardClass; ?>">
                        <div class="medicine-header">
                            <div>
                                <div class="medicine-name"><?php echo htmlspecialchars($medicine['name']); ?></div>
                                <?php if ($medicine['generic_name']): ?>
                                <div class="medicine-generic">Generic: <?php echo htmlspecialchars($medicine['generic_name']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="text-end">
                                <div class="mb-2">
                                    <span class="badge bg-<?php echo $isOutOfStock ? 'danger' : ($isLowStock ? 'warning' : 'success'); ?>">
                                        Stock: <?php echo $medicine['stock_quantity']; ?> <?php echo htmlspecialchars($medicine['unit']); ?>
                                    </span>
                                    <?php if ($isExpired): ?>
                                    <span class="badge bg-danger ms-1">Expired</span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <?php if (isAdmin() || isPharmacist()): ?>
                                    <a href="?edit=<?php echo $medicine['id']; ?>" class="btn btn-sm btn-warning me-1">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="?stock=<?php echo $medicine['id']; ?>" class="btn btn-sm btn-info me-1">
                                        <i class="fas fa-boxes"></i>
                                    </a>
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-outline-primary" onclick="viewMedicine(<?php echo $medicine['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="medicine-info">
                            <div class="info-item">
                                <i class="fas fa-industry info-icon"></i>
                                <span><?php echo htmlspecialchars($medicine['manufacturer']); ?></span>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-tag info-icon"></i>
                                <span><?php echo htmlspecialchars($medicine['category']); ?></span>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-weight info-icon"></i>
                                <span><?php echo htmlspecialchars($medicine['strength']); ?></span>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-rupee-sign info-icon"></i>
                                <span><?php echo formatCurrency($medicine['selling_price']); ?></span>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-calendar info-icon"></i>
                                <span>Exp: <?php echo formatDate($medicine['expiry_date'], 'd M Y'); ?></span>
                            </div>
                            <div class="info-item">
                                <i class="fas fa-barcode info-icon"></i>
                                <span><?php echo htmlspecialchars($medicine['batch_number']); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="d-flex justify-content-center mt-4">
                        <?php echo getPagination($page, $totalPages, "?search=" . urlencode($search) . "&category=" . urlencode($category_filter) . "&stock=" . urlencode($stock_filter)); ?>
                    </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-pills fa-3x text-muted mb-3"></i>
                    <h5>No medicines found</h5>
                    <p class="text-muted">Try adjusting your search criteria or add a new medicine.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Medicine Modal -->
    <div class="modal fade" id="medicineModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus me-2"></i>
                        <?php echo $editMedicine ? 'Edit Medicine' : 'Add New Medicine'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="<?php echo $editMedicine ? 'update_medicine' : 'add_medicine'; ?>">
                        <?php if ($editMedicine): ?>
                        <input type="hidden" name="medicine_id" value="<?php echo $editMedicine['id']; ?>">
                        <?php endif; ?>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Medicine Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" required 
                                       value="<?php echo htmlspecialchars($editMedicine['name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Generic Name</label>
                                <input type="text" name="generic_name" class="form-control" 
                                       value="<?php echo htmlspecialchars($editMedicine['generic_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Manufacturer <span class="text-danger">*</span></label>
                                <input type="text" name="manufacturer" class="form-control" required 
                                       value="<?php echo htmlspecialchars($editMedicine['manufacturer'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Category <span class="text-danger">*</span></label>
                                <input type="text" name="category" class="form-control" required 
                                       value="<?php echo htmlspecialchars($editMedicine['category'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Strength</label>
                                <input type="text" name="strength" class="form-control" 
                                       value="<?php echo htmlspecialchars($editMedicine['strength'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Unit <span class="text-danger">*</span></label>
                                <select name="unit" class="form-select" required>
                                    <option value="">Select Unit</option>
                                    <option value="Tablet" <?php echo ($editMedicine['unit'] ?? '') === 'Tablet' ? 'selected' : ''; ?>>Tablet</option>
                                    <option value="Capsule" <?php echo ($editMedicine['unit'] ?? '') === 'Capsule' ? 'selected' : ''; ?>>Capsule</option>
                                    <option value="Syrup" <?php echo ($editMedicine['unit'] ?? '') === 'Syrup' ? 'selected' : ''; ?>>Syrup</option>
                                    <option value="Injection" <?php echo ($editMedicine['unit'] ?? '') === 'Injection' ? 'selected' : ''; ?>>Injection</option>
                                    <option value="Drops" <?php echo ($editMedicine['unit'] ?? '') === 'Drops' ? 'selected' : ''; ?>>Drops</option>
                                    <option value="Cream" <?php echo ($editMedicine['unit'] ?? '') === 'Cream' ? 'selected' : ''; ?>>Cream</option>
                                    <option value="Ointment" <?php echo ($editMedicine['unit'] ?? '') === 'Ointment' ? 'selected' : ''; ?>>Ointment</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Batch Number</label>
                                <input type="text" name="batch_number" class="form-control" 
                                       value="<?php echo htmlspecialchars($editMedicine['batch_number'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Purchase Price <span class="text-danger">*</span></label>
                                <input type="number" name="purchase_price" class="form-control" step="0.01" required 
                                       value="<?php echo $editMedicine['purchase_price'] ?? ''; ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Selling Price <span class="text-danger">*</span></label>
                                <input type="number" name="selling_price" class="form-control" step="0.01" required 
                                       value="<?php echo $editMedicine['selling_price'] ?? ''; ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Expiry Date <span class="text-danger">*</span></label>
                                <input type="date" name="expiry_date" class="form-control" required 
                                       value="<?php echo $editMedicine['expiry_date'] ?? ''; ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Stock Quantity <span class="text-danger">*</span></label>
                                <input type="number" name="stock_quantity" class="form-control" min="0" required 
                                       value="<?php echo $editMedicine['stock_quantity'] ?? ''; ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Minimum Stock Level <span class="text-danger">*</span></label>
                                <input type="number" name="minimum_stock" class="form-control" min="1" required 
                                       value="<?php echo $editMedicine['minimum_stock'] ?? '10'; ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($editMedicine['description'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>
                            <?php echo $editMedicine ? 'Update Medicine' : 'Add Medicine'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Stock Update Modal -->
    <?php if ($stockMedicine): ?>
    <div class="modal fade" id="stockModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-boxes me-2"></i>Update Stock
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_stock">
                        <input type="hidden" name="medicine_id" value="<?php echo $stockMedicine['id']; ?>">
                        
                        <div class="mb-3">
                            <strong><?php echo htmlspecialchars($stockMedicine['name']); ?></strong><br>
                            <span class="text-muted">Current Stock: <?php echo $stockMedicine['stock_quantity']; ?> <?php echo htmlspecialchars($stockMedicine['unit']); ?></span>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Operation <span class="text-danger">*</span></label>
                                <select name="operation" class="form-select" required>
                                    <option value="">Select Operation</option>
                                    <option value="add">Add Stock</option>
                                    <option value="subtract">Subtract Stock</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Quantity <span class="text-danger">*</span></label>
                                <input type="number" name="quantity_change" class="form-control" min="1" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="2" placeholder="Reason for stock change..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Stock
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function viewMedicine(id) {
            // This would open a detailed view modal
            alert('Medicine details view - ID: ' + id);
        }

        // Show modals if needed
        <?php if ($editMedicine): ?>
        document.addEventListener('DOMContentLoaded', function() {
            new bootstrap.Modal(document.getElementById('medicineModal')).show();
        });
        <?php endif; ?>

        <?php if ($stockMedicine): ?>
        document.addEventListener('DOMContentLoaded', function() {
            new bootstrap.Modal(document.getElementById('stockModal')).show();
        });
        <?php endif; ?>
    </script>
</body>
</html>