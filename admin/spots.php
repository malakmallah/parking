<?php
/**
 * LIU Parking System - Parking Spots (ADD + EDIT)
 * File: admin/spots.php
 * - List + search + pagination
 * - Add new spot (modal) + Edit spot (modal)
 *   Uses columns: spot_number, campus_id, block_id, is_reserved
 *   Does NOT touch is_occupied (managed by sessions triggers)
 */

session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
  header('Location: ../login.php'); exit;
}

$db_host='localhost'; $db_name='parking'; $db_user='root'; $db_pass='';
try {
  $pdo=new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",$db_user,$db_pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
} catch(PDOException $e){ die('DB connection failed: '.$e->getMessage()); }

$success_message=''; $error_message='';

/* ---------- handle create/update ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (isset($_POST['create_spot'])) {
    $spot_number = trim($_POST['spot_number'] ?? '');
    $campus_id   = (int)($_POST['campus_id'] ?? 0);
    $block_id    = (int)($_POST['block_id'] ?? 0);
    $is_reserved = isset($_POST['is_reserved']) ? 1 : 0;

    if ($spot_number==='' || $campus_id<=0 || $block_id<=0) {
      $error_message = 'Please fill in all required fields (Campus, Block, Spot #).';
    } else {
      try {
        // enforce uniqueness per block (block_id, spot_number)
        $chk=$pdo->prepare("SELECT id FROM parking_spots WHERE block_id=? AND spot_number=?");
        $chk->execute([$block_id, $spot_number]);
        if ($chk->fetch()) {
          $error_message = 'This spot number already exists in the selected block.';
        } else {
          $ins=$pdo->prepare("INSERT INTO parking_spots (campus_id, block_id, spot_number, is_reserved) VALUES (?,?,?,?)");
          $ins->execute([$campus_id, $block_id, $spot_number, $is_reserved]);
          $success_message = 'Spot added successfully.';
        }
      } catch(PDOException $e){
        $error_message = 'Database error: '.$e->getMessage();
      }
    }
  }

  if (isset($_POST['update_spot'])) {
    $id          = (int)($_POST['id'] ?? 0);
    $spot_number = trim($_POST['spot_number'] ?? '');
    $campus_id   = (int)($_POST['campus_id'] ?? 0);
    $block_id    = (int)($_POST['block_id'] ?? 0);
    $is_reserved = isset($_POST['is_reserved']) ? 1 : 0;

    if ($id<=0 || $spot_number==='' || $campus_id<=0 || $block_id<=0) {
      $error_message = 'Please fill in all required fields (Campus, Block, Spot #).';
    } else {
      try {
        // uniqueness check (exclude current row)
        $chk=$pdo->prepare("SELECT id FROM parking_spots WHERE block_id=? AND spot_number=? AND id<>?");
        $chk->execute([$block_id, $spot_number, $id]);
        if ($chk->fetch()) {
          $error_message = 'This spot number already exists in the selected block.';
        } else {
          $upd=$pdo->prepare("UPDATE parking_spots SET campus_id=?, block_id=?, spot_number=?, is_reserved=? WHERE id=?");
          $upd->execute([$campus_id, $block_id, $spot_number, $is_reserved, $id]);
          $success_message = 'Spot updated successfully.';
        }
      } catch(PDOException $e){
        $error_message = 'Database error: '.$e->getMessage();
      }
    }
  }
}

/* ---------- search + pagination ---------- */
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 12;
$off = ($page-1)*$per;

$where = [];
$params = [];

if ($q !== '') {
  $where[] = "(ps.spot_number LIKE ? OR c.name LIKE ? OR c.code LIKE ? OR b.name LIKE ?)";
  $params[] = "%$q%";
  $params[] = "%$q%";
  $params[] = "%$q%";
  $params[] = "%$q%";
}
$whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

