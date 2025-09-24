<?php
/**
 * LIU Parking System - Parking ID Cards Generator (with Pagination & Bulk)
 * Location: admin/cards.php
 */

session_start();

// Require admin
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
  header('Location: ../login.php'); exit;
}

// DB
$db_host='localhost'; $db_name='parking'; $db_user='root'; $db_pass='';
try {
  $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",$db_user,$db_pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
} catch(PDOException $e){ die("Database connection failed: ".$e->getMessage()); }

$success_message=''; $error_message=''; 
$action = $_GET['action'] ?? 'list';
$selected_user = null;
$liuLogoPath = '../assets/img/liu-logo.png';

/* ------------------ Upload Photo ------------------ */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['upload_photo'])) {
  $user_id = (int)$_POST['user_id'];
  if (!empty($_FILES['photo']) && $_FILES['photo']['error']===UPLOAD_ERR_OK){
    $dir = '../images/user_photos/';
    if (!is_dir($dir)) mkdir($dir,0755,true);
    $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext,['jpg','jpeg','png','gif'])) { $error_message='Invalid file format.'; }
    else {
      $name = "user_{$user_id}_".time().".{$ext}";
      if (move_uploaded_file($_FILES['photo']['tmp_name'],$dir.$name)){
        $stmt=$pdo->prepare("UPDATE users SET photo_url=? WHERE id=?");
        $stmt->execute(['images/user_photos/'.$name,$user_id]);
        $success_message='Photo uploaded successfully!';
      } else { $error_message='Failed to upload photo.'; }
    }
  } else { $error_message='Please select a photo.'; }
}

/* ------------------ Assign Parking Number ------------------ */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['assign_spot'])) {
  $user_id = (int)$_POST['user_id'];
  $parking_number = trim($_POST['parking_number']);
  try{
    $stmt=$pdo->prepare("SELECT id FROM users WHERE parking_number=? AND id<>?");
    $stmt->execute([$parking_number,$user_id]);
    if ($stmt->fetch()){ $error_message='Parking number already in use.'; }
    else{
      $stmt=$pdo->prepare("UPDATE users SET parking_number=? WHERE id=?");
      $stmt->execute([$parking_number,$user_id]);
      $success_message='Parking spot assigned successfully!';
    }
  }catch(PDOException $e){ $error_message='Database error: '.$e->getMessage(); }
}

