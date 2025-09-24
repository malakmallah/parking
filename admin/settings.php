<?php
/**
 * LIU Parking System - Settings (Change Admin Password)
 * Location: admin/settings.php
 */

session_start();

// Require logged-in admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// DB
$db_host = 'localhost';
$db_name = 'parking';
$db_user = 'root';
$db_pass = '';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}

$success_message = '';
$error_message   = '';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $token          = $_POST['csrf_token'] ?? '';
    $current_pass   = $_POST['current_password'] ?? '';
    $new_pass       = $_POST['new_password'] ?? '';
    $confirm_pass   = $_POST['confirm_password'] ?? '';
    $admin_id       = (int)$_SESSION['user_id'];

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error_message = 'Invalid form token. Please try again.';
    } elseif (empty($current_pass) || empty($new_pass) || empty($confirm_pass)) {
        $error_message = 'Please fill in all fields.';
    } elseif ($new_pass !== $confirm_pass) {
        $error_message = 'New password and confirmation do not match.';
    } elseif (strlen($new_pass) < 8) {
        $error_message = 'New password must be at least 8 characters.';
    } else {
        try {
            // Verify current password
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin' AND password_md5 = ?");
            $stmt->execute([$admin_id, md5($current_pass)]);
            $row = $stmt->fetch();

            if (!$row) {
                $error_message = 'Current password is incorrect.';
            } else {
                // Update password
                $stmt = $pdo->prepare("UPDATE users SET password_md5 = ?, updated_at = NOW() WHERE id = ? AND role = 'admin'");
                $stmt->execute([md5($new_pass), $admin_id]);

                $success_message = 'Password updated successfully!';
                // Rotate CSRF token after a successful sensitive action
                $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
            }
        } catch (PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Settings - Change Admin Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSS Files -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-color: #003366;
            --secondary-color: #FFB81C;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color:  #dc3545;
            --light-color:   #f8f9fa;
            --sidebar-width: 280px;
            --header-height: 70px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background:#f5f7fa; color:#333; }

        /* Sidebar */
        .sidebar {
            position: fixed; top:0; left:0; height:100vh; width: var(--sidebar-width);
            background: linear-gradient(135deg, var(--primary-color) 0%, #004080 100%);
            box-shadow: 2px 0 10px rgba(0,0,0,.1); z-index:1000;
        }
        .sidebar-header { padding:20px; text-align:center; border-bottom:1px solid rgba(255,255,255,.1); margin-bottom:20px; }
        .sidebar-header .logo {
            width:50px; height:50px; background: var(--secondary-color);
            border-radius:12px; color:#fff; display:flex; align-items:center; justify-content:center; margin:0 auto 15px; font-size:24px;
        }
        .sidebar-header h4 { color:#fff; font-weight:600; font-size:18px; margin-bottom:5px; }
        .sidebar-header p  { color:rgba(255,255,255,.7); font-size:12px; margin:0; }

        .sidebar-menu { padding:0 15px; }
        .menu-item { margin-bottom:5px; }
        .menu-link {
            display:flex; align-items:center; padding:12px 15px; color:rgba(255,255,255,.8);
            text-decoration:none; border-radius:8px; transition:.2s ease; font-size:14px; font-weight:500;
        }
        .menu-link i { width:20px; margin-right:12px; text-align:center; }
        .menu-link:hover, .menu-link.active { background:rgba(255,255,255,.1); color:#fff; transform: translateX(5px); }

        /* Main */
        .main-content { margin-left: var(--sidebar-width); min-height:100vh; }
        .header {
            height: var(--header-height); background:#fff; box-shadow:0 2px 10px rgba(0,0,0,.05);
            display:flex; align-items:center; padding:0 30px; position:sticky; top:0; z-index:999;
        }
        .header h1 { font-size:24px; font-weight:600; color: var(--primary-color); margin:0; }
        .content-area { padding:30px; }

        .card { border:1px solid #e9ecef; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.05); }
        .card-header { background:none; border-bottom:1px solid #e9ecef; padding:20px 25px; }
        .card-title { margin:0; font-size:18px; font-weight:600; color: var(--primary-color); }
        .card-body { padding:25px; }

        .form-label { font-weight:500; color: var(--primary-color); margin-bottom:8px; }
        .form-control { border:2px solid #e9ecef; border-radius:8px; padding:12px 15px; }
        .form-control:focus { border-color: var(--primary-color); box-shadow: 0 0 0 .2rem rgba(0,51,102,.1); }

        .alert { border-radius:8px; border:none; padding:15px 20px; margin-bottom:20px; }
        .alert-success { background: rgba(40,167,69,.1); color: var(--success-color); }
        .alert-danger  { background: rgba(220,53,69,.1); color: var(--danger-color); }

        @media (max-width:768px){
            .sidebar { transform: translateX(-100%); }
            .main-content { margin-left:0; }
            .content-area { padding:20px; }
            .header { padding:0 20px; }
        }
    </style>
</head>
<body>

<!-- Sidebar -->
  <?php include 'includes/sidebar.php'; ?>

<!-- Main -->
<div class="main-content">
    <header class="header">
        <h1>Settings &mdash; Change Admin Password</h1>
    </header>

    <div class="content-area">
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-key me-2"></i>Update Password</h3>
            </div>
            <div class="card-body">
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="current_password">Current Password *</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                        </div>
                        <div class="col-md-6"></div>
                        <div class="col-md-6">
                            <label class="form-label" for="new_password">New Password *</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" minlength="8" required>
                            <div class="form-text">Minimum 8 characters.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="confirm_password">Confirm New Password *</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" minlength="8" required>
                        </div>
                    </div>

                    <div class="d-flex gap-3 mt-4">
                        <button type="submit" name="change_password" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save New Password
                        </button>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="mt-3 text-muted small">
            Tip: After changing your password, consider logging out and logging back in to ensure your session refreshes.
        </div>
    </div>
</div>

<!-- JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Client-side quick check for matching passwords
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.querySelector('form[method="POST"]');
        const newPass = document.getElementById('new_password');
        const confPass = document.getElementById('confirm_password');

        form.addEventListener('submit', function (e) {
            if (newPass.value !== confPass.value) {
                e.preventDefault();
                alert('New password and confirmation do not match.');
                confPass.focus();
            }
        });
    });

    // Auto-hide alerts
    setTimeout(() => {
        document.querySelectorAll('.alert').forEach(a => {
            a.style.transition = 'opacity .5s';
            a.style.opacity = '0';
            setTimeout(() => a.remove(), 500);
        });
    }, 5000);
</script>
</body>
</html>
