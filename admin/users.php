<?php
/**
 * LIU Parking System - Users Management
 * Add, edit, and manage staff/instructor accounts
 * Location: admin/users.php
 */

session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Database configuration
$db_host = 'localhost';
$db_name = 'parking';
$db_user = 'root';
$db_pass = '';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$success_message = '';
$error_message   = '';
$action          = $_GET['action'] ?? 'list';
$edit_user       = null;

/* ------------------------------------------------------------------
   Pagination defaults (used in list view footer; harmless elsewhere)
-------------------------------------------------------------------*/
$per_page     = 10;                                                // adjust if desired
$page         = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$total_users  = 0;
$total_pages  = 1;
$offset       = 0;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_user'])) {
        // Add new user
        $email      = trim($_POST['email']);
        $first_name = trim($_POST['first_name']);
        $last_name  = trim($_POST['last_name']);
        $title      = trim($_POST['title']);
        $role       = $_POST['role'];
        $campus     = $_POST['campus'];
        $school     = trim($_POST['school']);
        $division   = trim($_POST['division']);
        $reference  = trim($_POST['reference']);
        $password   = $_POST['password'];

        // Validation
        if (empty($email) || empty($first_name) || empty($last_name) || empty($password)) {
            $error_message = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = 'Please enter a valid email address.';
        } else {
            try {
                // Check if email already exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE Email = ?");
                $stmt->execute([$email]);

                if ($stmt->fetch()) {
                    $error_message = 'Email address already exists in the system.';
                } else {
                    // Insert new user
                    $stmt = $pdo->prepare("
                        INSERT INTO users (Email, FIRST, Last, Title, role, Campus, School, Division, Reference, password_md5, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");

                    $stmt->execute([
                        $email,
                        $first_name,
                        $last_name,
                        $title,
                        $role,
                        $campus,
                        $school,
                        $division,
                        $reference,
                        md5($password)
                    ]);

                    $success_message = 'User added successfully!';
                    $action = 'list'; // Redirect to list view
                }
            } catch (PDOException $e) {
                $error_message = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['update_user'])) {
        // Update existing user
        $user_id    = $_POST['user_id'];
        $email      = trim($_POST['email']);
        $first_name = trim($_POST['first_name']);
        $last_name  = trim($_POST['last_name']);
        $title      = trim($_POST['title']);
        $role       = $_POST['role'];
        $campus     = $_POST['campus'];
        $school     = trim($_POST['school']);
        $division   = trim($_POST['division']);
        $reference  = trim($_POST['reference']);
        $password   = $_POST['password'];

        try {
            // Update user (with or without password change)
            if (!empty($password)) {
                $stmt = $pdo->prepare("
                    UPDATE users SET 
                    Email = ?, FIRST = ?, Last = ?, Title = ?, role = ?, Campus = ?, 
                    School = ?, Division = ?, Reference = ?, password_md5 = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $email, $first_name, $last_name, $title, $role, $campus,
                    $school, $division, $reference, md5($password), $user_id
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE users SET 
                    Email = ?, FIRST = ?, Last = ?, Title = ?, role = ?, Campus = ?, 
                    School = ?, Division = ?, Reference = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $email, $first_name, $last_name, $title, $role, $campus,
                    $school, $division, $reference, $user_id
                ]);
            }

            $success_message = 'User updated successfully!';
            $action = 'list';
        } catch (PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    }
}

// Handle delete action
if (isset($_GET['delete'])) {
    $user_id = (int)$_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'");
        $stmt->execute([$user_id]);
        $success_message = 'User deleted successfully!';
    } catch (PDOException $e) {
        $error_message = 'Error deleting user: ' . $e->getMessage();
    }
}

// Get user for editing
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $edit_user = $stmt->fetch();

    if (!$edit_user) {
        $error_message = 'User not found.';
        $action = 'list';
    }
}

