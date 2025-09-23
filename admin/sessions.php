<?php
/**
 * LIU Parking System - Sessions (READ-ONLY)
 * File: admin/sessions.php
 * - Lists parking sessions with user, campus, spot and gate (if present)
 * - Read-only (no add/edit/delete)
 * - Search, filters (campus, date range, status), pagination
 * - Auto-detects table/column names to avoid 1054 errors
 */

session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
  header('Location: ../login.php'); exit;
}

// ---- DB ----
$db_host='localhost'; $db_name='parking'; $db_user='root'; $db_pass='';
try {
  $pdo=new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",$db_user,$db_pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
} catch(PDOException $e){ die('DB connection failed: '.$e->getMessage()); }

// ---- helpers ----
function tableExists(PDO $pdo, string $table): bool {
  $s=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $s->execute([$table]); return (int)$s->fetchColumn() > 0;
}
function colExists(PDO $pdo, string $table, string $col): bool {
  $s=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
  $s->execute([$table,$col]); return (int)$s->fetchColumn() > 0;
}
function pickFirstExisting(PDO $pdo, string $table, array $candidates): array {
  foreach ($candidates as $alias => $name) {
    if (is_int($alias)) $alias = $name;
    if (colExists($pdo,$table,$name)) return [$alias, "g.$name"]; // g = main sessions alias
  }
  return [null, "NULL"];
}

// ---- detect main sessions table ----
$sessionTable = tableExists($pdo,'parking_sessions') ? 'parking_sessions' : (tableExists($pdo,'sessions') ? 'sessions' : null);
if (!$sessionTable) { die('No sessions table found (expected `parking_sessions` or `sessions`).'); }

// detect key/foreign columns
$hasUserId   = colExists($pdo,$sessionTable,'user_id');
$hasCampusId = colExists($pdo,$sessionTable,'campus_id');
$hasSpotId   = colExists($pdo,$sessionTable,'spot_id') || colExists($pdo,$sessionTable,'parking_spot_id');
$spotCol     = colExists($pdo,$sessionTable,'spot_id') ? 'g.spot_id' : (colExists($pdo,$sessionTable,'parking_spot_id') ? 'g.parking_spot_id' : 'NULL');
$hasGateId   = colExists($pdo,$sessionTable,'gate_id');

// detect time columns
list($entryAlias,$entryCol) = pickFirstExisting($pdo,$sessionTable,[
  'entry_at'     => 'entry_at',
  'enter_at'     => 'enter_at',
  'entrance_at'  => 'entrance_at',
  'start_at'     => 'start_at',
  'created_at'   => 'created_at',
  'in_at'        => 'in_at'
]);
list($exitAlias,$exitCol) = pickFirstExisting($pdo,$sessionTable,[
  'exit_at'      => 'exit_at',
  'exit_time'    => 'exit_time',
  'leave_at'     => 'leave_at',
  'out_at'       => 'out_at',
  'ended_at'     => 'ended_at'
]);

// other likely columns
$hasPlate = colExists($pdo,$sessionTable,'plate') || colExists($pdo,$sessionTable,'plate_number');
$plateCol = colExists($pdo,$sessionTable,'plate') ? 'g.plate' : (colExists($pdo,$sessionTable,'plate_number') ? 'g.plate_number' : 'NULL');
$hasParkingNumber = colExists($pdo,$sessionTable,'parking_number');
$parkingNumCol    = $hasParkingNumber ? 'g.parking_number' : 'NULL';
$hasStatus = colExists($pdo,$sessionTable,'status');

// supporting tables presence
$hasUsers   = tableExists($pdo,'users');
$hasSpots   = tableExists($pdo,'parking_spots');
$hasGates   = tableExists($pdo,'gates');
$hasBlocks  = tableExists($pdo,'blocks');
$hasCampuses= tableExists($pdo,'campuses');