/* ------------------ ID Card (single) ------------------ */
if ($action==='generate'){
  $user_id = $_GET['user_id'] ?? null;
  if ($user_id){
    $stmt=$pdo->prepare("
      SELECT u.*, c.name campus_name, c.code campus_code
      FROM users u
      LEFT JOIN campuses c ON u.Campus COLLATE utf8mb4_unicode_ci = c.name COLLATE utf8mb4_unicode_ci
      WHERE u.id=? AND u.role IN ('staff','instructor')
    ");
    $stmt->execute([$user_id]);
    $selected_user = $stmt->fetch();
    if (!$selected_user){ $error_message='User not found.'; $action='list'; }
  } else { $error_message='No user selected.'; $action='list'; }
}

/* ------------------ Bulk page (selected or all) ------------------ */
$bulk_users = [];
if ($action==='bulk'){
  if (isset($_GET['all']) && $_GET['all']=='1'){
    $stmt=$pdo->query("
      SELECT u.*, c.name campus_name, c.code campus_code
      FROM users u
      LEFT JOIN campuses c ON u.Campus COLLATE utf8mb4_unicode_ci = c.name COLLATE utf8mb4_unicode_ci
      WHERE u.role IN ('staff','instructor')
      ORDER BY u.First, u.Last
    ");
    $bulk_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $ids = array_map('intval', $_POST['user_ids'] ?? []);
    if ($ids){
      $in = implode(',', array_fill(0,count($ids),'?'));
      $stmt=$pdo->prepare("
        SELECT u.*, c.name campus_name, c.code campus_code
        FROM users u
        LEFT JOIN campuses c ON u.Campus COLLATE utf8mb4_unicode_ci = c.name COLLATE utf8mb4_unicode_ci
        WHERE u.id IN ($in) AND u.role IN ('staff','instructor')
        ORDER BY u.First, u.Last
      ");
      $stmt->execute($ids);
      $bulk_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
      $error_message='No users selected.'; $action='list';
    }
  }
}

/* ------------------ Pagination for list ------------------ */
$perPage = max(5, min(100, (int)($_GET['per_page'] ?? 20)));
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page-1)*$perPage;

$total=0;
if ($action==='list'){
  $total = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('staff','instructor')")->fetchColumn();
  $stmt=$pdo->prepare("
    SELECT u.*, c.name campus_name, c.code campus_code,
           DATE_FORMAT(u.created_at,'%M %d, %Y') AS formatted_date
    FROM users u
    LEFT JOIN campuses c ON u.Campus COLLATE utf8mb4_unicode_ci = c.name COLLATE utf8mb4_unicode_ci
    WHERE u.role IN ('staff','instructor')
    ORDER BY u.created_at DESC
    LIMIT :lim OFFSET :off
  ");
  $stmt->bindValue(':lim',$perPage,PDO::PARAM_INT);
  $stmt->bindValue(':off',$offset,PDO::PARAM_INT);
  $stmt->execute();
  $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle="Parking ID Cards - LIU Parking System";
$totalPages = max(1, (int)ceil($total / $perPage));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--primary-color:#003366;--secondary-color:#FFB81C;--success-color:#28a745;--danger-color:#dc3545;--sidebar-width:280px;--header-height:70px;}
*{box-sizing:border-box}body{font-family:'Inter',sans-serif;background:#f5f7fa;color:#333}
.sidebar{position:fixed;top:0;left:0;height:100vh;width:var(--sidebar-width);background:linear-gradient(135deg,#003366 0%,#004080 100%);z-index:1000;box-shadow:2px 0 10px rgba(0,0,0,.1)}
.sidebar-header{padding:20px;text-align:center;border-bottom:1px solid rgba(255,255,255,.1);margin-bottom:20px}
.sidebar-header .logo{width:50px;height:50px;margin:0 auto 15px;display:flex;align-items:center;justify-content:center}
.sidebar-header .logo img{max-height:50px;width:auto;display:block}
.sidebar-header h4{color:#fff;font-weight:600;font-size:18px;margin-bottom:5px}
.sidebar-header p{color:rgba(255,255,255,.7);font-size:12px;margin:0}
.sidebar-menu{padding:0 15px}
.menu-link{display:flex;align-items:center;padding:12px 15px;color:rgba(255,255,255,.8);text-decoration:none;border-radius:8px;transition:.3s;font-size:14px;font-weight:500}
.menu-link i{width:20px;margin-right:12px;text-align:center}
.menu-link:hover,.menu-link.active{background:rgba(255,255,255,.1);color:#fff;transform:translateX(5px)}
.main-content{margin-left:var(--sidebar-width);min-height:100vh}
.header{height:var(--header-height);background:#fff;box-shadow:0 2px 10px rgba(0,0,0,.05);display:flex;align-items:center;padding:0 30px;position:sticky;top:0;z-index:999}
.header-left h1{font-size:24px;font-weight:600;color:var(--primary-color);margin:0}
.content-area{padding:30px}
.card{border-radius:12px;border:1px solid #e9ecef;box-shadow:0 2px 10px rgba(0,0,0,.05)}
.card-header{background:none;border-bottom:1px solid #e9ecef;padding:20px 25px}
.parking-id-card{width:400px;height:250px;background:linear-gradient(135deg,#003366 0%,#004080 100%);border-radius:15px;position:relative;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.3);margin:0 auto 30px}
.parking-id-card::before{content:'';position:absolute;top:-50%;left:-50%;width:200%;height:200%;background:linear-gradient(45deg,transparent,rgba(255,184,28,.1),transparent);animation:shine 3s ease-in-out infinite}
@keyframes shine{0%{transform:rotate(0)}100%{transform:rotate(360deg)}}
.card-header-section{background:rgba(255,255,255,.1);padding:15px 20px;position:relative;z-index:2}
.card-logo{display:flex;align-items:center;gap:12px;margin-bottom:8px}
.logo-img{height:28px;width:auto;display:block}
.logo-text{color:#fff;font-weight:700;font-size:18px;letter-spacing:.5px}
.card-body-section{padding:20px;display:flex;gap:15px;position:relative;z-index:2;height:calc(100% - 80px)}
.user-photo{width:80px;height:80px;border-radius:10px;background:rgba(255,255,255,.1);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.6);font-size:24px;border:2px solid rgba(255,255,255,.2);flex-shrink:0;background-size:cover;background-position:center}
.user-info{flex:1;color:#fff}.user-name{font-size:16px;font-weight:700;margin-bottom:6px}
.user-title{font-size:12px;color:rgba(255,255,255,.8);margin-bottom:8px}
.user-details{font-size:11px;color:rgba(255,255,255,.7);line-height:1.4}
.parking-info{position:absolute;bottom:20px;right:20px;text-align:right}
.parking-number{background:var(--secondary-color);color:#fff;padding:3px 8px;border-radius:10px;font-size:10px;font-weight:700;margin-bottom:5px}
.parking-campus{font-size:10px;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.5px}
.photo-upload-area{border:2px dashed #ddd;border-radius:8px;padding:20px;text-align:center;transition:.3s;cursor:pointer}
.photo-upload-area:hover{border-color:#003366;background:rgba(0,51,102,.05)}
.photo-upload-area.dragover{border-color:#FFB81C;background:rgba(255,184,28,.1)}
.alert{border-radius:8px;border:none;padding:15px 20px;margin-bottom:20px}
.alert-success{background:rgba(40,167,69,.1);color:var(--success-color)}
.alert-danger{background:rgba(220,53,69,.1);color:var(--danger-color)}
/* print exact colors */
@media print{
  *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important}
  body *{visibility:hidden}
  .print-area,.print-area *{visibility:visible}
  .parking-id-card{page-break-inside:avoid;break-inside:avoid}
}
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>

<div class="main-content">
  <header class="header">
    <div class="header-left"><h1>
      <?= $action==='generate' ? 'Generate ID Card' : ($action==='bulk' ? 'Bulk ID Cards' : 'Parking ID Cards'); ?>
    </h1></div>
    <div class="header-right">
      <?php if ($action!=='list'): ?>
        <a href="cards.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i>Back to List</a>
      <?php endif; ?>
    </div>
  </header>

  <div class="content-area">
    <?php if($success_message): ?><div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?></div><?php endif; ?>
    <?php if($error_message):   ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error_message) ?></div><?php endif; ?>

    <?php if ($action==='generate' && $selected_user): ?>
      <div class="row">
        <div class="col-lg-8">
          <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0"><i class="fas fa-id-card me-2"></i>ID Card Preview</h3>
              <div>
                <button onclick="window.print()" class="btn btn-primary btn-sm"><i class="fas fa-print me-2"></i>Print</button>
                <button onclick="downloadCard('idCard')" class="btn btn-secondary btn-sm"><i class="fas fa-download me-2"></i>Download</button>
              </div>
            </div>
            <div class="card-body text-center print-area">
              <div class="parking-id-card" id="idCard">
                <div class="card-header-section">
                  <div class="card-logo">
                    <?php if (file_exists($liuLogoPath)): ?>
                      <img class="logo-img" src="<?= htmlspecialchars($liuLogoPath) ?>" alt="LIU Logo">
                    <?php else: ?><div class="logo-img"><i class="fas fa-car text-white"></i></div><?php endif; ?>
                    <div class="logo-text">LIU PARKING</div>
                  </div>
                </div>
                <div class="card-body-section">
                  <div class="user-photo" style="<?= !empty($selected_user['photo_url']) ? "background-image:url('../".htmlspecialchars($selected_user['photo_url'])."')" : '' ?>">
                    <?php if (empty($selected_user['photo_url'])): ?><i class="fas fa-user"></i><?php endif; ?>
                  </div>
                  <div class="user-info">
                    <div class="user-name"><?= htmlspecialchars($selected_user['FIRST'].' '.$selected_user['Last']) ?></div>
                    <div class="user-title"><?= htmlspecialchars($selected_user['Title'] ?: ucfirst($selected_user['role'])) ?></div>
                    <div class="user-details">
                      <?= htmlspecialchars($selected_user['Email']) ?><br>
                      <?php if(!empty($selected_user['School'])): ?><?= htmlspecialchars($selected_user['School']) ?><br><?php endif; ?>
                      <?php if(!empty($selected_user['Reference'])): ?>ID: <?= htmlspecialchars($selected_user['Reference']) ?><?php endif; ?>
                    </div>
                  </div>
                  <div class="parking-info">
                    <div class="parking-number"><?= htmlspecialchars($selected_user['parking_number'] ?? 'UNASSIGNED') ?></div>
                    <div class="parking-campus"><?= htmlspecialchars($selected_user['campus_name'] ?? 'No Campus') ?></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <?php
          // available spots (optional list)
          $spots = $pdo->query("
            SELECT ps.*, c.name campus_name, b.name block_name
            FROM parking_spots ps
            LEFT JOIN campuses c ON ps.campus_id=c.id
            LEFT JOIN blocks b ON ps.block_id=b.id
            WHERE ps.is_reserved=0
            ORDER BY c.name, b.name, ps.spot_number
          ")->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="col-lg-4">
          <div class="card mb-4">
            <div class="card-header"><h3 class="card-title mb-0"><i class="fas fa-camera me-2"></i>Upload Photo</h3></div>
            <div class="card-body">
              <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="user_id" value="<?= (int)$selected_user['id'] ?>">
                <div class="photo-upload-area mb-3" onclick="document.getElementById('photoInput').click()">
                  <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2"></i>
                  <p class="text-muted mb-0">Click to upload photo</p><small class="text-muted">JPG, PNG, GIF (Max 5MB)</small>
                </div>
                <input type="file" id="photoInput" name="photo" accept="image/*" hidden>
                <button class="btn btn-primary w-100" name="upload_photo"><i class="fas fa-upload me-2"></i>Upload Photo</button>
              </form>
              <?php if(!empty($selected_user['photo_url'])): ?>
                <div class="mt-3 text-center">
                  <img src="../<?= htmlspecialchars($selected_user['photo_url']) ?>" class="img-thumbnail" style="max-width:100px" alt="Photo">
                  <div class="small text-muted mt-1">Current Photo</div>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="card">
            <div class="card-header"><h3 class="card-title mb-0"><i class="fas fa-parking me-2"></i>Parking Assignment</h3></div>
            <div class="card-body">
              <form method="POST">
                <input type="hidden" name="user_id" value="<?= (int)$selected_user['id'] ?>">
                <div class="mb-3">
                  <label class="form-label" for="parking_number">Parking Number *</label>
                  <input id="parking_number" name="parking_number" class="form-control" required
                         value="<?= htmlspecialchars($selected_user['parking_number'] ?? '') ?>" placeholder="e.g., BEI-A-15">
                </div>
                <button class="btn btn-secondary w-100" name="assign_spot"><i class="fas fa-save me-2"></i>Save Assignment</button>
              </form>
            </div>
          </div>
        </div>
      </div>

    <?php elseif ($action==='bulk'): ?>
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h3 class="card-title mb-0"><i class="fas fa-layer-group me-2"></i>Bulk ID Cards (<?= count($bulk_users) ?>)</h3>
          <div>
            <button onclick="window.print()" class="btn btn-primary btn-sm"><i class="fas fa-print me-2"></i>Print All</button>
          </div>
        </div>
        <div class="card-body">
          <?php if (!$bulk_users): ?>
            <div class="text-center text-muted py-5">No users to generate.</div>
          <?php else: ?>
            <div class="row print-area">
              <?php foreach($bulk_users as $u): ?>
                <div class="col-xl-4 col-lg-6 col-md-6 mb-4 d-flex justify-content-center">
                  <div class="parking-id-card">
                    <div class="card-header-section">
                      <div class="card-logo">
                        <?php if (file_exists($liuLogoPath)): ?>
                          <img class="logo-img" src="<?= htmlspecialchars($liuLogoPath) ?>" alt="LIU Logo">
                        <?php else: ?><div class="logo-img"><i class="fas fa-car text-white"></i></div><?php endif; ?>
                        <div class="logo-text">LIU PARKING</div>
                      </div>
                      <div class="card-subtitle text-white-50">Staff/Instructor ID Card</div>
                    </div>
                    <div class="card-body-section">
                      <div class="user-photo" style="<?= !empty($u['photo_url']) ? "background-image:url('../".htmlspecialchars($u['photo_url'])."')" : '' ?>">
                        <?php if (empty($u['photo_url'])): ?><i class="fas fa-user"></i><?php endif; ?>
                      </div>
                      <div class="user-info">
                        <div class="user-name"><?= htmlspecialchars($u['FIRST'].' '.$u['Last']) ?></div>
                        <div class="user-title"><?= htmlspecialchars($u['Title'] ?: ucfirst($u['role'])) ?></div>
                        <div class="user-details">
                          <?= htmlspecialchars($u['Email']) ?><br>
                          <?php if(!empty($u['School'])): ?><?= htmlspecialchars($u['School']) ?><br><?php endif; ?>
                          <?php if(!empty($u['Reference'])): ?>ID: <?= htmlspecialchars($u['Reference']) ?><?php endif; ?>
                        </div>
                      </div>
                      <div class="parking-info">
                        <div class="parking-number"><?= htmlspecialchars($u['parking_number'] ?? 'UNASSIGNED') ?></div>
                        <div class="parking-campus"><?= htmlspecialchars($u['campus_name'] ?? 'No Campus') ?></div>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

    <?php else: /* -------- LIST with pagination + checkboxes -------- */ ?>
      <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
          <h3 class="card-title mb-0"><i class="fas fa-id-card me-2"></i>Select Users for ID Card Generation</h3>
          <div class="d-flex align-items-center gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="?action=bulk&all=1"><i class="fas fa-layer-group me-1"></i>Generate All</a>
          </div>
        </div>

        <form method="POST" action="cards.php?action=bulk">
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table align-middle">
                <thead class="table-light">
                  <tr>
                    <th style="width:40px;">
                      <input type="checkbox" id="selectAll">
                    </th>
                    <th>User</th>
                    <th>Role</th>
                    <th>Campus</th>
                    <th>Photo</th>
                    <th>Parking Number</th>
                    <th style="width:160px;">Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach($users as $user): ?>
                  <tr>
                    <td><input type="checkbox" class="row-check" name="user_ids[]" value="<?= (int)$user['id'] ?>"></td>
                    <td>
                      <div style="font-weight:600;color:#003366"><?= htmlspecialchars($user['FIRST'].' '.$user['Last']) ?></div>
                      <div class="small text-muted"><?= htmlspecialchars($user['Email']) ?></div>
                      <?php if(!empty($user['Title'])): ?><div class="small text-muted"><?= htmlspecialchars($user['Title']) ?></div><?php endif; ?>
                    </td>
                    <td><span class="badge <?= $user['role']==='staff' ? 'bg-primary':'bg-success' ?>"><?= ucfirst(htmlspecialchars($user['role'])) ?></span></td>
                    <td><?= htmlspecialchars($user['campus_name'] ?? 'N/A') ?></td>
                    <td>
                      <?php if(!empty($user['photo_url'])): ?>
                        <img src="../<?= htmlspecialchars($user['photo_url']) ?>" class="rounded" style="width:40px;height:40px;object-fit:cover" alt="Photo">
                      <?php else: ?>
                        <div class="text-center" style="width:40px;height:40px;background:#f8f9fa;border-radius:4px;display:flex;align-items:center;justify-content:center">
                          <i class="fas fa-user text-muted"></i>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if(!empty($user['parking_number'])): ?>
                        <span class="badge bg-warning text-dark"><?= htmlspecialchars($user['parking_number']) ?></span>
                      <?php else: ?><span class="text-muted">Not Assigned</span><?php endif; ?>
                    </td>
                    <td>
                      <div class="d-flex gap-2">
                        <a class="btn btn-sm btn-primary" href="?action=generate&user_id=<?= (int)$user['id'] ?>"><i class="fas fa-id-card"></i> Generate</a>
                        <a class="btn btn-sm btn-outline-secondary" href="users.php?action=edit&id=<?= (int)$user['id'] ?>"><i class="fas fa-edit"></i></a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                  <tr><td colspan="7" class="text-center py-5 text-muted">No users found.</td></tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="d-flex justify-content-between align-items-center p-3">
            <div>
              <button type="submit" class="btn btn-primary"><i class="fas fa-print me-1"></i>Generate Selected</button>
              <a class="btn btn-outline-secondary ms-2" href="?action=bulk&all=1"><i class="fas fa-layer-group me-1"></i>Generate All</a>
            </div>

            <!-- Pagination -->
            <nav>
              <ul class="pagination mb-0">
                <?php
                  $qs = function($p) use($perPage){ return '?action=list&page='.$p.'&per_page='.$perPage; };
                  $disabledPrev = $page<=1 ? ' disabled':'';
                  $disabledNext = $page>=$totalPages ? ' disabled':'';
                ?>
                <li class="page-item<?= $disabledPrev ?>"><a class="page-link" href="<?= $qs(max(1,$page-1)) ?>">Previous</a></li>
                <?php for($p=max(1,$page-2); $p<=min($totalPages,$page+2); $p++): ?>
                  <li class="page-item <?= $p===$page?'active':'' ?>"><a class="page-link" href="<?= $qs($p) ?>"><?= $p ?></a></li>
                <?php endfor; ?>
                <li class="page-item<?= $disabledNext ?>"><a class="page-link" href="<?= $qs(min($totalPages,$page+1)) ?>">Next</a></li>
              </ul>
            </nav>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
function downloadCard(id){
  const card=document.getElementById(id);
  html2canvas(card,{scale:2,backgroundColor:null,useCORS:true}).then(cv=>{
    const a=document.createElement('a');a.download='parking-id-card.png';a.href=cv.toDataURL();a.click();
  });
}
const selectAll=document.getElementById('selectAll');
if(selectAll){
  selectAll.addEventListener('change',e=>{
    document.querySelectorAll('.row-check').forEach(cb=>cb.checked=e.target.checked);
  });
}
const uploadArea=document.querySelector('.photo-upload-area'), photoInput=document.getElementById('photoInput');
if(uploadArea && photoInput){
  uploadArea.addEventListener('dragover',e=>{e.preventDefault();uploadArea.classList.add('dragover');});
  uploadArea.addEventListener('dragleave',e=>{e.preventDefault();uploadArea.classList.remove('dragover');});
  uploadArea.addEventListener('drop',e=>{
    e.preventDefault();uploadArea.classList.remove('dragover');
    if(e.dataTransfer.files.length){photoInput.files=e.dataTransfer.files;photoInput.dispatchEvent(new Event('change',{bubbles:true}));}
  });
  photoInput.addEventListener('change',e=>{
    const f=e.target.files[0]; if(!f) return;
    if(f.size>5*1024*1024){alert('File size must be less than 5MB'); photoInput.value=''; return;}
    const r=new FileReader(); r.onload=ev=>{const ph=document.querySelector('.user-photo'); if(ph){ph.style.backgroundImage=`url(${ev.target.result})`; ph.innerHTML='';}}; r.readAsDataURL(f);
  });
}
// fade alerts
setTimeout(()=>{document.querySelectorAll('.alert').forEach(el=>{el.style.transition='opacity .5s';el.style.opacity='0';setTimeout(()=>el.remove(),500);});},5000);
</script>
</body>
</html>
