<?php
/**
 * LIU Parking System - Wall Codes (Shared Box Code)
 * Location: /admin/wall_codes.php
 */

session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
  header('Location: ../login.php'); exit;
}

$db_host='localhost'; $db_name='parking'; $db_user='root'; $db_pass='';
try {
  $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",$db_user,$db_pass);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) { die("DB error: ".$e->getMessage()); }

$success=''; $error='';

/* ---------- Ensure parking_sessions exists (lightweight, no FKs) ---------- */
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS parking_sessions (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      user_id BIGINT NULL,
      wall_code_id BIGINT NULL,
      barcode_code VARCHAR(64) NULL,
      entry_time DATETIME NULL,
      exit_time  DATETIME NULL,
      status ENUM('active','completed') DEFAULT 'active',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      KEY idx_user (user_id),
      KEY idx_wall (wall_code_id),
      KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch(PDOException $e) {
  // ignore; table may already exist with different shape
}

/* ---------- Generate wall codes (no SQL CONCAT used) ---------- */
if (isset($_POST['ensure_codes'])) {
  try {
    $campuses = $pdo->query("SELECT id,name,code FROM campuses ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $ins = $pdo->prepare("INSERT IGNORE INTO wall_codes(code, description) VALUES(?, ?)");

    foreach ($campuses as $c) {
      $payload = "CAMPUS:".$c['id'];
      $desc    = "Campus ".$c['name']." (".$c['code'].") - Entry/Exit Gate";
      $ins->execute([$payload, $desc]);
    }

    // Beirut blocks (detect by code/name)
    $bei = $pdo->query("SELECT id,name,code FROM campuses WHERE code='BEI' OR name='Beirut'")->fetch(PDO::FETCH_ASSOC);
    if ($bei) {
      $st = $pdo->prepare("SELECT id,name FROM blocks WHERE campus_id=? ORDER BY name");
      $st->execute([$bei['id']]);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $payload = "CAMPUS:".$bei['id']."|BLOCK:".$b['id'];
        $desc    = "Beirut Block ".$b['name']." - Entry/Exit Gate";
        $ins->execute([$payload, $desc]);
      }
    }

    $success = "Wall codes verified/created.";
  } catch (Throwable $e) {
    $error = "Error creating wall codes: ".$e->getMessage();
  }
}

/* ---------- Handle preview request (shared box code) ---------- */
$previewWall = null;
$sharedBoxCode = null;
$previewUsers = [];

if (isset($_POST['preview_shared']) && !empty($_POST['wall_code_id'])) {
  try {
    // Load selected wall code row
    $st = $pdo->prepare("SELECT id, code, description FROM wall_codes WHERE id=?");
    $st->execute([ (int)$_POST['wall_code_id'] ]);
    $previewWall = $st->fetch(PDO::FETCH_ASSOC);

    if ($previewWall) {
      // Parse "CAMPUS:<id>" and optional "|BLOCK:<id>"
      $campusId = null; $blockId = null;
      if (preg_match('/CAMPUS:(\d+)/', $previewWall['code'], $m1)) { $campusId = (int)$m1[1]; }
      if (preg_match('/BLOCK:(\d+)/',  $previewWall['code'], $m2)) { $blockId  = (int)$m2[1]; }

      if (!$campusId) { throw new Exception("Wall code payload missing CAMPUS id."); }

      // Fetch the ONE shared code from barcode_boxes
      if ($blockId === null) {
        $q = $pdo->prepare("SELECT code FROM barcode_boxes WHERE campus_id=? AND block_id IS NULL LIMIT 1");
        $q->execute([$campusId]);
      } else {
        $q = $pdo->prepare("SELECT code FROM barcode_boxes WHERE campus_id=? AND block_id=? LIMIT 1");
        $q->execute([$campusId, $blockId]);
      }
      $sharedBoxCode = $q->fetchColumn();
      if (!$sharedBoxCode) {
        throw new Exception("No barcode box defined for this selection. Set it in Admin → Gates.");
      }

      // Optional: load users (names only) for the selected scope
      $sql = "SELECT id, FIRST, `Last`, Email FROM users WHERE campus_id=? AND role IN ('staff','instructor')";
      $params = [$campusId];
      if ($blockId !== null) { $sql .= " AND block_id=?"; $params[] = $blockId; }
      $sql .= " ORDER BY `Last`, FIRST";
      $u = $pdo->prepare($sql); $u->execute($params);
      $previewUsers = $u->fetchAll(PDO::FETCH_ASSOC);
    }
  } catch (Throwable $e) {
    $error = "Preview error: ".$e->getMessage();
    $previewWall = null; $sharedBoxCode = null; $previewUsers = [];
  }
}

/* ---------- Fetch wall codes grid ---------- */
$rows = $pdo->query("
  SELECT wc.id, wc.code, wc.description,
         COALESCE(SUM(ps.status='active'),0) AS active_sessions
  FROM wall_codes wc
  LEFT JOIN parking_sessions ps
    ON wc.id = ps.wall_code_id
  GROUP BY wc.id, wc.code, wc.description
  ORDER BY wc.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Wall Codes (Shared Box Code) - LIU Parking";
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --primary: #003366;
    --gold: #FFB81C;
}

body {
    font-family: 'Inter', sans-serif;
    background: #f5f7fa;
    margin: 0;
    padding: 0;
}

.header {
    background: #fff;
    border-bottom: 1px solid #e5e7eb;
    padding: 20px 30px;
    position: sticky;
    top: 0;
    z-index: 999;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.header h1 {
    color: var(--primary);
    font-size: 24px;
    margin: 0;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.content-area {
    padding: 30px;
}

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 500;
    transition: all 0.3s;
    font-size: 14px;
}

.btn-primary {
    background: var(--primary);
    color: white;
}

.btn-primary:hover {
    background: #004080;
    transform: translateY(-1px);
}

.btn-success {
    background: #28a745;
    color: white;
}

.btn-success:hover {
    background: #218838;
    transform: translateY(-1px);
}

.btn-outline-secondary {
    border: 1px solid #6c757d;
    color: #6c757d;
    background: transparent;
}

.btn-outline-secondary:hover {
    background: #6c757d;
    color: white;
}

.btn-sm {
    padding: 6px 12px;
    font-size: 12px;
}

.qcard {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
    background: #fff;
    text-align: center;
    position: relative;
    transition: all 0.3s;
    box-shadow: 0 2px 10px rgba(0,0,0,0.04);
}

.qcard:hover {
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.qcard.has-users {
    border-left: 4px solid var(--gold);
}

.qr {
    width: 180px;
    height: 180px;
    margin: 0 auto 12px auto;
    border-radius: 8px;
    overflow: hidden;
}

.user-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 15px;
    margin-top: 20px;
}

.user-card {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: 15px;
    text-align: center;
    transition: all 0.3s;
}

.user-card:hover {
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.user-card .qr {
    width: 140px;
    height: 140px;
}

.small-muted {
    color: #6b7280;
    font-size: 12px;
}

.badge-sessions {
    position: absolute;
    top: 12px;
    right: 12px;
}

.wall-selector {
    background: #fff;
    padding: 25px;
    border-radius: 12px;
    margin-bottom: 25px;
    border: 1px solid #e9ecef;
    box-shadow: 0 2px 10px rgba(0,0,0,0.04);
}

.wall-selector h6 {
    color: var(--primary);
    font-weight: 600;
    margin-bottom: 15px;
}

.code {
    font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace;
    font-weight: 600;
    background: #f8f9fa;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 13px;
}

.alert {
    border-radius: 10px;
    border: none;
    padding: 15px 20px;
}

.alert-success {
    background: linear-gradient(135deg, #d4edda, #c3e6cb);
    color: #155724;
}

.alert-danger {
    background: linear-gradient(135deg, #f8d7da, #f1b0b7);
    color: #721c24;
}

.form-select,
.form-control {
    border-radius: 8px;
    border: 1px solid #d1d5db;
    transition: all 0.3s;
}

.form-select:focus,
.form-control:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(0,51,102,0.1);
}

@media print {
    .sidebar,
    .header,
    .toolbar,
    .btn,
    .alert,
    .wall-selector {
        display: none !important;
    }
    .container {
        max-width: none;
    }
    .col-print-6 {
        width: 50%;
        padding: 8px;
        float: left;
    }
    .qr {
        width: 120px !important;
        height: 120px !important;
    }
}

@media (max-width: 768px) {
    .content-area {
        padding: 20px;
    }
    .wall-selector {
        padding: 20px;
    }
    .header {
        padding: 15px 20px;
    }
    .header h1 {
        font-size: 20px;
    }
    .qr {
        width: 150px;
        height: 150px;
    }
    .user-card .qr {
        width: 120px;
        height: 120px;
    }
}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>

<!-- CORRECTED: Use main-content class -->
<div class="main-content">
  <div class="header d-flex align-items-center justify-content-between">
    <h1><i class="fa-solid fa-qrcode text-primary"></i>Wall Codes (Shared Box Code)</h1>
    <div class="d-flex gap-2">
      <form method="post" class="d-inline">
        <button class="btn btn-primary btn-sm" name="ensure_codes">
          <i class="fa-solid fa-wand-magic-sparkles me-1"></i> Generate Wall Codes
        </button>
      </form>
      <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
        <i class="fa-solid fa-print me-1"></i>Print
      </button>
    </div>
  </div>

  <div class="content-area">
    <?php if($success): ?>
      <div class="alert alert-success">
        <i class="fa fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
      </div>
    <?php endif;?>
    
    <?php if($error): ?>
      <div class="alert alert-danger">
        <i class="fa fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
      </div>
    <?php endif;?>

    <!-- Preview shared code selector -->
    <div class="wall-selector">
      <h6><i class="fa-solid fa-barcode me-2"></i>Preview Shared Code for a Gate</h6>
      <form method="post" class="row g-3 align-items-end">
        <div class="col-md-8">
          <label class="form-label">Select Gate/Location:</label>
          <select name="wall_code_id" class="form-select" required>
            <option value="">Select a gate/location…</option>
            <?php foreach($rows as $r): ?>
              <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['description'] ?: $r['code']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <button class="btn btn-success" name="preview_shared" value="1">
            <i class="fa-solid fa-eye me-1"></i> Preview Shared Code
          </button>
        </div>
      </form>
    </div>

    <!-- Shared code preview -->
    <?php if ($previewWall && $sharedBoxCode): ?>
      <div class="mb-4 p-4 bg-white rounded-3 border">
        <h6 class="text-primary mb-1">
          <i class="fa-solid fa-location-dot me-2"></i><?= htmlspecialchars($previewWall['description'] ?: $previewWall['code']) ?>
        </h6>
        <div class="small text-muted mb-3">Wall Code Payload: <span class="code"><?= htmlspecialchars($previewWall['code']) ?></span></div>

        <div class="row g-3 align-items-center">
          <div class="col-auto">
            <div class="qr" id="shared-qr"></div>
          </div>
          <div class="col">
            <div class="mb-1 fw-semibold">Shared Barcode Box Code</div>
            <div class="code fs-5" id="shared-text"><?= htmlspecialchars($sharedBoxCode) ?></div>
            <div class="small text-muted">All users in this campus/block scan this same code.</div>
          </div>
        </div>

        <?php if (!empty($previewUsers)): ?>
          <hr class="my-4">
          <div class="small text-muted mb-2">User cards (all show the same shared code)</div>
          <div class="user-grid">
            <?php foreach($previewUsers as $u): 
              $full = trim(($u['FIRST'] ?? '').' '.($u['Last'] ?? '')); ?>
              <div class="user-card">
                <div class="fw-semibold mb-2" style="font-size:13px;"><?= htmlspecialchars($full ?: '—') ?></div>
                <div class="qr user-qr" data-code="<?= htmlspecialchars($sharedBoxCode) ?>"></div>
                <div class="text-center small mt-2 code"><?= htmlspecialchars($sharedBoxCode) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Wall Codes Grid -->
    <h6 class="mb-3"><i class="fa-solid fa-door-open me-2"></i>Gate/Location QR Codes</h6>
    <div class="row g-3">
      <?php foreach($rows as $r): ?>
        <div class="col-12 col-sm-6 col-lg-4 col-print-6">
          <div class="qcard <?= ((int)$r['active_sessions']) > 0 ? 'has-users' : '' ?>">
            <?php if(((int)$r['active_sessions']) > 0): ?>
              <span class="badge bg-success badge-sessions"><?= (int)$r['active_sessions'] ?> active</span>
            <?php endif; ?>
            <div class="qr gate-qr" data-payload="GATE:<?= htmlspecialchars($r['code']) ?>"></div>
            <div class="fw-semibold mb-1"><?= htmlspecialchars($r['description'] ?: $r['code']) ?></div>
            <div class="small-muted">Gate ID: <span class="code"><?= htmlspecialchars($r['code']) ?></span></div>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if(!$rows): ?>
        <div class="col-12">
          <div class="alert alert-info">
            <i class="fa fa-info-circle me-2"></i>No wall codes yet. Click <b>Generate Wall Codes</b>.
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
// Gate QR codes
document.querySelectorAll('.gate-qr').forEach(el=>{
  const text = el.getAttribute('data-payload') || '';
  new QRCode(el, {text, width:180, height:180, correctLevel: QRCode.CorrectLevel.H});
});

// Shared big QR
const sharedText = document.getElementById('shared-text');
const sharedQR   = document.getElementById('shared-qr');
if (sharedQR && sharedText) {
  new QRCode(sharedQR, {text: sharedText.textContent, width:180, height:180, correctLevel: QRCode.CorrectLevel.H});
}

// Per-user cards: all with the same shared code
document.querySelectorAll('.user-qr').forEach(el=>{
  const c = el.getAttribute('data-code') || '';
  new QRCode(el, {text: c, width:140, height:140, correctLevel: QRCode.CorrectLevel.H});
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>