// ---- filters ----
$q          = trim($_GET['q'] ?? '');
$status     = trim($_GET['status'] ?? ''); // '', active, closed
$campus_id  = (int)($_GET['campus_id'] ?? 0);
$date_from  = trim($_GET['date_from'] ?? '');
$date_to    = trim($_GET['date_to'] ?? '');
$page       = max(1,(int)($_GET['page'] ?? 1));
$per        = 15;
$off        = ($page-1)*$per;

$where = [];
$params = [];

// status filter (open sessions => no exit timestamp)
if ($status === 'active' && $exitAlias) {
  $where[] = "$exitCol IS NULL";
}
if ($status === 'closed' && $exitAlias) {
  $where[] = "$exitCol IS NOT NULL";
}

// campus filter
if ($campus_id && $hasCampusId) {
  $where[] = "g.campus_id = ?";
  $params[] = $campus_id;
}

// date range filter (on entry column if present)
if ($entryAlias && $date_from !== '') {
  $where[] = "$entryCol >= ?";
  $params[] = $date_from . " 00:00:00";
}
if ($entryAlias && $date_to !== '') {
  $where[] = "$entryCol <= ?";
  $params[] = $date_to . " 23:59:59";
}

// search
$searchParts = [];
if ($q !== '') {
  if ($hasPlate)          { $searchParts[] = "$plateCol LIKE ?";           $params[]="%$q%"; }
  if ($hasParkingNumber)  { $searchParts[] = "$parkingNumCol LIKE ?";      $params[]="%$q%"; }
  if ($hasUsers)          { $searchParts[] = "(u.FIRST LIKE ? OR u.Last LIKE ? OR u.Email LIKE ?)"; array_push($params,"%$q%","%$q%","%$q%"); }
  if ($hasSpots)          { $searchParts[] = "ps.spot_number LIKE ?";      $params[]="%$q%"; }
  if ($hasGates)          { 
    $gateNameCol = colExists($pdo,'gates','name')?'name':(colExists($pdo,'gates','gate_name')?'gate_name':null);
    if ($gateNameCol) { $searchParts[] = "gt.$gateNameCol LIKE ?"; $params[]="%$q%"; }
  }
  if ($hasCampuses)       { $searchParts[] = "(c.name LIKE ? OR c.code LIKE ?)"; array_push($params,"%$q%","%$q%"); }
}
if ($searchParts) $where[] = '(' . implode(' OR ', $searchParts) . ')';

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ---- lookups for campus dropdown ----
$campusList = [];
if ($hasCampuses) {
  $campusList = $pdo->query("SELECT id, name, code FROM campuses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

// ---- counts ----
$countSql = "
  SELECT COUNT(*)
  FROM {$sessionTable} g
  ".($hasUsers   ? "LEFT JOIN users u ON ".($hasUserId?"u.id = g.user_id":"1=0")." " : "")."
  ".($hasSpots   ? "LEFT JOIN parking_spots ps ON ".($hasSpotId?"ps.id = $spotCol":"1=0")." " : "")."
  ".($hasGates   ? "LEFT JOIN gates gt ON ".($hasGateId?"gt.id = g.gate_id":"1=0")." " : "")."
  ".($hasBlocks  ? "LEFT JOIN blocks b ON ps.block_id = b.id " : "")."
  ".($hasCampuses? "LEFT JOIN campuses c ON ".($hasCampusId?"c.id = g.campus_id":"1=0")." " : "")."
  $whereSql
";
$cnt=$pdo->prepare($countSql);
$cnt->execute($params);
$total=(int)$cnt->fetchColumn();
$pages=max(1,(int)ceil($total/$per));

// ---- select list ----
$gateName = 'NULL AS gate_name';
if ($hasGates) {
  if (colExists($pdo,'gates','name'))       $gateName = 'gt.name AS gate_name';
  elseif (colExists($pdo,'gates','gate_name')) $gateName = 'gt.gate_name AS gate_name';
}

$select = [
  "g.id",
  $entryCol . " AS entry_at",
  $exitCol  . " AS exit_at",
  $hasUsers ? "u.FIRST, u.Last, u.Email" : "NULL AS FIRST, NULL AS Last, NULL AS Email",
  $hasPlate ? "$plateCol AS plate" : "NULL AS plate",
  $hasParkingNumber ? "$parkingNumCol AS parking_number" : "NULL AS parking_number",
  $hasSpots ? "ps.spot_number" : "NULL AS spot_number",
  $hasBlocks ? "b.name AS block_name" : "NULL AS block_name",
  $hasCampuses ? "c.name AS campus_name, c.code AS campus_code" : "NULL AS campus_name, NULL AS campus_code",
  $gateName
];

$listSql = "
  SELECT ".implode(", ",$select)."
  FROM {$sessionTable} g
  ".($hasUsers   ? "LEFT JOIN users u ON ".($hasUserId?"u.id = g.user_id":"1=0")." " : "")."
  ".($hasSpots   ? "LEFT JOIN parking_spots ps ON ".($hasSpotId?"ps.id = $spotCol":"1=0")." " : "")."
  ".($hasGates   ? "LEFT JOIN gates gt ON ".($hasGateId?"gt.id = g.gate_id":"1=0")." " : "")."
  ".($hasBlocks  ? "LEFT JOIN blocks b ON ps.block_id = b.id " : "")."
  ".($hasCampuses? "LEFT JOIN campuses c ON ".($hasCampusId?"c.id = g.campus_id":"1=0")." " : "")."
  $whereSql
  ORDER BY ".($entryAlias ? "entry_at" : "g.id")." DESC
  LIMIT $per OFFSET $off
";
$stmt=$pdo->prepare($listSql);
$stmt->execute($params);
$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);

$liuLogoPath='../assets/img/liu-logo.png';
$title='Parking Sessions - LIU Parking System';

// For duration display
function formatDuration(?string $start, ?string $end): string {
  if (!$start) return '—';
  $t1 = strtotime($start);
  $t2 = $end ? strtotime($end) : time();
  if ($t1===false || $t2===false || $t2<$t1) return '—';
  $sec = $t2-$t1;
  $h = floor($sec/3600); $m = floor(($sec%3600)/60);
  if ($h>0) return $h.'h '.$m.'m';
  return $m.'m';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=htmlspecialchars($title)?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary:#003366;--gold:#FFB81C;--sidebar:280px;--header:70px}
body{font-family:'Inter',sans-serif;background:#f5f7fa}
.sidebar{position:fixed;top:0;left:0;height:100vh;width:var(--sidebar);background:linear-gradient(135deg,var(--primary),#004080);box-shadow:2px 0 10px rgba(0,0,0,.08)}
.sidebar-header{padding:20px;text-align:center;border-bottom:1px solid rgba(255,255,255,.12)}
.sidebar-header h4{color:#fff;margin:6px 0 2px;font-weight:600}
.sidebar-header p{color:rgba(255,255,255,.75);font-size:12px}
.menu-link{display:flex;align-items:center;padding:12px 15px;color:rgba(255,255,255,.85);text-decoration:none;border-radius:8px;margin:4px 12px}
.menu-link:hover,.menu-link.active{background:rgba(255,255,255,.1);color:#fff}
.menu-link i{width:20px;margin-right:10px;text-align:center}
.main{margin-left:var(--sidebar);min-height:100vh}
.header{height:var(--header);background:#fff;box-shadow:0 2px 10px rgba(0,0,0,.05);display:flex;align-items:center;padding:0 24px;position:sticky;top:0;z-index:5}
.header h1{color:var(--primary);font-size:22px;margin:0}
.content{padding:28px}
.card{border:1px solid #e9ecef;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.04)}
.table td,.table th{vertical-align:middle}
.badge-soft{background:#f1f3f5;color:#333}
@media(max-width:768px){.sidebar{transform:translateX(-100%)}.main{margin-left:0}.content{padding:18px}}
</style>
</head>
<body>
<!-- Sidebar -->
<aside class="sidebar">
  <div class="sidebar-header">
    <div style="height:50px">
      <?php if (file_exists($liuLogoPath)): ?>
        <img src="<?=htmlspecialchars($liuLogoPath)?>" style="max-height:50px" alt="LIU">
      <?php else: ?><i class="fa fa-car text-white fs-3"></i><?php endif; ?>
    </div>
    <h4>LIU Parking</h4><p>Admin Dashboard</p>
  </div>
  <nav>
    <a class="menu-link" href="index.php"><i class="fas fa-tachometer-alt"></i>Dashboard</a>
    <a class="menu-link" href="users.php"><i class="fas fa-users"></i>Users Management</a>
    <a class="menu-link" href="campuses.php"><i class="fas fa-university"></i>Campuses & Blocks</a>
    <a class="menu-link" href="spots.php"><i class="fas fa-parking"></i>Parking Spots</a>
    <a class="menu-link" href="gates.php"><i class="fas fa-door-open"></i>Gates & Wall Codes</a>
    <a class="menu-link active" href="sessions.php"><i class="fas fa-history"></i>Parking Sessions</a>
    <a class="menu-link" href="cards.php"><i class="fas fa-id-card"></i>Parking ID Cards</a>
    <hr class="text-white-50 mx-3">
    <a class="menu-link" href="settings.php"><i class="fas fa-cog"></i>Settings</a>
    <a class="menu-link" href="../logout.php"><i class="fas fa-sign-out-alt"></i>Logout</a>
  </nav>
</aside>

<div class="main">
  <header class="header">
    <h1>Parking Sessions</h1>
  </header>

  <div class="content">
    <div class="card">
      <div class="card-header">
        <form class="row g-2 align-items-end" method="get">
          <div class="col-sm-6 col-md-4">
            <label class="form-label mb-1">Search</label>
            <input class="form-control" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Name, email, plate, parking #, gate…">
          </div>
          <div class="col-sm-6 col-md-3">
            <label class="form-label mb-1">Campus</label>
            <select name="campus_id" class="form-select" <?= $hasCampuses && $hasCampusId ? '' : 'disabled' ?>>
              <option value="0">All campuses</option>
              <?php foreach($campusList as $c): ?>
                <option value="<?=$c['id']?>" <?= $campus_id==$c['id']?'selected':'' ?>>
                  <?= htmlspecialchars($c['name']) ?><?= $c['code']?' ('.htmlspecialchars($c['code']).')':'' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-2">
            <label class="form-label mb-1">Status</label>
            <select name="status" class="form-select" <?= $exitAlias ? '' : 'disabled' ?>>
              <option value="">All</option>
              <option value="active" <?= $status==='active'?'selected':'' ?>>Active (no exit)</option>
              <option value="closed" <?= $status==='closed'?'selected':'' ?>>Closed (has exit)</option>
            </select>
          </div>
          <div class="col-6 col-md-1">
            <label class="form-label mb-1">From</label>
            <input type="date" name="date_from" class="form-control" value="<?=htmlspecialchars($date_from)?>" <?= $entryAlias ? '' : 'disabled' ?>>
          </div>
          <div class="col-6 col-md-1">
            <label class="form-label mb-1">To</label>
            <input type="date" name="date_to" class="form-control" value="<?=htmlspecialchars($date_to)?>" <?= $entryAlias ? '' : 'disabled' ?>>
          </div>
          <div class="col-6 col-md-1 d-grid">
            <button class="btn btn-outline-primary"><i class="fa fa-search me-1"></i>Go</button>
          </div>
          <?php if($q!=='' || $status!=='' || $campus_id || $date_from!=='' || $date_to!==''): ?>
          <div class="col-6 col-md-1 d-grid">
            <a class="btn btn-outline-secondary" href="sessions.php">Clear</a>
          </div>
          <?php endif; ?>
        </form>
      </div>

      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table mb-0">
            <thead class="table-light">
              <tr>
                <th style="width:70px">#</th>
                <th>User</th>
                <th>Campus</th>
                <th>Spot</th>
                <th>Gate</th>
                <th>Plate / Parking #</th>
                <th>Entry</th>
                <th>Exit</th>
                <th>Duration</th>
                <th class="text-center">Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if(!$rows): ?>
                <tr><td colspan="10" class="text-center p-4 text-muted">No sessions found.</td></tr>
              <?php else: foreach($rows as $r): 
                $entry = $r['entry_at'] ?? null;
                $exit  = $r['exit_at'] ?? null;
                $dur   = formatDuration($entry,$exit);
                $active = $exit ? false : true;
              ?>
                <tr>
                  <td><?= (int)$r['id'] ?></td>
                  <td>
                    <?php if ($r['FIRST'] || $r['Last']): ?>
                      <div class="fw-semibold" style="color:#003366"><?= htmlspecialchars(trim(($r['FIRST']??'').' '.($r['Last']??''))) ?></div>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                    <div class="small text-muted"><?= htmlspecialchars($r['Email'] ?? '') ?></div>
                  </td>
                  <td>
                    <?php if ($r['campus_name']): ?>
                      <?= htmlspecialchars($r['campus_name']) ?>
                      <?php if ($r['campus_code']): ?>
                        <span class="badge bg-warning text-dark ms-1"><?= htmlspecialchars($r['campus_code']) ?></span>
                      <?php endif; ?>
                    <?php else: ?><span class="text-muted">N/A</span><?php endif; ?>
                  </td>
                  <td>
                    <?php if ($r['spot_number']): ?>
                      Spot <?= htmlspecialchars($r['spot_number']) ?>
                      <?php if ($r['block_name']): ?>
                        <span class="badge badge-soft ms-1">Block <?= htmlspecialchars($r['block_name']) ?></span>
                      <?php endif; ?>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                  </td>
                  <td><?= $r['gate_name'] ? htmlspecialchars($r['gate_name']) : '<span class="text-muted">—</span>' ?></td>
                  <td>
                    <?php if (!empty($r['plate'])): ?>
                      <span class="badge bg-secondary"><?= htmlspecialchars($r['plate']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($r['parking_number'])): ?>
                      <span class="badge bg-warning text-dark ms-1"><?= htmlspecialchars($r['parking_number']) ?></span>
                    <?php endif; ?>
                    <?php if (empty($r['plate']) && empty($r['parking_number'])): ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                  <td><?= $entry ? htmlspecialchars($entry) : '<span class="text-muted">—</span>' ?></td>
                  <td><?= $exit  ? htmlspecialchars($exit)  : '<span class="text-muted">—</span>' ?></td>
                  <td><?= htmlspecialchars($dur) ?></td>
                  <td class="text-center">
                    <?php if ($active): ?>
                      <span class="badge bg-primary">Active</span>
                    <?php else: ?>
                      <span class="badge badge-soft">Closed</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php if($pages>1): ?>
      <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small">Page <?=$page?> of <?=$pages?> • Total <?=number_format($total)?></div>
        <ul class="pagination mb-0">
          <?php
            $baseParams = [];
            if ($q!=='') $baseParams['q']=$q;
            if ($status!=='') $baseParams['status']=$status;
            if ($campus_id) $baseParams['campus_id']=$campus_id;
            if ($date_from!=='') $baseParams['date_from']=$date_from;
            if ($date_to!=='') $baseParams['date_to']=$date_to;
            $base = 'sessions.php?'.http_build_query($baseParams);
            $mk   = fn($p)=> ($base ? $base.'&' : '') . 'page='.$p;
          ?>
          <li class="page-item <?=$page<=1?'disabled':''?>"><a class="page-link" href="<?=$page>1?$mk($page-1):'#'?>">Prev</a></li>
          <?php for($p=max(1,$page-2); $p<=min($pages,$page+2); $p++): ?>
            <li class="page-item <?=$p==$page?'active':''?>"><a class="page-link" href="<?=$mk($p)?>"><?=$p?></a></li>
          <?php endfor; ?>
          <li class="page-item <?=$page>=$pages?'disabled':''?>"><a class="page-link" href="<?=$page<$pages?$mk($page+1):'#'?>">Next</a></li>
        </ul>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
