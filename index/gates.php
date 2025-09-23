<?php
/**
 * Printable QR Sheet
 * Location: /index/gates.php
 *
 * Query params:
 *   campus_id (required)
 *   block_id  (optional; for Beirut)
 *
 * Encoded payload for each USER QR:
 *   LIU|C:<campus_id>|B:<block_id_or_0>|U:<user_id>|N:<user_barcode_or_parking_number>
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

$campus_id = isset($_GET['campus_id']) ? (int)$_GET['campus_id'] : 0;
$block_id  = isset($_GET['block_id'])  ? (int)$_GET['block_id']  : 0;

if (!$campus_id) { die("Missing campus_id"); }

$camp = $pdo->prepare("SELECT id,name,code FROM campuses WHERE id=?");
$camp->execute([$campus_id]);
$campus = $camp->fetch(PDO::FETCH_ASSOC);
if (!$campus) { die("Campus not found"); }

// find wall code payload
$payload = $block_id
  ? "CAMPUS:{$campus_id}|BLOCK:{$block_id}"
  : "CAMPUS:{$campus_id}";

$wall = $pdo->prepare("SELECT id,code,description FROM wall_codes WHERE code=?");
$wall->execute([$payload]);
$wall_code = $wall->fetch(PDO::FETCH_ASSOC);

// list users for that campus (optionally filter by your model if you add users.block_id later)
$users = $pdo->prepare("
  SELECT id, FIRST, Last, Email,
         COALESCE(NULLIF(user_barcode,''), NULLIF(parking_number,'')) AS barcode
  FROM users
  WHERE campus_id=? AND role IN ('staff','instructor') AND
        COALESCE(user_barcode, parking_number) IS NOT NULL AND COALESCE(user_barcode, parking_number) <> ''
  ORDER BY FIRST, Last
");
$users->execute([$campus_id]);
$users = $users->fetchAll(PDO::FETCH_ASSOC);

$title = "QR Cards — {$campus['name']} ({$campus['code']})".($block_id? " — Block #$block_id":"");
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($title) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
body{ font-family:Inter, system-ui, -apple-system, Segoe UI, Roboto, sans-serif; background:#fff; }
.toolbar{ position:sticky; top:0; z-index:5; background:#fff; border-bottom:1px solid #e5e7eb; }
.wall{ border:1px solid #e5e7eb; border-radius:12px; padding:14px; margin-bottom:14px; }
.qr-lg{ width:240px;height:240px; }
.card-q{ border:1px solid #e5e7eb; border-radius:12px; padding:14px; text-align:center; }
.qr{ width:170px;height:170px;margin:0 auto 8px; }
.name{ font-weight:600; font-size:14px; }
.num{ font-size:12px; color:#6b7280; }
@media print{
  .toolbar{ display:none !important; }
  .col-print-3{ width:25%; float:left; padding:6px; }
}
</style>
</head>
<body>
<div class="toolbar py-2">
  <div class="container d-flex align-items-center gap-2">
    <h6 class="mb-0"><?= htmlspecialchars($title) ?></h6>
    <div class="ms-auto d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="../admin/gates.php"><i class="fa-solid fa-arrow-left me-1"></i>Back</a>
      <button onclick="window.print()" class="btn btn-primary btn-sm"><i class="fa-solid fa-print me-1"></i>Print</button>
    </div>
  </div>
</div>

<div class="container py-3">
  <div class="wall d-flex align-items-center gap-3">
    <div class="qr-lg" id="wall_qr" data-payload="<?= htmlspecialchars($wall_code['code'] ?? $payload) ?>"></div>
    <div>
      <h5 class="mb-1"><?= htmlspecialchars($campus['name']) ?> (<?= htmlspecialchars($campus['code']) ?>)</h5>
      <?php if($block_id): ?><div class="text-muted">Block ID: <?= (int)$block_id ?></div><?php endif; ?>
      <div class="text-muted small">Scan at the gate/wall. Payload: <code><?= htmlspecialchars($wall_code['code'] ?? $payload) ?></code></div>
    </div>
  </div>

  <?php if(!$users): ?>
    <div class="alert alert-warning">No users with barcodes yet in this campus.</div>
  <?php endif; ?>

  <div class="row g-3">
    <?php foreach($users as $u):
      $code = trim($u['barcode']);
      $payloadUser = "LIU|C:{$campus_id}|B:".($block_id?:0)."|U:{$u['id']}|N:{$code}";
    ?>
      <div class="col-12 col-sm-6 col-md-4 col-lg-3 col-print-3">
        <div class="card-q">
          <div class="qr" data-payload="<?= htmlspecialchars($payloadUser) ?>"></div>
          <div class="name"><?= htmlspecialchars(($u['FIRST']??'').' '.($u['Last']??'')) ?></div>
          <div class="num"><?= htmlspecialchars($code) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
function renderQR(el, size){
  const text = el.getAttribute('data-payload') || '';
  new QRCode(el, { text, width:size, height:size, correctLevel: QRCode.CorrectLevel.H });
}
renderQR(document.getElementById('wall_qr'), 240);
document.querySelectorAll('.qr').forEach(el=>renderQR(el, 170));
</script>
</body>
</html>
