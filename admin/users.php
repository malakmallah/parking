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
   Helpers: campus lookup + safe generators (no CONCAT in SQL)
-------------------------------------------------------------------*/
function findCampus(PDO $pdo, string $campusInput): ?array {
    // Try exact name
    $st = $pdo->prepare("SELECT id, name, code FROM campuses WHERE name = ? LIMIT 1");
    $st->execute([$campusInput]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    // If user selected something like "Tyre (TYR)" pull code in () and try code or name
    if (preg_match('/\(([A-Z]{2,4})\)/', $campusInput, $m)) {
        $code = $m[1];
        $st = $pdo->prepare("SELECT id, name, code FROM campuses WHERE code = ? LIMIT 1");
        $st->execute([$code]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    }

    // Try by code directly
    $st = $pdo->prepare("SELECT id, name, code FROM campuses WHERE code = ? LIMIT 1");
    $st->execute([$campusInput]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Next numeric for a pure numeric column stored as INT/BIGINT or VARCHAR of digits */
function nextNumeric(PDO $pdo, string $column): int {
    // Use CAST to be safe if the column is VARCHAR
    $sql = "SELECT COALESCE(MAX(CAST($column AS UNSIGNED)), 0) AS mx FROM users";
    $mx  = (int)$pdo->query($sql)->fetchColumn();
    return $mx + 1;
}

/** Get next parking number with prefix CODE-###### without CONCAT (build in PHP) */
function nextParkingNumber(PDO $pdo, string $campusCode, int $pad = 6): string {
    // Order by numeric tail of the string; no CONCAT used
    $st = $pdo->prepare("
        SELECT parking_number
        FROM users
        WHERE parking_number LIKE ?
        ORDER BY CAST(SUBSTRING_INDEX(parking_number, '-', -1) AS UNSIGNED) DESC
        LIMIT 1
    ");
    $st->execute([$campusCode . '-%']);
    $last = $st->fetchColumn();

    $n = 0;
    if ($last) {
        $tail = substr($last, strrpos($last, '-') + 1);
        if (ctype_digit($tail)) $n = (int)$tail;
    }
    $n++;

    return $campusCode . '-' . str_pad((string)$n, $pad, '0', STR_PAD_LEFT);
}

/** Make a username if you want to store it; fall back to email local-part */
function makeUserName(string $email, string $first, string $last): string {
    $local = strstr($email, '@', true);
    if ($local) return $local;
    $base = preg_replace('/\s+/', '.', trim($first . '.' . $last));
    return strtolower($base);
}

/* ------------------------------------------------------------------
   Pagination defaults (used in list view footer; harmless elsewhere)
-------------------------------------------------------------------*/
$per_page     = 10;
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
        $title      = trim($_POST['title'] ?? '');
        $role       = $_POST['role'] ?? '';
        $campusSel  = $_POST['campus'] ?? '';
        $school     = trim($_POST['school'] ?? '');
        $division   = trim($_POST['division'] ?? '');
        $password   = $_POST['password'] ?? '';
        $middle     = trim($_POST['middle'] ?? '');
        $photo_url  = trim($_POST['photo_url'] ?? '');
        // Do NOT take Reference/user_barcode/parking_number from the form; we will generate

        // Validation
        if ($email==='' || $first_name==='' || $last_name==='' || $password==='' || $role==='' || $campusSel==='') {
            $error_message = 'Please fill in all required fields (Email, First, Last, Password, Role, Campus).';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = 'Please enter a valid email address.';
        } else {
            // Resolve campus_id + code
            $campusRow = findCampus($pdo, $campusSel);
            if (!$campusRow) {
                $error_message = 'Invalid campus selection. Please choose a valid campus.';
            } else {
                try {
                    // Unique email check
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE Email = ?");
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) {
                        throw new Exception('Email address already exists in the system.');
                    }

                    // Generate values
                    $campus_id     = (int)$campusRow['id'];
                    $campus_code   = strtoupper($campusRow['code']);
                    $username      = makeUserName($email, $first_name, $last_name);
                    $nextRef       = nextNumeric($pdo, 'Reference');
                    $nextBarcode   = nextNumeric($pdo, 'user_barcode');
                    $parkingNumber = nextParkingNumber($pdo, $campus_code, 6);

                    // Insert
                    $ins = $pdo->prepare("
                        INSERT INTO users
                          (UserName, Title, FIRST, Middle, Last,
                           Email, School, Division, Campus, campus_id,
                           Reference, role, password_md5, photo_url,
                           created_at, updated_at,
                           parking_number, user_barcode, barcode_box_id, block_id)
                        VALUES
                          (?, ?, ?, ?, ?,
                           ?, ?, ?, ?, ?,
                           ?, ?, ?, ?,
                           NOW(), NOW(),
                           ?, ?, NULL, NULL)
                    ");
                    $ins->execute([
                        $username, $title, $first_name, $middle, $last_name,
                        $email, $school, $division, $campusRow['name'], $campus_id,
                        $nextRef, $role, md5($password), $photo_url,
                        $parkingNumber, $nextBarcode
                    ]);

                    $success_message = 'User added successfully!';
                    $action = 'list';
                } catch (Throwable $e) {
                    $error_message = 'Database error: ' . $e->getMessage();
                }
            }
        }
    } elseif (isset($_POST['update_user'])) {
        // Update existing user
        $user_id    = (int)($_POST['user_id'] ?? 0);
        $email      = trim($_POST['email']);
        $first_name = trim($_POST['first_name']);
        $last_name  = trim($_POST['last_name']);
        $title      = trim($_POST['title'] ?? '');
        $role       = $_POST['role'] ?? '';
        $campusSel  = $_POST['campus'] ?? '';
        $school     = trim($_POST['school'] ?? '');
        $division   = trim($_POST['division'] ?? '');
        $password   = $_POST['password'] ?? '';
        $middle     = trim($_POST['middle'] ?? '');
        $photo_url  = trim($_POST['photo_url'] ?? '');

        try {
            $campusRow = $campusSel ? findCampus($pdo, $campusSel) : null;

            // Fetch current to avoid overwriting generated identifiers
            $cur = $pdo->prepare("SELECT Reference, user_barcode, parking_number, Campus, campus_id FROM users WHERE id=?");
            $cur->execute([$user_id]);
            $curr = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$curr) throw new Exception('User not found.');

            $campus_name = $curr['Campus'];
            $campus_id   = (int)$curr['campus_id'];
            if ($campusRow) {
                $campus_name = $campusRow['name'];
                $campus_id   = (int)$campusRow['id'];
            }

            // If any of the auto fields are empty (legacy rows), generate once.
            $ref        = $curr['Reference'] ?: nextNumeric($pdo, 'Reference');
            $barcode    = $curr['user_barcode'] ?: nextNumeric($pdo, 'user_barcode');
            $parkNum    = $curr['parking_number'];
            if (!$parkNum && $campusRow) {
                $parkNum = nextParkingNumber($pdo, strtoupper($campusRow['code']), 6);
            }

            if (!empty($password)) {
                $stmt = $pdo->prepare("
                    UPDATE users SET 
                      Email = ?, FIRST = ?, Last = ?, Title = ?, role = ?,
                      Campus = ?, campus_id = ?, School = ?, Division = ?,
                      Reference = ?, user_barcode = ?, parking_number = ?,
                      Middle = ?, photo_url = ?,
                      updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $email, $first_name, $last_name, $title, $role,
                    $campus_name, $campus_id, $school, $division,
                    $ref, $barcode, $parkNum,
                    $middle, $photo_url,
                    $user_id
                ]);
                // update password separately (so we don't MD5 unless provided)
                $pdo->prepare("UPDATE users SET password_md5=? WHERE id=?")
                    ->execute([md5($password), $user_id]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE users SET 
                      Email = ?, FIRST = ?, Last = ?, Title = ?, role = ?,
                      Campus = ?, campus_id = ?, School = ?, Division = ?,
                      Reference = ?, user_barcode = ?, parking_number = ?,
                      Middle = ?, photo_url = ?,
                      updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $email, $first_name, $last_name, $title, $role,
                    $campus_name, $campus_id, $school, $division,
                    $ref, $barcode, $parkNum,
                    $middle, $photo_url,
                    $user_id
                ]);
            }

            $success_message = 'User updated successfully!';
            $action = 'list';
        } catch (Throwable $e) {
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

    // Count total
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $where_clause");
    $countStmt->execute($params);
    $total_users = (int)$countStmt->fetchColumn();

    $total_pages = max(1, (int)ceil($total_users / $per_page));
    if ($page > $total_pages) { $page = $total_pages; }
    $offset = ($page - 1) * $per_page;

    // Fetch page
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
<title><?= htmlspecialchars($pageTitle) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* (your existing CSS unchanged) */
:root {
  --primary-color:#003366; --secondary-color:#FFB81C; --success-color:#28a745;
  --info-color:#17a2b8; --warning-color:#ffc107; --danger-color:#dc3545;
  --light-color:#f8f9fa; --dark-color:#343a40; --sidebar-width:280px; --header-height:70px;
}
body{font-family:'Inter',sans-serif;background:#f5f7fa;color:#333}
.sidebar{position:fixed;top:0;left:0;height:100vh;width:var(--sidebar-width);background:linear-gradient(135deg,var(--primary-color) 0%,#004080 100%);z-index:1000;transition:all .3s;box-shadow:2px 0 10px rgba(0,0,0,.1)}
.sidebar-header{padding:20px;text-align:center;border-bottom:1px solid rgba(255,255,255,.1);margin-bottom:20px}
.sidebar-header .logo{width:50px;height:50px;background:var(--secondary-color);border-radius:12px;display:flex;align-items:center;justify-content:center;margin:0 auto 15px;font-size:24px;color:#fff}
.sidebar-header h4{color:#fff;font-weight:600;font-size:18px;margin-bottom:5px}
.sidebar-header p{color:rgba(255,255,255,.7);font-size:12px;margin:0}
.sidebar-menu{padding:0 15px}
.sidebar-menu .menu-item{margin-bottom:5px}
.sidebar-menu .menu-link{display:flex;align-items:center;padding:12px 15px;color:rgba(255,255,255,.8);text-decoration:none;border-radius:8px;transition:all .3s;font-size:14px;font-weight:500}
.sidebar-menu .menu-link:hover,.sidebar-menu .menu-link.active{background:rgba(255,255,255,.1);color:#fff;transform:translateX(5px)}
.sidebar-menu .menu-link i{width:20px;margin-right:12px;text-align:center}
.main-content{margin-left:var(--sidebar-width);min-height:100vh}
.header{height:var(--header-height);background:#fff;box-shadow:0 2px 10px rgba(0,0,0,.05);display:flex;align-items:center;justify-content:between;padding:0 30px;position:sticky;top:0;z-index:999}
.header-left h1{font-size:24px;font-weight:600;color:var(--primary-color);margin:0}
.header-right{margin-left:auto;display:flex;align-items:center;gap:20px}
.content-area{padding:30px}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.05);border:1px solid #e9ecef}
.card-header{background:none;border-bottom:1px solid #e9ecef;padding:20px 25px}
.card-title{font-size:18px;font-weight:600;color:var(--primary-color);margin:0}
.card-body{padding:25px}
.form-label{font-weight:500;color:var(--primary-color);margin-bottom:8px}
.form-control,.form-select{border:2px solid #e9ecef;border-radius:8px;padding:12px 15px;font-size:14px;transition:all .3s}
.form-control:focus,.form-select:focus{border-color:var(--primary-color);box-shadow:0 0 0 .2rem rgba(0,51,102,.1)}
.btn{padding:10px 20px;border-radius:8px;font-weight:500;font-size:14px;transition:all .3s}
.btn-primary{background:var(--primary-color);border-color:var(--primary-color)}
.btn-primary:hover{background:#004080;border-color:#004080;transform:translateY(-1px)}
.btn-secondary{background:var(--secondary-color);border-color:var(--secondary-color);color:#fff}
.btn-secondary:hover{background:#e67e22;border-color:#e67e22;color:#fff}
.table th{background:#f8f9fa;border:none;font-weight:600;color:var(--primary-color);padding:15px;font-size:13px;text-transform:uppercase;letter-spacing:.5px}
.table td{padding:15px;border-bottom:1px solid #e9ecef;vertical-align:middle}
.alert{border-radius:8px;border:none;padding:15px 20px;margin-bottom:20px}
.alert-success{background:rgba(40,167,69,.1);color:var(--success-color)}
.alert-danger{background:rgba(220,53,69,.1);color:var(--danger-color)}
@media (max-width:768px){.sidebar{transform:translateX(-100%)}.main-content{margin-left:0}.content-area{padding:20px}.header{padding:0 20px}}
</style>
</head>
<body>

<?php include 'includes/sidebar.php'; ?>

<div class="main-content">
    <header class="header">
        <div class="header-left">
            <h1><?= $action === 'add' ? 'Add New User' : ($action === 'edit' ? 'Edit User' : 'Users Management'); ?></h1>
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

    <div class="content-area">
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <?php if ($action === 'add' || $action === 'edit'): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-user-<?= $action === 'add' ? 'plus' : 'edit'; ?> me-2"></i>
                    <?= $action === 'add' ? 'Add New User' : 'Edit User'; ?>
                </h3>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php if ($action === 'edit'): ?>
                        <input type="hidden" name="user_id" value="<?= htmlspecialchars($edit_user['id']) ?>">
                    <?php endif; ?>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email Address *</label>
                            <input type="email" class="form-control" name="email" required
                                   value="<?= htmlspecialchars($edit_user['Email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Role *</label>
                            <select class="form-select" name="role" required>
                                <option value="">Select Role</option>
                                <option value="staff"      <?= (($edit_user['role'] ?? '')==='staff')?'selected':''; ?>>Staff</option>
                                <option value="instructor" <?= (($edit_user['role'] ?? '')==='instructor')?'selected':''; ?>>Instructor</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">First Name *</label>
                            <input type="text" class="form-control" name="first_name" required
                                   value="<?= htmlspecialchars($edit_user['FIRST'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Last Name *</label>
                            <input type="text" class="form-control" name="last_name" required
                                   value="<?= htmlspecialchars($edit_user['Last'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Middle</label>
                            <input type="text" class="form-control" name="middle"
                                   value="<?= htmlspecialchars($edit_user['Middle'] ?? '') ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" class="form-control" name="title"
                                   value="<?= htmlspecialchars($edit_user['Title'] ?? '') ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Campus *</label>
                            <select class="form-select" name="campus" required>
                                <option value="">Select Campus</option>
                                <?php foreach ($campuses as $campus): ?>
                                  <option value="<?= htmlspecialchars($campus['name']) ?>"
                                    <?= (($edit_user['Campus'] ?? '')===$campus['name'])?'selected':''; ?>>
                                    <?= htmlspecialchars($campus['name']) ?>
                                  </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">School/Faculty</label>
                            <input type="text" class="form-control" name="school"
                                   value="<?= htmlspecialchars($edit_user['School'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Division/Department</label>
                            <input type="text" class="form-control" name="division"
                                   value="<?= htmlspecialchars($edit_user['Division'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                Password <?= $action === 'add' ? '*' : '(leave blank to keep current)'; ?>
                            </label>
                            <input type="password" class="form-control" name="password" <?= $action === 'add' ? 'required' : '' ?>>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Photo URL (optional)</label>
                            <input type="url" class="form-control" name="photo_url"
                                   value="<?= htmlspecialchars($edit_user['photo_url'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="d-flex gap-3 mt-2">
                        <button type="submit" name="<?= $action === 'add' ? 'add_user' : 'update_user'; ?>" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i><?= $action === 'add' ? 'Add User' : 'Update User'; ?>
                        </button>
                        <a href="users.php" class="btn btn-secondary"><i class="fas fa-times me-2"></i>Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        <?php else: ?>

        <!-- (listing UI unchanged) -->
        <div class="search-filters">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" name="search"
                           value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" placeholder="Search by name or email...">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Role</label>
                    <select class="form-select" name="role_filter">
                        <option value="">All Roles</option>
                        <option value="staff"      <?= (($_GET['role_filter'] ?? '')==='staff')?'selected':''; ?>>Staff</option>
                        <option value="instructor" <?= (($_GET['role_filter'] ?? '')==='instructor')?'selected':''; ?>>Instructor</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Campus</label>
                    <select class="form-select" name="campus_filter">
                        <option value="">All Campuses</option>
                        <?php foreach ($campuses as $campus): ?>
                            <option value="<?= htmlspecialchars($campus['name']) ?>"
                              <?= (($_GET['campus_filter'] ?? '')===$campus['name'])?'selected':''; ?>>
                              <?= htmlspecialchars($campus['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2"><i class="fas fa-search"></i> Search</button>
                    <a href="users.php" class="btn btn-secondary"><i class="fas fa-times"></i> Clear</a>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0"><i class="fas fa-users me-2"></i>Users List (<?= (int)$total_users ?> users)</h3>
                <div class="text-muted small">
                    Showing <?= $total_users ? ($offset + 1) : 0; ?>–<?= $total_users ? min($offset + count($users), $total_users) : 0; ?> of <?= (int)$total_users; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>User</th><th>Campus</th><th>School/Division</th><th>Created</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($users as $user): ?>
                          <tr>
                            <td>
                              <div style="font-weight:600;color:#003366;"><?= htmlspecialchars($user['FIRST'].' '.$user['Last']) ?></div>
                              <div style="font-size:12px;color:#6c757d;"><?= htmlspecialchars($user['Email']) ?></div>
                              <?php if (!empty($user['Title'])): ?>
                                <div style="font-size:12px;color:#6c757d;"><?= htmlspecialchars($user['Title']) ?></div>
                              <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($user['Campus'] ?? 'N/A') ?></td>
                            <td>
                              <div style="font-size:13px;">
                                <?php if (!empty($user['School'])): ?><div><?= htmlspecialchars($user['School']) ?></div><?php endif; ?>
                                <?php if (!empty($user['Division'])): ?><div style="color:#6c757d;"><?= htmlspecialchars($user['Division']) ?></div><?php endif; ?>
                                <?php if (empty($user['School']) && empty($user['Division'])): ?><span style="color:#6c757d;">N/A</span><?php endif; ?>
                              </div>
                            </td>
                            <td><?= htmlspecialchars($user['formatted_date']) ?></td>
                            <td>
                              <div class="d-flex gap-2">
                                <a class="btn btn-sm btn-outline-primary" href="?action=edit&id=<?= $user['id'] ?>" title="Edit User"><i class="fas fa-edit"></i></a>
                                <a class="btn btn-sm btn-outline-success" href="cards.php?user_id=<?= $user['id'] ?>" title="Generate ID Card"><i class="fas fa-id-card"></i></a>
                                <button class="btn btn-sm btn-outline-danger" title="Delete User"
                                  onclick="confirmDelete(<?= $user['id'] ?>,'<?= htmlspecialchars($user['FIRST'].' '.$user['Last'], ENT_QUOTES) ?>')">
                                  <i class="fas fa-trash"></i>
                                </button>
                              </div>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                        <?php if (empty($users)): ?>
                          <tr><td colspan="5" class="text-center" style="padding:40px;">
                            <i class="fas fa-users" style="font-size:48px;color:#6c757d;margin-bottom:15px;"></i>
                            <div style="font-size:16px;color:#6c757d;margin-bottom:10px;">No users found</div>
                            <div style="font-size:14px;color:#6c757d;"><a href="?action=add" class="btn btn-primary btn-sm">Add your first user</a></div>
                          </td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="card-footer">
              <div class="row align-items-center">
                <div class="col text-muted">
                  Showing <?= $total_users ? ($offset + 1) : 0; ?> to <?= $total_users ? min($offset + count($users), $total_users) : 0; ?> of <?= number_format($total_users) ?> entries
                </div>
                <div class="col-auto">
                  <nav><ul class="pagination pagination-sm mb-0">
                    <?php $qs=function($p){$q=$_GET;$q['page']=$p;$q['action']='list';return '?'.htmlspecialchars(http_build_query($q));};
                          $start=max(1,$page-2); $end=min($total_pages,$page+2); ?>
                    <?php if ($page>1): ?><li class="page-item"><a class="page-link" href="<?= $qs($page-1) ?>"><i class="fas fa-chevron-left"></i></a></li><?php endif; ?>
                    <?php if ($start>1): ?><li class="page-item"><a class="page-link" href="<?= $qs(1) ?>">1</a></li><?php if ($start>2): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; endif; ?>
                    <?php for ($i=$start;$i<=$end;$i++): ?><li class="page-item <?= $i===$page?'active':'' ?>"><a class="page-link" href="<?= $i===$page?'#':$qs($i) ?>"><?= $i ?></a></li><?php endfor; ?>
                    <?php if ($end<$total_pages): ?><?php if ($end<$total_pages-1): ?><li class="page-item disabled"><span class="page-link">...</span></li><?php endif; ?><li class="page-item"><a class="page-link" href="<?= $qs($total_pages) ?>"><?= $total_pages ?></a></li><?php endif; ?>
                    <?php if ($page<$total_pages): ?><li class="page-item"><a class="page-link" href="<?= $qs($page+1) ?>"><i class="fas fa-chevron-right"></i></a></li><?php endif; ?>
                  </ul></nav>
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
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title" id="deleteModalLabel"><i class="fas fa-exclamation-triangle text-danger me-2"></i>Confirm Delete</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
      <p>Are you sure you want to delete <strong id="deleteUserName"></strong>?</p>
      <p class="text-muted mb-0">This action cannot be undone.</p>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <a href="#" id="confirmDeleteBtn" class="btn btn-danger"><i class="fas fa-trash me-2"></i>Delete User</a>
    </div>
  </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function confirmDelete(userId, userName){
  document.getElementById('deleteUserName').textContent = userName;
  document.getElementById('confirmDeleteBtn').href = '?delete=' + userId;
  new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>
</body>
</html>
