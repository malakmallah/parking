<?php
/**
 * LIU Parking System - Campuses (READ-ONLY for campus info)
 * File: admin/campuses.php
 * - Admin can manage: Blocks / Spots / Capacity per campus (via actions/links)
 * - Keeps search + pagination + theme
 */

session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
  header('Location: ../login.php'); exit;
}

$db_host='localhost'; $db_name='parking'; $db_user='root'; $db_pass='';
try {
  $pdo=new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",$db_user,$db_pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
} catch(PDOException $e){ die('DB connection failed: '.$e->getMessage()); }

/* ---------- LIST + SEARCH + PAGINATION ---------- */
$q=trim($_GET['q']??'');
$page=max(1,(int)($_GET['page']??1)); $per=10; $off=($page-1)*$per;

$where=''; $params=[];
if($q!==''){ $where="WHERE (c.name LIKE ? OR c.code LIKE ?)"; $params=["%$q%","%$q%"]; }

$cnt=$pdo->prepare("SELECT COUNT(*) FROM campuses c $where"); $cnt->execute($params);
$total=(int)$cnt->fetchColumn(); $pages=max(1,(int)ceil($total/$per));

$sql="
SELECT
  c.*,
  (SELECT COUNT(*) FROM users u WHERE u.campus_id=c.id) AS users_count,
  (SELECT COUNT(*) FROM blocks b WHERE b.campus_id=c.id) AS blocks_count,
  (SELECT COUNT(*) FROM parking_spots ps WHERE ps.campus_id=c.id) AS spots_count,
  (SELECT COALESCE(SUM(cap.capacity),0) FROM capacities cap WHERE cap.campus_id=c.id) AS total_capacity,
  (SELECT COUNT(*) FROM gates g WHERE g.campus_id=c.id) AS gates_count
FROM campuses c
$where
ORDER BY c.name ASC
LIMIT $per OFFSET $off";
$list=$pdo->prepare($sql); $list->execute($params);
$campuses=$list->fetchAll(PDO::FETCH_ASSOC);

$title='Campuses - LIU Parking System';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=htmlspecialchars($title)?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
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
    height: 70px;
    background: #fff;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    display: flex;
    align-items: center;
    padding: 0 30px;
    position: sticky;
    top: 0;
    z-index: 999;
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
    transition: all 0.3s;
}

.card {
    border: 1px solid #e9ecef;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.04);
    background: white;
}

.card-header {
    padding: 20px;
    border-bottom: 1px solid #e9ecef;
    background: #f8f9fa;
    border-radius: 12px 12px 0 0;
}

.table {
    margin: 0;
}