// Get all users for listing (WITH pagination)
$users = [];
if ($action === 'list') {
    $search        = $_GET['search'] ?? '';
    $role_filter   = $_GET['role_filter'] ?? '';
    $campus_filter = $_GET['campus_filter'] ?? '';

    $where_conditions = ["role IN ('staff', 'instructor')"];
    $params = [];

    if (!empty($search)) {
        $where_conditions[] = "(FIRST LIKE ? OR Last LIKE ? OR Email LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if (!empty($role_filter)) {
        $where_conditions[] = "role = ?";
        $params[] = $role_filter;
    }

    if (!empty($campus_filter)) {
        $where_conditions[] = "Campus = ?";
        $params[] = $campus_filter;
    }

    $where_clause = implode(' AND ', $where_conditions);

    // --- Count total for pagination ---
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $where_clause");
    $countStmt->execute($params);
    $total_users = (int)$countStmt->fetchColumn();

    $total_pages = max(1, (int)ceil($total_users / $per_page));
    if ($page > $total_pages) { $page = $total_pages; }
    $offset = ($page - 1) * $per_page;

    // --- Fetch current page (LIMIT/OFFSET are server-controlled ints) ---
    $stmt = $pdo->prepare("
        SELECT *, DATE_FORMAT(created_at, '%M %d, %Y') as formatted_date
        FROM users 
        WHERE $where_clause 
        ORDER BY created_at DESC
        LIMIT $per_page OFFSET $offset
    ");
    $stmt->execute($params);
    $users = $stmt->fetchAll();
}

// Get campuses for dropdown
$stmt = $pdo->query("SELECT name FROM campuses ORDER BY name");
$campuses = $stmt->fetchAll();

$pageTitle = "Users Management - LIU Parking System";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>

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
            --info-color: #17a2b8;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --sidebar-width: 280px;
            --header-height: 70px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f5f7fa;
            color: #333;
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: linear-gradient(135deg, var(--primary-color) 0%, #004080 100%);
            z-index: 1000;
            transition: all 0.3s ease;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }

        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 20px;
        }

        .sidebar-header .logo {
            width: 50px;
            height: 50px;
            background: var(--secondary-color);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 24px;
            color: white;
        }

        .sidebar-header h4 { color: white; font-weight: 600; font-size: 18px; margin-bottom: 5px; }
        .sidebar-header p { color: rgba(255,255,255,0.7); font-size: 12px; margin: 0; }

        .sidebar-menu { padding: 0 15px; }
        .sidebar-menu .menu-item { margin-bottom: 5px; }
        .sidebar-menu .menu-link {
            display: flex; align-items: center; padding: 12px 15px;
            color: rgba(255,255,255,0.8); text-decoration: none; border-radius: 8px;
            transition: all 0.3s ease; font-size: 14px; font-weight: 500;
        }
        .sidebar-menu .menu-link:hover, .sidebar-menu .menu-link.active {
            background: rgba(255,255,255,0.1); color: white; transform: translateX(5px);
        }
        .sidebar-menu .menu-link i { width: 20px; margin-right: 12px; text-align: center; }

        /* Main Content */
        .main-content { margin-left: var(--sidebar-width); min-height: 100vh; }
        .header {
            height: var(--header-height); background: white; box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex; align-items: center; justify-content: between; padding: 0 30px;
            position: sticky; top: 0; z-index: 999;
        }
        .header-left h1 { font-size: 24px; font-weight: 600; color: var(--primary-color); margin: 0; }
        .header-right { margin-left: auto; display: flex; align-items: center; gap: 20px; }

        .content-area { padding: 30px; }

        /* Cards */
        .card { background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border: 1px solid #e9ecef; }
        .card-header { background: none; border-bottom: 1px solid #e9ecef; padding: 20px 25px; }
        .card-title { font-size: 18px; font-weight: 600; color: var(--primary-color); margin: 0; }
        .card-body { padding: 25px; }

        /* Forms */
        .form-label { font-weight: 500; color: var(--primary-color); margin-bottom: 8px; }
        .form-control, .form-select { border: 2px solid #e9ecef; border-radius: 8px; padding: 12px 15px; font-size: 14px; transition: all 0.3s ease; }
        .form-control:focus, .form-select:focus { border-color: var(--primary-color); box-shadow: 0 0 0 0.2rem rgba(0, 51, 102, 0.1); }

        /* Buttons */
        .btn { padding: 10px 20px; border-radius: 8px; font-weight: 500; font-size: 14px; transition: all 0.3s ease; }
        .btn-primary { background: var(--primary-color); border-color: var(--primary-color); }
        .btn-primary:hover { background: #004080; border-color: #004080; transform: translateY(-1px); }
        .btn-secondary { background: var(--secondary-color); border-color: var(--secondary-color); color: white; }
        .btn-secondary:hover { background: #e67e22; border-color: #e67e22; color: white; }

        /* Table */
        .table { margin: 0; }
        .table th {
            background: #f8f9fa; border: none; font-weight: 600; color: var(--primary-color);
            padding: 15px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .table td { padding: 15px; border-bottom: 1px solid #e9ecef; vertical-align: middle; }
        .table tr:last-child td { border-bottom: none; }

        /* Status badges */
        .badge { padding: 6px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .badge.bg-success { background-color: var(--success-color) !important; }
        .badge.bg-warning { background-color: var(--warning-color) !important; }

        /* Search and filters */
        .search-filters {
            background: white; padding: 20px; border-radius: 12px; margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        /* Alert messages */
        .alert { border-radius: 8px; border: none; padding: 15px 20px; margin-bottom: 20px; }
        .alert-success { background: rgba(40, 167, 69, 0.1); color: var(--success-color); }
        .alert-danger  { background: rgba(220, 53, 69, 0.1); color: var(--danger-color); }

        /* Action buttons */
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .main-content { margin-left: 0; }
            .content-area { padding: 20px; }
            .header { padding: 0 20px; }
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-car"></i>
            </div>
            <h4>LIU Parking</h4>
            <p>Admin Dashboard</p>
        </div>

        <div class="sidebar-menu">
            <div class="menu-item">
                <a href="index.php" class="menu-link">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="users.php" class="menu-link active">
                    <i class="fas fa-users"></i>
                    <span>Users Management</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="campuses.php" class="menu-link">
                    <i class="fas fa-university"></i>
                    <span>Campuses & Blocks</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="spots.php" class="menu-link">
                    <i class="fas fa-parking"></i>
                    <span>Parking Spots</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="gates.php" class="menu-link">
                    <i class="fas fa-door-open"></i>
                    <span>Gates & Wall Codes</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="sessions.php" class="menu-link">
                    <i class="fas fa-history"></i>
                    <span>Parking Sessions</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="cards.php" class="menu-link">
                    <i class="fas fa-id-card"></i>
                    <span>Parking ID Cards</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="reports.php" class="menu-link">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reports</span>
                </a>
            </div>
            <hr style="border-color: rgba(255,255,255,0.1); margin: 20px 0;">
            <div class="menu-item">
                <a href="settings.php" class="menu-link">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="../logout.php" class="menu-link">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="header">
            <div class="header-left">
                <h1><?php echo $action === 'add' ? 'Add New User' : ($action === 'edit' ? 'Edit User' : 'Users Management'); ?></h1>
            </div>
            <div class="header-right">
                <?php if ($action === 'list'): ?>
                <a href="?action=add" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>Add New User
                </a>
                <?php else: ?>
                <a href="users.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to List
                </a>
                <?php endif; ?>
            </div>
        </header>

        <!-- Content Area -->
        <div class="content-area">
            <!-- Success/Error Messages -->
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <?php if ($action === 'add' || $action === 'edit'): ?>
                <!-- Add/Edit User Form -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-user-<?php echo $action === 'add' ? 'plus' : 'edit'; ?> me-2"></i>
                            <?php echo $action === 'add' ? 'Add New User' : 'Edit User'; ?>
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?php if ($action === 'edit'): ?>
                                <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
                            <?php endif; ?>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email Address *</label>
                                        <input type="email" class="form-control" id="email" name="email" required
                                               value="<?php echo htmlspecialchars($edit_user['Email'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="role" class="form-label">Role *</label>
                                        <select class="form-select" id="role" name="role" required>
                                            <option value="">Select Role</option>
                                            <option value="staff" <?php echo ($edit_user['role'] ?? '') === 'staff' ? 'selected' : ''; ?>>Staff</option>
                                            <option value="instructor" <?php echo ($edit_user['role'] ?? '') === 'instructor' ? 'selected' : ''; ?>>Instructor</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="first_name" class="form-label">First Name *</label>
                                        <input type="text" class="form-control" id="first_name" name="first_name" required
                                               value="<?php echo htmlspecialchars($edit_user['FIRST'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="last_name" class="form-label">Last Name *</label>
                                        <input type="text" class="form-control" id="last_name" name="last_name" required
                                               value="<?php echo htmlspecialchars($edit_user['Last'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="title" class="form-label">Title</label>
                                        <input type="text" class="form-control" id="title" name="title"
                                               value="<?php echo htmlspecialchars($edit_user['Title'] ?? ''); ?>"
                                               placeholder="e.g., Professor, Assistant, Coordinator">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="campus" class="form-label">Campus</label>
                                        <select class="form-select" id="campus" name="campus">
                                            <option value="">Select Campus</option>
                                            <?php foreach ($campuses as $campus): ?>
                                                <option value="<?php echo htmlspecialchars($campus['name']); ?>" 
                                                        <?php echo ($edit_user['Campus'] ?? '') === $campus['name'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($campus['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="school" class="form-label">School/Faculty</label>
                                        <input type="text" class="form-control" id="school" name="school"
                                               value="<?php echo htmlspecialchars($edit_user['School'] ?? ''); ?>"
                                               placeholder="e.g., School of Engineering">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="division" class="form-label">Division/Department</label>
                                        <input type="text" class="form-control" id="division" name="division"
                                               value="<?php echo htmlspecialchars($edit_user['Division'] ?? ''); ?>"
                                               placeholder="e.g., Computer Science">
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="reference" class="form-label">Reference Number</label>
                                        <input type="text" class="form-control" id="reference" name="reference"
                                               value="<?php echo htmlspecialchars($edit_user['Reference'] ?? ''); ?>"
                                               placeholder="Employee ID or Reference">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="password" class="form-label">
                                            Password <?php echo $action === 'add' ? '*' : '(leave blank to keep current)'; ?>
                                        </label>
                                        <input type="password" class="form-control" id="password" name="password"
                                               <?php echo $action === 'add' ? 'required' : ''; ?>>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex gap-3 mt-4">
                                <button type="submit" name="<?php echo $action === 'add' ? 'add_user' : 'update_user'; ?>" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>
                                    <?php echo $action === 'add' ? 'Add User' : 'Update User'; ?>
                                </button>
                                <a href="users.php" class="btn btn-secondary">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <!-- Users List -->

                <!-- Search and Filters -->
                <div class="search-filters">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label for="search" class="form-label">Search</label>
                            <input type="text" class="form-control" id="search" name="search" 
                                   value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
                                   placeholder="Search by name or email...">
                        </div>
                        <div class="col-md-3">
                            <label for="role_filter" class="form-label">Role</label>
                            <select class="form-select" id="role_filter" name="role_filter">
                                <option value="">All Roles</option>
                                <option value="staff" <?php echo ($_GET['role_filter'] ?? '') === 'staff' ? 'selected' : ''; ?>>Staff</option>
                                <option value="instructor" <?php echo ($_GET['role_filter'] ?? '') === 'instructor' ? 'selected' : ''; ?>>Instructor</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="campus_filter" class="form-label">Campus</label>
                            <select class="form-select" id="campus_filter" name="campus_filter">
                                <option value="">All Campuses</option>
                                <?php foreach ($campuses as $campus): ?>
                                    <option value="<?php echo htmlspecialchars($campus['name']); ?>" 
                                            <?php echo ($_GET['campus_filter'] ?? '') === $campus['name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($campus['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="fas fa-search"></i> Search
                            </button>
                            <a href="users.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Users Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0">
                            <i class="fas fa-users me-2"></i>
                            Users List (<?php echo (int)$total_users; ?> users)
                        </h3>
                        <div class="text-muted small">
                            Showing <?php echo $total_users ? ($offset + 1) : 0; ?>–<?php echo $total_users ? min($offset + count($users), $total_users) : 0; ?> of <?php echo (int)$total_users; ?>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Campus</th>
                                        <th>School/Division</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600; color: var(--primary-color);">
                                                <?php echo htmlspecialchars($user['FIRST'] . ' ' . $user['Last']); ?>
                                            </div>
                                            <div style="font-size: 12px; color: #6c757d;">
                                                <?php echo htmlspecialchars($user['Email']); ?>
                                            </div>
                                            <?php if (!empty($user['Title'])): ?>
                                                <div style="font-size: 12px; color: #6c757d;">
                                                    <?php echo htmlspecialchars($user['Title']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($user['Campus'] ?? 'N/A'); ?>
                                        </td>
                                        <td>
                                            <div style="font-size: 13px;">
                                                <?php if (!empty($user['School'])): ?>
                                                    <div><?php echo htmlspecialchars($user['School']); ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($user['Division'])): ?>
                                                    <div style="color: #6c757d;"><?php echo htmlspecialchars($user['Division']); ?></div>
                                                <?php endif; ?>
                                                <?php if (empty($user['School']) && empty($user['Division'])): ?>
                                                    <span style="color: #6c757d;">N/A</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($user['formatted_date']); ?>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-2">
                                                <a href="?action=edit&id=<?php echo $user['id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary" title="Edit User">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="cards.php?user_id=<?php echo $user['id']; ?>" 
                                                   class="btn btn-sm btn-outline-success" title="Generate ID Card">
                                                    <i class="fas fa-id-card"></i>
                                                </a>
                                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                                        onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['FIRST'] . ' ' . $user['Last']); ?>')"
                                                        title="Delete User">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>

                                    <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center" style="padding: 40px;">
                                            <i class="fas fa-users" style="font-size: 48px; color: #6c757d; margin-bottom: 15px;"></i>
                                            <div style="font-size: 16px; color: #6c757d; margin-bottom: 10px;">No users found</div>
                                            <div style="font-size: 14px; color: #6c757d;">
                                                <?php if (!empty($_GET['search']) || !empty($_GET['role_filter']) || !empty($_GET['campus_filter'])): ?>
                                                    Try adjusting your search filters or <a href="users.php">view all users</a>
                                                <?php else: ?>
                                                    <a href="?action=add" class="btn btn-primary btn-sm">Add your first user</a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="card-footer">
                        <div class="row align-items-center">
                            <div class="col">
                                <div class="text-muted">
                                    Showing <?php echo $total_users ? ($offset + 1) : 0; ?> to <?php echo $total_users ? min($offset + count($users), $total_users) : 0; ?> 
                                    of <?php echo number_format($total_users); ?> entries
                                </div>
                            </div>
                            <div class="col-auto">
                                <nav>
                                    <ul class="pagination pagination-sm mb-0">
                                        <?php
                                            // Build querystring while preserving filters/search
                                            $qs = function($p) {
                                                $q = $_GET;
                                                $q['page'] = $p;
                                                $q['action'] = 'list';
                                                return '?' . htmlspecialchars(http_build_query($q));
                                            };
                                            $start = max(1, $page - 2);
                                            $end   = min($total_pages, $page + 2);
                                        ?>
                                        <?php if ($page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="<?php echo $qs($page - 1); ?>">
                                                    <i class="fas fa-chevron-left"></i>
                                                </a>
                                            </li>
                                        <?php endif; ?>

                                        <?php if ($start > 1): ?>
                                            <li class="page-item"><a class="page-link" href="<?php echo $qs(1); ?>">1</a></li>
                                            <?php if ($start > 2): ?>
                                                <li class="page-item disabled"><span class="page-link">...</span></li>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php for ($i = $start; $i <= $end; $i++): ?>
                                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="<?php echo $i === $page ? '#' : $qs($i); ?>"><?php echo $i; ?></a>
                                            </li>
                                        <?php endfor; ?>

                                        <?php if ($end < $total_pages): ?>
                                            <?php if ($end < $total_pages - 1): ?>
                                                <li class="page-item disabled"><span class="page-link">...</span></li>
                                            <?php endif; ?>
                                            <li class="page-item"><a class="page-link" href="<?php echo $qs($total_pages); ?>"><?php echo $total_pages; ?></a></li>
                                        <?php endif; ?>

                                        <?php if ($page < $total_pages): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="<?php echo $qs($page + 1); ?>">
                                                    <i class="fas fa-chevron-right"></i>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">
                        <i class="fas fa-exclamation-triangle text-danger me-2"></i>
                        Confirm Delete
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete <strong id="deleteUserName"></strong>?</p>
                    <p class="text-muted mb-0">This action cannot be undone. All parking sessions and related data for this user will remain in the system for record keeping.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="#" id="confirmDeleteBtn" class="btn btn-danger">
                        <i class="fas fa-trash me-2"></i>Delete User
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Delete confirmation
        function confirmDelete(userId, userName) {
            document.getElementById('deleteUserName').textContent = userName;
            document.getElementById('confirmDeleteBtn').href = '?delete=' + userId;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const requiredFields = form.querySelectorAll('[required]');
                    let isValid = true;

                    requiredFields.forEach(field => {
                        if (!field.value.trim()) {
                            field.classList.add('is-invalid');
                            isValid = false;
                        } else {
                            field.classList.remove('is-invalid');
                        }
                    });

                    if (!isValid) {
                        e.preventDefault();
                        alert('Please fill in all required fields.');
                    }
                });
            });

            // Clear validation on input
            document.querySelectorAll('input[required], select[required]').forEach(field => {
                field.addEventListener('input', function() {
                    if (this.value.trim()) {
                        this.classList.remove('is-invalid');
                    }
                });
            });
        });

        // Auto-clear success messages
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>
