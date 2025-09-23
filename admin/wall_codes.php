<?php
/**
 * LIU Parking System - Wall Codes (QR) Manager
 * Location: /admin/wall_codes.php
 *
 * Table used: wall_codes(id, code, description)
 * We store CODE as the logical payload (string). The image is rendered as QR on the fly.
 * Payload formats we create:
 *   CAMPUS:<campus_id>
 *   CAMPUS:<campus_id>|BLOCK:<block_id>   (for Beirut blocks)
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

// Ensure wall codes exist (campus + beirut blocks)
if (isset($_POST['ensure_codes'])) {
  try {
    // all campuses
    $campuses = $pdo->query("SELECT id,name,code FROM campuses ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

    $ins = $pdo->prepare("INSERT IGNORE INTO wall_codes(code, description) VALUES(?, ?)");
    foreach ($campuses as $c) {
      $payload = "CAMPUS:{$c['id']}";
      $desc = "Campus {$c['name']} ({$c['code']}) wall QR";
      $ins->execute([$payload, $desc]);
    }

    // Beirut blocks (name='Beirut' OR code='BEI')
    $bei = $pdo->query("SELECT id FROM campuses WHERE code='BEI' OR name='Beirut'")->fetch(PDO::FETCH_ASSOC);
    if ($bei) {
      $st = $pdo->prepare("SELECT id,name FROM blocks WHERE campus_id=? ORDER BY name");
      $st->execute([$bei['id']]);
      $blocks = $st->fetchAll(PDO::FETCH_ASSOC);
      foreach ($blocks as $b) {
        $payload = "CAMPUS:{$bei['id']}|BLOCK:{$b['id']}";
        $desc = "Beirut Block {$b['name']} wall QR";
        $ins->execute([$payload, $desc]);
      }
    }

    $success = "Wall codes verified/created.";
  } catch (Throwable $e) {
    $error = "Error: ".$e->getMessage();
  }
}

// Fetch wall codes to render
$rows = $pdo->query("
  SELECT id, code, description
  FROM wall_codes
  ORDER BY id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Wall Codes (QR) - LIU Parking";
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
:root{ --primary:#003366; --secondary:#FFB81C; --sidebar:280px; }
body{ font-family:system-ui, -apple-system, Segoe UI, Roboto, Inter, sans-serif; background:#f5f7fa; }
.sidebar{ position:fixed; left:0; top:0; width:var(--sidebar); height:100vh; color:#fff;
  background:linear-gradient(135deg,var(--primary),#004080); padding:18px; }
.sidebar a{ color:rgba(255,255,255,.85); text-decoration:none; display:block; padding:10px 14px; border-radius:8px; }
.sidebar a.active,.sidebar a:hover{ background:rgba(255,255,255,.12); color:#fff; }
.main{ margin-left:var(--sidebar); }
.header{ background:#fff; border-bottom:1px solid #e5e7eb; padding:16px 24px; position:sticky; top:0; z-index:5;}
.qcard{ border:1px solid #e5e7eb; border-radius:12px; padding:16px; background:#fff; text-align:center; }
.qr{ width:220px; height:220px; margin:0 auto 8px auto; }
.small-muted{ color:#6b7280; font-size:12px; }
@media print{
  .sidebar, .header, .toolbar, .btn, .alert{ display:none !important; }
  .container{ max-width:none; }
  .col-print-3{ width:25%; padding:8px; float:left; }
}
</style>
</head>
<body>
<nav class="sidebar">
  <div class="mb-3 text-center">
    <div style="width:50px;height:50px;border-radius:12px;background:var(--secondary);display:flex;align-items:center;justify-content:center;margin:0 auto 8px;"><i class="fa-solid fa-qrcode text-white"></i></div>
    <div class="fw-semibold">LIU Parking</div>
    <small class="text-white-50">Admin</small>
  </div>
  <a href="index.php"><i class="fa-solid fa-gauge-high me-2"></i>Dashboard</a>
  <a href="users.php"><i class="fa-solid fa-users me-2"></i>Users</a>
  <a href="campuses.php"><i class="fa-solid fa-university me-2"></i>Campuses</a>
  <a href="gates.php"><i class="fa-solid fa-door-open me-2"></i>Gates</a>
  <a class="active" href="wall_codes.php"><i class="fa-solid fa-qrcode me-2"></i>Wall Codes</a>
  <a href="spots.php"><i class="fa-solid fa-parking me-2"></i>Spots</a>
  <a href="sessions.php"><i class="fa-solid fa-clock-rotate-left me-2"></i>Sessions</a>
  <hr class="border border-light opacity-25">
  <a href="settings.php"><i class="fa-solid fa-gear me-2"></i>Settings</a>
  <a href="../logout.php"><i class="fa-solid fa-right-from-bracket me-2"></i>Logout</a>
</nav>

<div class="main">
  <div class="header d-flex align-items-center">
    <h5 class="mb-0"><i class="fa-solid fa-qrcode text-primary me-2"></i>Wall Codes (QR)</h5>
    <div class="ms-auto d-flex gap-2">
      <form method="post">
        <button class="btn btn-primary btn-sm" name="ensure_codes"><i class="fa-solid fa-wand-magic-sparkles me-1"></i> Ensure/Generate</button>
      </form>
      <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-print me-1"></i>Print</button>
    </div>
  </div>

  <div class="container py-3">
    <?php if($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif;?>
    <?php if($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif;?>

    <div class="row g-3">
      <?php foreach($rows as $r): ?>
        <div class="col-12 col-sm-6 col-lg-3 col-print-3">
          <div class="qcard">
            <div class="qr" data-payload="<?= htmlspecialchars($r['code']) ?>"></div>
            <div class="fw-semibold"><?= htmlspecialchars($r['description'] ?: $r['code']) ?></div>
            <div class="small-muted"><?= htmlspecialchars($r['code']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if(!$rows): ?>
        <div class="col-12"><div class="alert alert-info">No wall codes yet. Click <b>Ensure/Generate</b>.</div></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.querySelectorAll('.qr').forEach(el=>{
  const text = el.getAttribute('data-payload');
  new QRCode(el, {text, width:220, height:220, correctLevel: QRCode.CorrectLevel.H});
});
</script>
</body>
</html>
