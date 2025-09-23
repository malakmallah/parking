<?php
/**
 * LIU Parking System - Gates / Barcode Manager
 * Location: /admin/gates.php
 *
 * What it does
 * - Admin chooses a campus (and optional block if campus=Beirut)
 * - Assigns sequential barcode numbers to all users in that campus who don't have one yet
 *   Format: <CAMPUS_CODE><7-digit serial>, e.g. BEI0000001, BEK0000002
 * - Opens a printable sheet (/index/gates.php) with QR cards for those users
 *
 * Uses:
 *   campuses(id,name,code)
 *   blocks(id,campus_id,name)
 *   users(id, FIRST, Last, Email, campus_id, Campus, role, parking_number)
 */

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../login.php'); exit;
}

$db_host='localhost'; $db_name='parking'; $db_user='root'; $db_pass='';
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",$db_user,$db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) { die("DB error: ".$e->getMessage()); }

$success=''; $error='';

// Fetch campuses
$campuses = $pdo->query("SELECT id,name,code FROM campuses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// If campus selected, fetch its blocks (only meaningful for Beirut, but we’ll show for any)
$selected_campus_id = isset($_GET['campus_id']) ? (int)$_GET['campus_id'] : 0;
$blocks = [];
if ($selected_campus_id) {
    $st = $pdo->prepare("SELECT id,name FROM blocks WHERE campus_id=? ORDER BY name");
    $st->execute([$selected_campus_id]);
    $blocks = $st->fetchAll(PDO::FETCH_ASSOC);
}

// Assign numbers
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['assign_numbers'])) {
    $campus_id = (int)$_POST['campus_id'];
    $block_id  = !empty($_POST['block_id']) ? (int)$_POST['block_id'] : null;

    if (!$campus_id) { $error = "Please select a campus."; }
    else {
        try {
            // 1) campus code
            $st = $pdo->prepare("SELECT id, name, code FROM campuses WHERE id=?");
            $st->execute([$campus_id]);
            $camp = $st->fetch(PDO::FETCH_ASSOC);
            if (!$camp) throw new Exception("Campus not found.");
            $prefix = strtoupper($camp['code'] ?? '');
            if ($prefix==='') throw new Exception("Campus code missing for {$camp['name']}.");

            // 2) current max serial for this campus
            $st = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(parking_number,4) AS UNSIGNED)) AS maxnum
                                 FROM users WHERE parking_number LIKE CONCAT(?, '%')");
            $st->execute([$prefix]);
            $maxnum = (int)($st->fetchColumn() ?: 0);
            $next = $maxnum + 1;

            // 3) Target users (staff/instructor) in campus without a number yet
            $sqlUsers = "SELECT id FROM users WHERE role IN ('staff','instructor') AND campus_id = ? AND (parking_number IS NULL OR parking_number='')";
            $params = [$campus_id];

            // (Optional) If you ever store block on user, add AND block_id = ?
            // For now users don’t have block_id, so we don’t filter by block.

            $st = $pdo->prepare($sqlUsers);
            $st->execute($params);
            $ids = $st->fetchAll(PDO::FETCH_COLUMN);

            if (!$ids) {
                $success = "No unassigned users found for {$camp['name']}.";
            } else {
                // 4) Assign sequential codes
                $upd = $pdo->prepare("UPDATE users SET parking_number = ? WHERE id = ?");
                foreach ($ids as $uid) {
                    $code = $prefix . str_pad($next, 7, '0', STR_PAD_LEFT);
                    $upd->execute([$code, (int)$uid]);
                    $next++;
                }
                $assignedCount = count($ids);
                $success = "Assigned $assignedCount barcodes for {$camp['name']} (prefix $prefix).";

                // After assigning, open printable sheet for the campus
                $qs = http_build_query([
                    'campus_id' => $campus_id,
                    // 'block_id' => $block_id  // kept for future if you store blocks per user
                ]);
                header("Location: ../index/gates.php?$qs");
                exit;
            }
        } catch (Throwable $e) {
            $error = "Error: ".$e->getMessage();
        }
    }
}

