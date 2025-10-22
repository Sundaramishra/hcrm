<?php
session_start();
require_once 'config/database.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error_message = '';

if ($_POST) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if ($email && $password) {
        try {
            $db = Database::getInstance();
            $stmt = $db->query(
                "SELECT u.*, r.role_name, r.role_display_name, r.permissions 
                 FROM users u 
                 JOIN roles r ON u.role_id = r.id 
                 WHERE u.email = ? AND u.is_active = 1", 
                [$email]
            );
            
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role_name'];
                $_SESSION['role_display'] = $user['role_display_name'];
                $_SESSION['full_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['permissions'] = json_decode($user['permissions'], true);
                
                // Update last login
                $db->query("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);
                
                // Log activity
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $db->query(
                    "INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)",
                    [$user['id'], 'Login', 'User logged in successfully', $ip, $userAgent]
                );
                
                header('Location: dashboard.php');
                exit;
            } else {
                $error_message = 'Invalid email or password!';
            }
        } catch (Exception $e) {
            $error_message = 'Login failed. Please try again.';
            error_log("Login error: " . $e->getMessage());
        }
    } else {
        $error_message = 'Please fill all fields!';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital Management System - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
            overflow: hidden;
            width: 100%;
            max-width: 400px;
            margin: 20px;
        }
        
        .login-header {
            background: linear-gradient(135deg, #004685 0%, #0066cc 100%);
            color: white;
            text-align: center;
            padding: 40px 20px 30px;
        }
        
        .login-header i {
            font-size: 60px;
            margin-bottom: 15px;
            opacity: 0.9;
        }
        
        .login-header h2 {
            font-size: 28px;
            font-weight: 300;
            margin-bottom: 5px;
        }
        
        .login-header p {
            font-size: 14px;
            opacity: 0.8;
        }
        
        .login-form {
            padding: 40px 30px;
        }
        
        .form-group {
            margin-bottom: 25px;
            position: relative;
        }
        
        .form-group label {
            color: #555;
            font-weight: 500;
            margin-bottom: 8px;
            display: block;
        }
        
        .form-control {
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            padding: 12px 45px 12px 15px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }
        
        .form-control:focus {
            border-color: #004685;
            box-shadow: 0 0 0 0.2rem rgba(0,70,133,0.15);
            background: white;
        }
        
        .input-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 18px;
        }
        
        .btn-login {
            background: linear-gradient(135deg, #004685 0%, #0066cc 100%);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-size: 16px;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,70,133,0.4);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            margin-bottom: 20px;
        }
        
        .alert-danger {
            background: #fee;
            color: #c33;
        }
        
        .demo-credentials {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-top: 20px;
            border-left: 4px solid #004685;
        }
        
        .demo-credentials h6 {
            color: #004685;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        .demo-credentials p {
            margin: 5px 0;
            font-size: 13px;
            color: #666;
        }
        
        .demo-credentials strong {
            color: #004685;
        }
        
        @media (max-width: 480px) {
            .login-container {
                margin: 10px;
                border-radius: 15px;
            }
            
            .login-form {
                padding: 30px 20px;
            }
            
            .login-header {
                padding: 30px 20px 25px;
            }
            
            .login-header h2 {
                font-size: 24px;
            }
        }
        
        .loading {
            display: none;
        }
        
        .btn-login.loading {
            pointer-events: none;
        }
        
        .btn-login.loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            margin: auto;
            border: 2px solid transparent;
            border-top-color: #ffffff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }
        
        @keyframes spin {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <i class="fas fa-hospital"></i>
            <h2>Hospital Management</h2>
            <p>Secure Healthcare Portal</p>
        </div>
        
        <div class="login-form">
            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" id="loginForm">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" class="form-control" id="email" name="email" 
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                    <i class="fas fa-envelope input-icon"></i>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                    <i class="fas fa-lock input-icon"></i>
                </div>
                
                <button type="submit" class="btn btn-login" id="loginBtn">
                    <span class="btn-text">Sign In</span>
                </button>
            </form>
            
            <div class="demo-credentials">
                <h6><i class="fas fa-info-circle me-2"></i>Demo Credentials</h6>
                <p><strong>Admin:</strong> admin@hospital.com / admin123</p>
                <p><strong>Role:</strong> Full system access</p>
                <p class="mb-0 mt-2"><small><i class="fas fa-shield-alt me-1"></i>All user roles have specific dashboard access based on permissions</small></p>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            const btnText = btn.querySelector('.btn-text');
            
            btn.classList.add('loading');
            btnText.style.opacity = '0';
            
            // Re-enable if form validation fails
            setTimeout(() => {
                if (!this.checkValidity()) {
                    btn.classList.remove('loading');
                    btnText.style.opacity = '1';
                }
            }, 100);
        });
        
        // Auto-focus first empty field
        document.addEventListener('DOMContentLoaded', function() {
            const emailField = document.getElementById('email');
            const passwordField = document.getElementById('password');
            
            if (!emailField.value) {
                emailField.focus();
            } else {
                passwordField.focus();
            }
        });
        
        // Show/hide password
        document.querySelector('.fa-lock').addEventListener('click', function() {
            const passwordField = document.getElementById('password');
            const icon = this;
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                icon.classList.remove('fa-lock');
                icon.classList.add('fa-lock-open');
            } else {
                passwordField.type = 'password';
                icon.classList.remove('fa-lock-open');
                icon.classList.add('fa-lock');
            }
        });
        
        // Input animations
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.classList.add('focused');
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.classList.remove('focused');
            });
        });
    </script>
</body>
</html>