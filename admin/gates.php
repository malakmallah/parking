<?php
/**
 * LIU Parking System — Printable QR Cards (Shared Box Code)
 * File: /index/gates.php
 *
 * - If no campus is chosen, shows a campus (and optional block) picker.
 * - For a selected campus (and optional block), fetches ONE shared code from barcode_boxes
 *   and uses it for ALL user cards (everyone scans the same code).
 */

session_start();

$db_host='localhost'; $db_name='parking'; $db_user='root'; $db_pass='';
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",$db_user,$db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) { die("DB error: ".$e->getMessage()); }

/* ------------ Inputs ------------ */
$campus_id = isset($_GET['campus_id']) && $_GET['campus_id'] !== '' ? (int)$_GET['campus_id'] : 0;
$block_id  = isset($_GET['block_id'])  && $_GET['block_id']  !== '' ? (int)$_GET['block_id']  : null;

/* ------------ Data for selectors ------------ */
$campuses = $pdo->query("SELECT id, name, code FROM campuses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$blocks = [];
$campus = null;
$block  = null;

if ($campus_id) {
    $st = $pdo->prepare("SELECT id, name, code FROM campuses WHERE id=?");
    $st->execute([$campus_id]);
    $campus = $st->fetch(PDO::FETCH_ASSOC);

    $stb = $pdo->prepare("SELECT id, name FROM blocks WHERE campus_id=? ORDER BY name");
    $stb->execute([$campus_id]);
    $blocks = $stb->fetchAll(PDO::FETCH_ASSOC);

    if ($block_id !== null) {
        $stb2 = $pdo->prepare("SELECT id, name FROM blocks WHERE id=? AND campus_id=?");
        $stb2->execute([$block_id, $campus_id]);
        $block = $stb2->fetch(PDO::FETCH_ASSOC);
        if (!$block) { $block_id = null; } // safety
    }
}

/* ------------ When a campus is selected, fetch shared code & users ------------ */
$box_code = null;
$users = [];

if ($campus_id && $campus) {
    if ($block_id === null) {
        $st = $pdo->prepare("SELECT code FROM barcode_boxes WHERE campus_id=? AND block_id IS NULL LIMIT 1");
        $st->execute([$campus_id]);
    } else {
        $st = $pdo->prepare("SELECT code FROM barcode_boxes WHERE campus_id=? AND block_id=? LIMIT 1");
        $st->execute([$campus_id, $block_id]);
    }
    $box_code = $st->fetchColumn();

    $sql = "
      SELECT u.id, u.FIRST, u.Last, u.Email
      FROM users u
      WHERE u.campus_id = ?
        AND u.role IN ('staff','instructor')
    ";
    $params = [$campus_id];
    if ($block_id !== null) { $sql .= " AND u.block_id = ? "; $params[] = $block_id; }
    $sql .= " ORDER BY u.Last, u.FIRST ";

    $stu = $pdo->prepare($sql);
    $stu->execute($params);
    $users = $stu->fetchAll(PDO::FETCH_ASSOC);
}

/* ------------ UI ------------ */
$pageTitle = $campus_id && $campus
    ? "QR Cards — {$campus['name']} ({$campus['code']})".($block ? " — {$block['name']}" : "")
    : "QR Cards — Select Campus";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<style>
  :root{ --primary:#003366; --secondary:#FFB81C; --sidebar-w:280px; }
  body{ background:#f7f9fc; }
  .sidebar{ position:fixed; top:0; left:0; height:100vh; width:var(--sidebar-w);
    background:linear-gradient(135deg, var(--primary) 0%, #004080 100%); color:#fff; }
  .main{ margin-left:var(--sidebar-w); min-height:100vh; }
  .header{ background:#fff; border-bottom:1px solid #e9ecef; padding:16px 24px; position:sticky; top:0; z-index:5;}
  .grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:16px; }
  .card-qr {
    border:1px solid #e5e7eb; border-radius:12px; padding:16px; background:#fff;
    box-shadow:0 2px 10px rgba(0,0,0,.05); height:100%;
  }
  .code { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
          letter-spacing:1px; font-weight:600; }
  @media print{
    .sidebar, .no-print { display:none !important; }
    .main{ margin-left:0 !important; }
    body { background:#fff; }
  }
</style>
</head>
<body>

<?php
// Sidebar include with path fallbacks
foreach ([
    __DIR__ . '/includes/sidebar.php',
    dirname(__DIR__) . '/includes/sidebar.php',
    dirname(__DIR__) . '/admin/includes/sidebar.php'
] as $p) { if (is_file($p)) { include $p; break; } }
?>

<div class="main">
  <div class="header d-flex align-items-center">
    <h4 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h4>
    <div class="ms-auto d-flex gap-2 no-print">
      <?php if ($campus_id): ?>
        <a href="gates.php" class="btn btn-sm btn-light">Change</a>
        <button type="button" class="btn btn-sm btn-primary" onclick="window.print()">Print</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="container py-4">

    <?php if (!$campus_id): ?>
      <!-- Campus / Block picker -->
      <div class="card">
        <div class="card-header"><strong>Select Campus (and Block if applicable)</strong></div>
        <div class="card-body">
          <form class="row g-3" method="get">
            <div class="col-md-6">
              <label class="form-label">Campus</label>
              <select name="campus_id" id="campusSelect" class="form-select" required
                      onchange="location.href='gates.php?campus_id='+this.value">
                <option value="">Select campus…</option>
                <?php foreach($campuses as $c): ?>
                  <option value="<?= (int)$c['id'] ?>">
                    <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['code']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <div class="form-text">After selecting a campus, the page will reload and show blocks (if any).</div>
            </div>
          </form>
        </div>
      </div>

    <?php else: ?>
      <!-- Optional block selector (if campus has blocks) -->
      <?php if (!empty($blocks)): ?>
        <form class="row g-3 no-print mb-3" method="get">
          <input type="hidden" name="campus_id" value="<?= (int)$campus_id ?>">
          <div class="col-md-6">
            <label class="form-label">Block</label>
            <select name="block_id" class="form-select" onchange="this.form.submit()">
              <option value="">— Whole campus —</option>
              <?php foreach($blocks as $b): ?>
                <option value="<?= (int)$b['id'] ?>" <?= $block_id===(int)$b['id']?'selected':'' ?>>
                  <?= htmlspecialchars($b['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
      <?php endif; ?>

      <?php if (!$box_code): ?>
        <div class="alert alert-warning">
          No barcode box code defined for this <?= $block ? 'block' : 'campus' ?>.
          Set it in <a href="../admin/gates.php?campus_id=<?= (int)$campus_id ?><?= $block_id!==null?'&block_id='.(int)$block_id:'' ?>" target="_blank">Admin → Gates</a>.
        </div>
      <?php else: ?>
        <!-- One big QR with shared code -->
        <div class="card-qr mb-4">
          <div class="row g-3 align-items-center">
            <div class="col-auto">
              <?php $qrBig = "https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=".rawurlencode($box_code); ?>
              <img src="<?= $qrBig ?>" alt="Box QR" width="240" height="240">
            </div>
            <div class="col">
              <h5 class="mb-1">
                <?= htmlspecialchars($campus['name']) ?> (<?= htmlspecialchars($campus['code']) ?>)
                <?= $block ? ' — '.htmlspecialchars($block['name']) : '' ?>
              </h5>
              <div class="text-muted mb-2">Shared barcode for this <?= $block ? 'block' : 'campus' ?>.</div>
              <div class="code fs-5"><?= htmlspecialchars($box_code) ?></div>
            </div>
          </div>
        </div>

        <!-- Users (each card shows the same shared code) -->
        <?php if (!$users): ?>
          <div class="alert alert-info">No users found for this selection.</div>
        <?php else: ?>
          <div class="grid">
            <?php
              $qrSmall = "https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=".rawurlencode($box_code);
              foreach ($users as $u):
                $full = trim(($u['FIRST'] ?? '').' '.($u['Last'] ?? ''));
            ?>
              <div class="card-qr">
                <div class="d-flex align-items-center justify-content-between mb-2">
                  <div>
                    <div class="fw-semibold"><?= htmlspecialchars($full ?: '—') ?></div>
                    <div class="text-muted small"><?= htmlspecialchars($u['Email'] ?? '') ?></div>
                  </div>
                  <div class="text-muted small"><?= htmlspecialchars($campus['code']) ?></div>
                </div>
                <div class="text-center mb-2">
                  <img src="<?= $qrSmall ?>" alt="QR <?= htmlspecialchars($box_code) ?>" width="180" height="180">
                </div>
                <div class="text-center code"><?= htmlspecialchars($box_code) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