$pageTitle = "Gates & Barcodes - LIU Parking";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{ --primary:#003366; --secondary:#FFB81C; --sidebar-w:280px; }
body{ font-family:'Inter',sans-serif; background:#f5f7fa; }
.sidebar{ position:fixed; top:0; left:0; height:100vh; width:var(--sidebar-w);
  background:linear-gradient(135deg, var(--primary) 0%, #004080 100%); color:#fff; }
.sidebar .logo{ width:50px;height:50px; background:var(--secondary); border-radius:12px;
  display:flex;align-items:center;justify-content:center; font-size:24px; margin:20px auto 10px;}
.sidebar a{ color:rgba(255,255,255,.85); text-decoration:none; display:block; padding:10px 18px; border-radius:8px; }
.sidebar a.active, .sidebar a:hover{ background:rgba(255,255,255,.12); color:#fff; }
.main{ margin-left:var(--sidebar-w); min-height:100vh; }
.header{ background:#fff; border-bottom:1px solid #e9ecef; padding:16px 24px; position:sticky; top:0; z-index:5;}
.card{ border-radius:12px; border:1px solid #e9ecef; box-shadow:0 2px 10px rgba(0,0,0,.05); }
</style>
</head>
<body>
<nav class="sidebar">
  <div class="text-center">
    <div class="logo"><i class="fas fa-door-open"></i></div>
    <h5 class="m-0">LIU Parking</h5>
    <small class="text-white-50">Admin Dashboard</small>
    <hr class="border-light opacity-25">
  </div>
  <div class="px-2">
    <a href="index.php"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</a>
    <a href="users.php"><i class="fas fa-users me-2"></i>Users</a>
    <a href="campuses.php"><i class="fas fa-university me-2"></i>Campuses</a>
    <a class="active" href="gates.php"><i class="fas fa-door-open me-2"></i>Gates & Barcodes</a>
    <a href="spots.php"><i class="fas fa-parking me-2"></i>Spots</a>
    <a href="sessions.php"><i class="fas fa-history me-2"></i>Sessions</a>
    <hr class="border-light opacity-25">
    <a href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a>
    <a href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a>
  </div>
</nav>

<div class="main">
  <div class="header d-flex align-items-center">
    <h4 class="mb-0"><i class="fas fa-qrcode text-primary me-2"></i>Gates & Barcode Numbers</h4>
    <div class="ms-auto">
      <a class="btn btn-outline-secondary btn-sm" href="../index/gates.php" target="_blank">
        <i class="fas fa-print me-1"></i> Open Printable
      </a>
    </div>
  </div>

  <div class="container py-4">
    <?php if($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card">
      <div class="card-header">
        <strong>Assign Barcode Numbers</strong>
        <div class="text-muted small">Creates sequential numbers for users without one in the selected campus.</div>
      </div>
      <div class="card-body">
        <form method="post" class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Campus *</label>
            <select name="campus_id" id="campusSelect" class="form-select" required
                    onchange="location.href='gates.php?campus_id='+this.value">
              <option value="">Select campus</option>
              <?php foreach($campuses as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $selected_campus_id===(int)$c['id']?'selected':'' ?>>
                  <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['code']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">Block (optional)</label>
            <select name="block_id" id="blockSelect" class="form-select">
              <option value="">All blocks</option>
              <?php foreach($blocks as $b): ?>
                <option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Shown for campuses with defined blocks (e.g., Beirut).</div>
          </div>

          <div class="col-12 d-flex gap-2">
            <button class="btn btn-primary" name="assign_numbers">
              <i class="fas fa-hashtag me-2"></i>Assign numbers to unassigned users
            </button>
            <?php if ($selected_campus_id): ?>
              <a target="_blank" class="btn btn-secondary"
                 href="../index/gates.php?campus_id=<?= (int)$selected_campus_id ?>">
                <i class="fas fa-print me-2"></i>Open printable sheet
              </a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <div class="card mt-4">
      <div class="card-header"><strong>Current counts per campus</strong></div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead><tr><th>Campus</th><th>Code</th><th>Users</th><th>With Barcode</th><th>Without</th></tr></thead>
            <tbody>
            <?php
            $rows = $pdo->query("
              SELECT c.id,c.name,c.code,
                     COUNT(u.id) AS total,
                     SUM(CASE WHEN u.parking_number IS NOT NULL AND u.parking_number<>'' THEN 1 ELSE 0 END) AS with_code
              FROM campuses c
              LEFT JOIN users u ON u.campus_id=c.id AND u.role IN ('staff','instructor')
              GROUP BY c.id,c.name,c.code
              ORDER BY c.name
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as $r):
              $without = (int)$r['total'] - (int)$r['with_code'];
            ?>
              <tr>
                <td><?= htmlspecialchars($r['name']) ?></td>
                <td><?= htmlspecialchars($r['code']) ?></td>
                <td><?= (int)$r['total'] ?></td>
                <td><?= (int)$r['with_code'] ?></td>
                <td><?= $without ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
</div>
</body>
</html>