/* Count */
$cnt=$pdo->prepare("
  SELECT COUNT(*)
  FROM parking_spots ps
  LEFT JOIN campuses c ON c.id = ps.campus_id
  LEFT JOIN blocks b   ON b.id = ps.block_id
  $whereSql
");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total/$per));

/* List */
$sql = "
SELECT
  ps.id,
  ps.spot_number,
  COALESCE(ps.is_reserved,0) AS is_reserved,
  c.id AS campus_id, c.name AS campus_name, c.code AS campus_code,
  b.id AS block_id, b.name AS block_name
FROM parking_spots ps
LEFT JOIN campuses c ON c.id = ps.campus_id
LEFT JOIN blocks b   ON b.id = ps.block_id
$whereSql
ORDER BY c.name ASC, b.name ASC, ps.spot_number+0 ASC, ps.spot_number ASC
LIMIT $per OFFSET $off";
$stmt=$pdo->prepare($sql);
$stmt->execute($params);
$spots = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* dropdown data */
$campuses = $pdo->query("SELECT id, name, code FROM campuses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$blocks   = $pdo->query("SELECT id, campus_id, name FROM blocks ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$liuLogoPath='../assets/img/liu-logo.png';
$title='Parking Spots - LIU Parking System';
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
.sidebar{position:fixed;top:0;left:0;height:100vh;width:var(--sidebar);background:linear-gradient(135deg,var(--primary),#004080);box-shadow:0 0 8px rgba(0,0,0,.08)}
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
    <a class="menu-link active" href="spots.php"><i class="fas fa-parking"></i>Parking Spots</a>
    <a class="menu-link" href="gates.php"><i class="fas fa-door-open"></i>Gates & Wall Codes</a>
    <a class="menu-link" href="sessions.php"><i class="fas fa-history"></i>Parking Sessions</a>
    <a class="menu-link" href="cards.php"><i class="fas fa-id-card"></i>Parking ID Cards</a>
    <hr class="text-white-50 mx-3">
    <a class="menu-link" href="settings.php"><i class="fas fa-cog"></i>Settings</a>
    <a class="menu-link" href="../logout.php"><i class="fas fa-sign-out-alt"></i>Logout</a>
  </nav>
</aside>

<div class="main">
  <header class="header w-100">
    <h1 class="me-auto">Parking Spots</h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSpotModal">
      <i class="fa fa-plus me-1"></i> Add New Spot
    </button>
  </header>

  <div class="content">
    <?php if($success_message): ?>
      <div class="alert alert-success"><i class="fa fa-check-circle me-2"></i><?=htmlspecialchars($success_message)?></div>
    <?php endif; ?>
    <?php if($error_message): ?>
      <div class="alert alert-danger"><i class="fa fa-exclamation-circle me-2"></i><?=htmlspecialchars($error_message)?></div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">
        <form class="row g-2 align-items-center" method="get">
          <div class="col-sm-6 col-md-5">
            <input class="form-control" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Search by spot #, campus name/code, or block…">
          </div>
          <div class="col-auto">
            <button class="btn btn-outline-primary"><i class="fa fa-search me-1"></i>Search</button>
          </div>
          <?php if($q!==''): ?>
          <div class="col-auto"><a class="btn btn-outline-secondary" href="spots.php">Clear</a></div>
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
                <th>Spot</th>
                <th>Campus</th>
                <th>Block</th>
                <th class="text-center">Reserved</th>
                <th class="text-center" style="width:120px">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if(!$spots): ?>
                <tr><td colspan="6" class="text-center p-4 text-muted">No spots found.</td></tr>
              <?php else: foreach($spots as $s): ?>
                <tr>
                  <td><?= (int)$s['id'] ?></td>
                  <td class="fw-semibold" style="color:#003366"><?= htmlspecialchars($s['spot_number'] ?? '') ?></td>
                  <td>
                    <?php if ($s['campus_name']): ?>
                      <?= htmlspecialchars($s['campus_name']) ?>
                      <?php if ($s['campus_code']): ?>
                        <span class="badge bg-warning text-dark ms-1"><?= htmlspecialchars($s['campus_code']) ?></span>
                      <?php endif; ?>
                    <?php else: ?><span class="text-muted">N/A</span><?php endif; ?>
                  </td>
                  <td><?= $s['block_name'] ? htmlspecialchars($s['block_name']) : '<span class="text-muted">—</span>' ?></td>
                  <td class="text-center">
                    <?php if ((int)$s['is_reserved'] === 1): ?>
                      <span class="badge bg-primary">Yes</span>
                    <?php else: ?>
                      <span class="badge badge-soft">No</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <button
                      class="btn btn-sm btn-outline-primary"
                      title="Edit Spot"
                      data-bs-toggle="modal" data-bs-target="#editSpotModal"
                      data-id="<?= (int)$s['id']?>"
                      data-spot="<?= htmlspecialchars($s['spot_number'] ?? '', ENT_QUOTES)?>"
                      data-campus="<?= (int)$s['campus_id']?>"
                      data-block="<?= (int)$s['block_id']?>"
                      data-reserved="<?= (int)$s['is_reserved']?>"
                    >
                      <i class="fa fa-edit"></i>
                    </button>
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
        <ul class="pagination mb-0">
          <?php $base='spots.php?'.http_build_query(array_filter(['q'=>$q?:null])); $mk=fn($p)=>$base.($base?'&':'?')."page=".$p; ?>
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

<!-- Add Spot Modal -->
<div class="modal fade" id="addSpotModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa fa-plus me-2"></i>Add New Spot</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Campus *</label>
          <select class="form-select" name="campus_id" id="add-campus" required>
            <option value="">Select campus</option>
            <?php foreach($campuses as $c): ?>
              <option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?> <?=$c['code']?('('.htmlspecialchars($c['code']).')'):''?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Block *</label>
          <select class="form-select" name="block_id" id="add-block" required>
            <option value="">Select block</option>
            <?php foreach($blocks as $b): ?>
              <option value="<?=$b['id']?>" data-campus="<?=$b['campus_id']?>"><?=htmlspecialchars($b['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Spot Number *</label>
          <input type="text" name="spot_number" class="form-control" required placeholder="e.g., 101">
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="is_reserved" id="add-reserved">
          <label class="form-check-label" for="add-reserved">Reserved</label>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit" name="create_spot"><i class="fa fa-save me-1"></i>Add Spot</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Spot Modal -->
<div class="modal fade" id="editSpotModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <input type="hidden" name="id" id="edit-id">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa fa-edit me-2"></i>Edit Spot</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Campus *</label>
          <select class="form-select" name="campus_id" id="edit-campus" required>
            <option value="">Select campus</option>
            <?php foreach($campuses as $c): ?>
              <option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?> <?=$c['code']?('('.htmlspecialchars($c['code']).')'):''?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Block *</label>
          <select class="form-select" name="block_id" id="edit-block" required>
            <option value="">Select block</option>
            <?php foreach($blocks as $b): ?>
              <option value="<?=$b['id']?>" data-campus="<?=$b['campus_id']?>"><?=htmlspecialchars($b['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Spot Number *</label>
          <input type="text" name="spot_number" id="edit-spot" class="form-control" required>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="is_reserved" id="edit-reserved">
          <label class="form-check-label" for="edit-reserved">Reserved</label>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit" name="update_spot"><i class="fa fa-save me-1"></i>Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function filterBlocks(selectEl, campusId) {
  const options = selectEl.querySelectorAll('option[data-campus]');
  let firstVisible = null;
  options.forEach(opt => {
    const visible = String(opt.dataset.campus) === String(campusId);
    opt.hidden = !visible;
    if (visible && !firstVisible) firstVisible = opt;
  });
  // if current value doesn't match new campus, reset
  if (!selectEl.value || selectEl.querySelector(`option[value="${selectEl.value}"]`)?.hidden) {
    selectEl.value = firstVisible ? firstVisible.value : '';
  }
}

document.getElementById('add-campus').addEventListener('change', function(){
  filterBlocks(document.getElementById('add-block'), this.value);
});
document.getElementById('edit-campus').addEventListener('change', function(){
  filterBlocks(document.getElementById('edit-block'), this.value);
});

// When opening edit modal, populate fields from row data
const editModal = document.getElementById('editSpotModal');
editModal.addEventListener('show.bs.modal', event => {
  const btn = event.relatedTarget;
  document.getElementById('edit-id').value = btn.getAttribute('data-id');
  document.getElementById('edit-spot').value = btn.getAttribute('data-spot');

  const campusId = btn.getAttribute('data-campus') || '';
  const blockId  = btn.getAttribute('data-block') || '';
  const reserved = btn.getAttribute('data-reserved') === '1';

  const campusSel = document.getElementById('edit-campus');
  campusSel.value = campusId;
  filterBlocks(document.getElementById('edit-block'), campusId);
  document.getElementById('edit-block').value = blockId;
  document.getElementById('edit-reserved').checked = reserved;
});

// Initialize block filters once for Add modal
filterBlocks(document.getElementById('add-block'), document.getElementById('add-campus').value);
</script>
</body>
</html>
