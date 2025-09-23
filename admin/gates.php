<?php
/**
 * LIU Parking System - Gates & Wall Codes (READ-ONLY)
 * File: admin/gates.php
 * - Lists gates with campus/block, wall code/QR, active flag if present
 * - No add/edit/delete
 * - Auto-detects available columns to avoid 1054 errors
 */

session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
  header('Location: ../login.php'); exit;
}

$db_host='localhost'; $db_name='parking'; $db_user='root'; $db_pass='';
try {
  $pdo=new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",$db_user,$db_pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
} catch(PDOException $e){ die('DB connection failed: '.$e->getMessage()); }

function colExists(PDO $pdo, string $table, string $col): bool {
  $stmt=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $stmt->execute([$table,$col]);
  return (int)$stmt->fetchColumn() > 0;
}

$table = 'gates'; // change if your table name differs

// Detect columns safely
$hasName      = colExists($pdo,$table,'name') || colExists($pdo,$table,'gate_name');
$colName      = colExists($pdo,$table,'name') ? 'g.name' : (colExists($pdo,$table,'gate_name') ? 'g.gate_name' : "NULL");
$hasWallCode  = colExists($pdo,$table,'wall_code') || colExists($pdo,$table,'qr_code') || colExists($pdo,$table,'code');
$colWallCode  = colExists($pdo,$table,'wall_code') ? 'g.wall_code' : (colExists($pdo,$table,'qr_code') ? 'g.qr_code' : (colExists($pdo,$table,'code') ? 'g.code' : "NULL"));
$hasLocation  = colExists($pdo,$table,'location');          // optional
$hasIsActive  = colExists($pdo,$table,'is_active');         // optional
$hasCampusId  = colExists($pdo,$table,'campus_id');         // expected
$hasBlockId   = colExists($pdo,$table,'block_id');          // optional

// Filters & pagination
$q    = trim($_GET['q'] ?? '');
$page = max(1,(int)($_GET['page'] ?? 1));
$per  = 12;
$off  = ($page-1)*$per;

$where = [];
$params = [];

// Build WHERE based on available columns
$searchParts = [];
if ($q !== '') {
  if ($hasName)      $searchParts[] = "$colName LIKE ?";
  if ($hasWallCode)  $searchParts[] = "$colWallCode LIKE ?";
  $searchParts[] = "c.name LIKE ?";
  $searchParts[] = "c.code LIKE ?";
  if ($hasBlockId)   $searchParts[] = "b.name LIKE ?";

  if ($hasName)     $params[] = "%$q%";
  if ($hasWallCode) $params[] = "%$q%";
  $params[]="%$q%"; // campus name
  $params[]="%$q%"; // campus code
  if ($hasBlockId)  $params[]="%$q%";
}

$whereSql = $searchParts ? ('WHERE '.implode(' OR ',$searchParts)) : '';

// Count
$countSql = "
  SELECT COUNT(*)
  FROM {$table} g
  LEFT JOIN campuses c ON ".($hasCampusId ? "c.id = g.campus_id" : "1=0")."
  ".($hasBlockId ? "LEFT JOIN blocks b ON b.id = g.block_id" : "")."
  $whereSql
";
$cnt = $pdo->prepare($countSql);
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total/$per));

// Select list
$select = [
  "g.id",
  $hasCampusId ? "c.id AS campus_id" : "NULL AS campus_id",
  $hasCampusId ? "c.name AS campus_name" : "NULL AS campus_name",
  $hasCampusId ? "c.code AS campus_code" : "NULL AS campus_code",
  $hasBlockId  ? "b.id AS block_id" : "NULL AS block_id",
  $hasBlockId  ? "b.name AS block_name" : "NULL AS block_name",
  "$colName AS gate_name",
  "$colWallCode AS wall_code",
  $hasLocation ? "g.location" : "NULL AS location",
  $hasIsActive ? "g.is_active" : "NULL AS is_active"
];

$listSql = "
  SELECT ".implode(", ", $select)."
  FROM {$table} g
  LEFT JOIN campuses c ON ".($hasCampusId ? "c.id = g.campus_id" : "1=0")."
  ".($hasBlockId ? "LEFT JOIN blocks b ON b.id = g.block_id" : "")."
  $whereSql
  ORDER BY c.name ASC, ".($hasBlockId ? "b.name ASC," : "")." gate_name ASC
  LIMIT $per OFFSET $off