.table th {
    background: #f8f9fa;
    border: none;
    font-weight: 600;
    color: var(--primary);
    padding: 15px;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.table td {
    padding: 15px;
    border-bottom: 1px solid #e9ecef;
    vertical-align: middle;
}

.table tr:last-child td {
    border-bottom: none;
}

.badge-link {
    text-decoration: none;
    color: #0066cc;
    font-weight: 500;
    transition: all 0.3s;
}

.badge-link:hover {
    color: #004499;
    text-decoration: underline;
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

.btn-outline-primary {
    border: 1px solid var(--primary);
    color: var(--primary);
    background: transparent;
}

.btn-outline-primary:hover {
    background: var(--primary);
    color: white;
}

.btn-outline-success {
    border: 1px solid #28a745;
    color: #28a745;
    background: transparent;
}

.btn-outline-success:hover {
    background: #28a745;
    color: white;
}

.btn-outline-warning {
    border: 1px solid var(--gold);
    color: #856404;
    background: transparent;
}

.btn-outline-warning:hover {
    background: var(--gold);
    color: white;
}

.btn-sm {
    padding: 6px 12px;
    font-size: 12px;
}

.card-footer {
    padding: 15px 20px;
    background: #f8f9fa;
    border-top: 1px solid #e9ecef;
    border-radius: 0 0 12px 12px;
}

.pagination {
    margin: 0;
}

.page-link {
    color: var(--primary);
    border: 1px solid #dee2e6;
}

.page-link:hover {
    color: white;
    background-color: var(--primary);
    border-color: var(--primary);
}

.page-item.active .page-link {
    background-color: var(--primary);
    border-color: var(--primary);
}

@media(max-width: 768px) {
    .content-area {
        padding: 20px;
    }
    .card-header {
        padding: 15px;
    }
    .table th,
    .table td {
        padding: 10px 8px;
        font-size: 13px;
    }
    .btn-sm {
        padding: 4px 8px;
        font-size: 11px;
    }
    .header {
        padding: 0 20px;
    }
    .header h1 {
        font-size: 20px;
    }
}
</style>
</head>
<body>
<!-- Sidebar -->
<?php include 'includes/sidebar.php'; ?>

<!-- CORRECTED: Use main-content class instead of main -->
<div class="main-content">
  <header class="header">
    <h1><i class="fas fa-university"></i> Campuses</h1>
  </header>

  <div class="content-area">
    <div class="card">
      <div class="card-header">
        <form class="row g-2 align-items-center" method="get">
          <div class="col-sm-6 col-md-4">
            <input class="form-control" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Search by name or code…">
          </div>
          <div class="col-auto">
            <button class="btn btn-outline-primary"><i class="fa fa-search me-1"></i>Search</button>
          </div>
          <?php if($q!==''): ?>
          <div class="col-auto"><a class="btn btn-outline-secondary" href="campuses.php">Clear</a></div>
          <?php endif; ?>
          <div class="col ms-auto text-end">
            <span class="text-muted small">Total: <?=number_format($total)?></span>
          </div>
        </form>
      </div>

      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table mb-0">
            <thead class="table-light">
              <tr>
                <th style="width:70px">#</th>
                <th>Name</th>
                <th>Code</th>
                <th class="text-center">Users</th>
                <th class="text-center">Blocks</th>
                <th class="text-center">Spots</th>
                <th class="text-center">Capacity</th>
                <th class="text-center">Gates</th>
                <th class="text-center" style="width:230px">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if(!$campuses): ?>
                <tr><td colspan="9" class="text-center p-4 text-muted">No campuses found.</td></tr>
              <?php else: foreach($campuses as $c): 
                $cid=(int)$c['id'];
                $linkBlocks     = "blocks.php?campus_id=".$cid;
                $linkSpots      = "spots.php?campus_id=".$cid;
                $linkCapacities = "capacities.php?campus_id=".$cid;
                $linkGates      = "gates.php?campus_id=".$cid;
              ?>
                <tr>
                  <td><?= $cid ?></td>
                  <td class="fw-semibold" style="color:#003366"><?= htmlspecialchars($c['name']) ?></td>
                  <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($c['code']) ?></span></td>

                  <td class="text-center"><?= (int)$c['users_count'] ?></td>

                  <!-- Make counts clickable to manage pages -->
                  <td class="text-center">
                    <a class="badge-link" href="<?= $linkBlocks ?>" title="Manage Blocks">
                      <?= (int)$c['blocks_count'] ?>
                    </a>
                  </td>
                  <td class="text-center">
                    <a class="badge-link" href="<?= $linkSpots ?>" title="Manage Spots">
                      <?= (int)$c['spots_count'] ?>
                    </a>
                  </td>
                  <td class="text-center">
                    <a class="badge-link" href="<?= $linkCapacities ?>" title="Manage Capacity">
                      <?= (int)$c['total_capacity'] ?>
                    </a>
                  </td>
                  <td class="text-center">
                    <a class="badge-link" href="<?= $linkGates ?>" title="Manage Gates">
                      <?= (int)$c['gates_count'] ?>
                    </a>
                  </td>

                  <!-- Actions column -->
                  <td class="text-center">
                    <div class="d-flex justify-content-center gap-2 flex-wrap">
                      <a class="btn btn-sm btn-outline-primary" href="<?= $linkBlocks ?>" title="Manage Blocks">
                        <i class="fa fa-th-large me-1"></i> Blocks
                      </a>
                      <a class="btn btn-sm btn-outline-success" href="<?= $linkSpots ?>" title="Manage Spots">
                        <i class="fa fa-parking me-1"></i> Spots
                      </a>
                      <a class="btn btn-sm btn-outline-warning" href="<?= $linkCapacities ?>" title="Manage Capacity">
                        <i class="fa fa-database me-1"></i> Capacity
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php if($pages>1): ?>
      <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="text-muted small">Page <?=$page?> of <?=$pages?></div>
        <nav>
          <ul class="pagination mb-0">
            <?php $base='campuses.php?'.http_build_query(array_filter(['q'=>$q?:null])); $mk=fn($p)=>$base.($base!='?'?'&':'')."page=".$p; ?>
            <li class="page-item <?=$page<=1?'disabled':''?>"><a class="page-link" href="<?=$page>1?$mk($page-1):'#'?>">Prev</a></li>
            <?php for($p=max(1,$page-2);$p<=min($pages,$page+2);$p++): ?>
              <li class="page-item <?=$p==$page?'active':''?>"><a class="page-link" href="<?=$mk($p)?>"><?=$p?></a></li>
            <?php endfor; ?>
            <li class="page-item <?=$page>=$pages?'disabled':''?>"><a class="page-link" href="<?=$page<$pages?$mk($page+1):'#'?>">Next</a></li>
          </ul>
        </nav>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>