";
$stmt = $pdo->prepare($listSql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$liuLogoPath='../assets/img/liu-logo.png';
$title='Gates & Wall Codes - LIU Parking System';
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
    <a class="menu-link active" href="gates.php"><i class="fas fa-door-open"></i>Gates & Wall Codes</a>
    <a class="menu-link" href="sessions.php"><i class="fas fa-history"></i>Parking Sessions</a>
    <a class="menu-link" href="cards.php"><i class="fas fa-id-card"></i>Parking ID Cards</a>
    <hr class="text-white-50 mx-3">
    <a class="menu-link" href="settings.php"><i class="fas fa-cog"></i>Settings</a>
    <a class="menu-link" href="../logout.php"><i class="fas fa-sign-out-alt"></i>Logout</a>
  </nav>
</aside>

<div class="main">
  <header class="header">
    <h1>Gates & Wall Codes</h1>
  </header>

  <div class="content">
    <div class="card">
      <div class="card-header">
        <form class="row g-2 align-items-center" method="get">
          <div class="col-sm-6 col-md-5">
            <input class="form-control" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Search by gate name/code, campus, or block…">
          </div>
          <div class="col-auto">
            <button class="btn btn-outline-primary"><i class="fa fa-search me-1"></i>Search</button>
          </div>
          <?php if($q!==''): ?>
          <div class="col-auto"><a class="btn btn-outline-secondary" href="gates.php">Clear</a></div>
          <?php endif; ?>
          <div class="col ms-auto text-end"><span class="text-muted small">Total: <?=number_format($total)?></span></div>
        </form>
      </div>

      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table mb-0">
            <thead class="table-light">
              <tr>
                <th style="width:70px">#</th>
                <th>Gate</th>
                <th>Campus</th>
                <th>Block</th>
                <th>Wall Code / QR</th>
                <?php if ($hasLocation): ?><th>Location</th><?php endif; ?>
                <?php if ($hasIsActive): ?><th class="text-center">Active</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php if(!$rows): ?>
                <tr><td colspan="<?= 5 + ($hasLocation?1:0) + ($hasIsActive?1:0) ?>" class="text-center p-4 text-muted">No gates found.</td></tr>
              <?php else: foreach($rows as $r): ?>
                <tr>
                  <td><?= (int)$r['id'] ?></td>
                  <td class="fw-semibold" style="color:#003366">
                    <?= htmlspecialchars($r['gate_name'] ?? '') ?>
                  </td>
                  <td>
                    <?php if ($r['campus_name']): ?>
                      <?= htmlspecialchars($r['campus_name']) ?>
                      <?php if ($r['campus_code']): ?>
                        <span class="badge bg-warning text-dark ms-1"><?= htmlspecialchars($r['campus_code']) ?></span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="text-muted">N/A</span>
                    <?php endif; ?>
                  </td>
                  <td><?= $r['block_name'] ? htmlspecialchars($r['block_name']) : '<span class="text-muted">—</span>' ?></td>
                  <td>
                    <?php if (!empty($r['wall_code'])): ?>
                      <code><?= htmlspecialchars($r['wall_code']) ?></code>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                  <?php if ($hasLocation): ?>
                    <td><?= !empty($r['location']) ? htmlspecialchars($r['location']) : '<span class="text-muted">—</span>' ?></td>
                  <?php endif; ?>
                  <?php if ($hasIsActive): ?>
                    <td class="text-center">
                      <?php if ((int)$r['is_active'] === 1): ?>
                        <span class="badge bg-primary">Yes</span>
                      <?php else: ?>
                        <span class="badge badge-soft">No</span>
                      <?php endif; ?>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php if($pages>1): ?>
      <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small">Page <?=$page?> of <?=$pages?></div>
        <ul class="pagination mb-0">
          <?php $base='gates.php?'.http_build_query(array_filter(['q'=>$q?:null])); $mk=fn($p)=>$base.($base?'&':'?')."page=".$p; ?>
          <li class="page-item <?=$page<=1?'disabled':''?>"><a class="page-link" href="<?=$page>1?$mk($page-1):'#'?>">Prev</a></li>
          <?php for($p=max(1,$page-2);$p<=min($pages,$page+2);$p++): ?>
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
