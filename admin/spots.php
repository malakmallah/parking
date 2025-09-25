<?php
/**
 * Unified Parking Spots Management - 3D & Table View Toggle
 * Integrates both views with consistent design
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
        $campus_id   = (int)($_POST['campus_id'] ?? 0);
        $block_id    = (int)($_POST['block_id'] ?? 0);
        $is_reserved = isset($_POST['is_reserved']) ? 1 : 0;

        if ($campus_id<=0) {
            $error_message = 'Please select a campus.';
        } else {
            try {
                // Get campus code
                $campus_stmt = $pdo->prepare("SELECT code FROM campuses WHERE id=?");
                $campus_stmt->execute([$campus_id]);
                $campus = $campus_stmt->fetch();
                
                if (!$campus) {
                    $error_message = 'Invalid campus selected.';
                } else {
                    $campus_code = $campus['code'];
                    
                    // Get next spot number for this campus
                    $next_stmt = $pdo->prepare("
                        SELECT COALESCE(MAX(CAST(SUBSTRING(spot_number, 5) AS UNSIGNED)), 0) + 1 as next_number 
                        FROM parking_spots 
                        WHERE campus_id = ? AND spot_number LIKE ?
                    ");
                    $next_stmt->execute([$campus_id, $campus_code . '-%']);
                    $next_number = $next_stmt->fetch()['next_number'];
                    
                    // Format spot number (e.g., BEI-001, BEI-002)
                    $spot_number = $campus_code . '-' . str_pad($next_number, 3, '0', STR_PAD_LEFT);
                    
                    // Insert the new spot (block_id can be NULL if no block selected)
                    $block_id_val = $block_id > 0 ? $block_id : NULL;
                    $ins=$pdo->prepare("INSERT INTO parking_spots (campus_id, block_id, spot_number, is_reserved) VALUES (?,?,?,?)");
                    $ins->execute([$campus_id, $block_id_val, $spot_number, $is_reserved]);
                    $success_message = "Spot {$spot_number} added successfully." . ($block_id_val ? "" : " (No block assigned)");
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

// Get real parking data with occupancy status
$stmt = $pdo->query("
    SELECT 
        ps.id,
        ps.spot_number,
        ps.campus_id,
        ps.block_id,
        ps.is_reserved,
        ps.is_occupied,
        c.name as campus_name,
        c.code as campus_code,
        b.name as block_name,
        sess.entrance_at,
        u.FIRST,
        u.Last
    FROM parking_spots ps
    LEFT JOIN campuses c ON c.id = ps.campus_id
    LEFT JOIN blocks b ON b.id = ps.block_id
    LEFT JOIN parking_sessions sess ON sess.spot_id = ps.id AND sess.exit_at IS NULL
    LEFT JOIN users u ON u.id = sess.user_id
    ORDER BY c.name, b.name, ps.spot_number
");
$parking_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get campuses and blocks for dropdowns
$campuses = $pdo->query("SELECT id, name, code FROM campuses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$blocks = $pdo->query("SELECT id, campus_id, name FROM blocks ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
// Calculate statistics (keep this unchanged for 3D view, but add filtered stats for search)
// Search and pagination for table view (MOVE THIS UP FIRST)
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

// Get total count with proper search filtering
$cnt=$pdo->prepare("SELECT COUNT(*) FROM parking_spots ps LEFT JOIN campuses c ON c.id = ps.campus_id LEFT JOIN blocks b ON b.id = ps.block_id $whereSql");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total/$per));

// NOW calculate statistics (after $q and $total are defined)
$stats = [
    'total' => count($parking_data),
    'available' => count(array_filter($parking_data, fn($s) => !$s['is_occupied'] && !$s['is_reserved'])),
    'occupied' => count(array_filter($parking_data, fn($s) => $s['is_occupied'])),
    'reserved' => count(array_filter($parking_data, fn($s) => $s['is_reserved'] && !$s['is_occupied']))
];

// For search results display (now $q and $total are defined)
$filtered_total = $q !== '' ? $total : $stats['total'];
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

// Get total count with proper search filtering
$cnt=$pdo->prepare("SELECT COUNT(*) FROM parking_spots ps LEFT JOIN campuses c ON c.id = ps.campus_id LEFT JOIN blocks b ON b.id = ps.block_id $whereSql");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total/$per));

// Get filtered data directly from database for table view
$table_spots = [];
if ($total > 0) {
    $table_stmt = $pdo->prepare("
        SELECT 
            ps.id,
            ps.spot_number,
            ps.campus_id,
            ps.block_id,
            ps.is_reserved,
            ps.is_occupied,
            c.name as campus_name,
            c.code as campus_code,
            b.name as block_name,
            sess.entrance_at,
            u.FIRST,
            u.Last
        FROM parking_spots ps
        LEFT JOIN campuses c ON c.id = ps.campus_id
        LEFT JOIN blocks b ON b.id = ps.block_id
        LEFT JOIN parking_sessions sess ON sess.spot_id = ps.id AND sess.exit_at IS NULL
        LEFT JOIN users u ON u.id = sess.user_id
        $whereSql
        ORDER BY c.name, b.name, ps.spot_number
        LIMIT $per OFFSET $off
    ");
    $table_stmt->execute($params);
    $table_spots = $table_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parking Spots - LIU Parking System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #003366;
            --gold: #FFB81C;
            --sidebar: 280px;
            --header: 70px;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fa;
            margin: 0;
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar);
            background: linear-gradient(135deg, var(--primary), #004080);
            z-index: 1000;
            transition: 0.3s;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 20px;
        }

        .sidebar-header .logo {
            width: 50px;
            height: 50px;
            background: var(--gold);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 24px;
            color: #fff;
        }

        .sidebar-header h4 {
            color: #fff;
            font-weight: 600;
            font-size: 18px;
            margin-bottom: 5px;
        }

        .sidebar-header p {
            color: rgba(255,255,255,0.7);
            font-size: 12px;
            margin: 0;
        }

        .sidebar-menu {
            padding: 0 15px;
        }

        .sidebar-menu .menu-item {
            margin-bottom: 5px;
        }

        .sidebar-menu .menu-link {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            border-radius: 8px;
            transition: 0.3s;
            font-size: 14px;
            font-weight: 500;
        }

        .sidebar-menu .menu-link:hover,
        .sidebar-menu .menu-link.active {
            background: rgba(255,255,255,0.1);
            color: #fff;
            transform: translateX(5px);
        }

        .sidebar-menu .menu-link i {
            width: 20px;
            margin-right: 12px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar);
            min-height: 100vh;
        }

        .header {
            height: var(--header);
            background: #fff;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            position: sticky;
            top: 0;
            z-index: 999;
        }

        .header-left h1 {
            font-size: 24px;
            font-weight: 600;
            color: var(--primary);
            margin: 0;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .view-toggle {
            display: flex;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 4px;
            border: 1px solid #e9ecef;
        }

        .view-toggle button {
            padding: 8px 16px;
            border: none;
            background: transparent;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            color: #6c757d;
        }

        .view-toggle button.active {
            background: var(--primary);
            color: white;
            box-shadow: 0 2px 4px rgba(0,51,102,0.2);
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

        .btn-secondary {
            background: #6c757d;
            color: white;
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

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        /* Content Area */
        .content-area {
            padding: 30px;
            transition: all 0.3s;
        }

        /* Table View Styles */
        .table-view {
            display: block;
        }

        .table-view.hidden {
            display: none;
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

        .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-soft {
            background: #f1f3f5;
            color: #333;
        }

        /* 3D View Styles */
        .view-3d {
            display: none;
            height: calc(100vh - var(--header) - 60px);
            position: relative;
        }

        .view-3d.active {
            display: block;
        }

        #parking3d {
            width: 100%;
            height: 100%;
        }

        .controls-panel-3d {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255,255,255,0.95);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            width: 280px;
            max-height: calc(100% - 40px);
            overflow-y: auto;
            backdrop-filter: blur(10px);
        }

        .controls-panel-3d h6 {
            margin-bottom: 15px;
            color: var(--primary);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #555;
            font-size: 13px;
        }

        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(0,51,102,0.1);
        }

        .stats-3d {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .stat-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .stat-value {
            font-weight: 600;
        }

        .legend {
            margin-bottom: 20px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 3px;
            margin-right: 10px;
            border: 1px solid rgba(0,0,0,0.1);
        }

        .spot-info {
            position: absolute;
            bottom: 20px;
            left: 20px;
            background: rgba(255,255,255,0.95);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            min-width: 280px;
            display: none;
            backdrop-filter: blur(10px);
        }

        .spot-info h5 {
            margin-bottom: 15px;
            color: var(--primary);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            padding: 4px 0;
        }

        .info-row strong {
            color: #555;
        }

        .status-available { color: #28a745; font-weight: 600; }
        .status-occupied { color: #dc3545; font-weight: 600; }
        .status-reserved { color: #007bff; font-weight: 600; }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .main-content {
                margin-left: 0;
            }
            .content-area {
                padding: 20px;
            }
            .controls-panel-3d {
                position: relative;
                top: 0;
                right: 0;
                width: 100%;
                margin-bottom: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Admin Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <header class="header">
            <div class="header-left">
                <h1><i class="fas fa-parking"></i> Parking Spots</h1>
            </div>
            <div class="header-right">
                <div class="view-toggle">
                    <button id="tableViewBtn" class="active" onclick="switchView('table')">
                        <i class="fas fa-table"></i> Table View
                    </button>
                    <button id="threeDViewBtn" onclick="switchView('3d')">
                        <i class="fas fa-cube"></i> 3D View
                    </button>
                </div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSpotModal">
                    <i class="fas fa-plus"></i> Add Spot
                </button>
                <button class="btn btn-secondary" onclick="refreshData()">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
        </header>

        <div class="content-area">
            <?php if($success_message): ?>
                <div class="alert alert-success"><i class="fa fa-check-circle me-2"></i><?=htmlspecialchars($success_message)?></div>
            <?php endif; ?>
            <?php if($error_message): ?>
                <div class="alert alert-danger"><i class="fa fa-exclamation-circle me-2"></i><?=htmlspecialchars($error_message)?></div>
            <?php endif; ?>

            <!-- Table View -->
            <div id="tableView" class="table-view">
                <div class="card">
                    <div class="card-header">
                        <form class="row g-2 align-items-center" method="get">
                            <div class="col-sm-6 col-md-5">
                                <input class="form-control" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Search by spot #, campus, or block...">
                            </div>
                            <div class="col-auto">
                                <button class="btn btn-outline-primary"><i class="fa fa-search me-1"></i>Search</button>
                            </div>
                            <?php if($q!==''): ?>
                            <div class="col-auto"><a class="btn btn-outline-secondary" href="?">Clear</a></div>
                            <?php endif; ?>
                            <div class="col ms-auto text-end">
                                <span class="text-muted small">
                                <?php if ($q !== ''): ?>
                                    Found: <?=number_format($total)?> of <?=number_format(count($parking_data))?>
                                <?php else: ?>
                                    Total: <?=number_format($total)?>
                                <?php endif; ?>
                            </span>
                            </div>
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
                                        <th class="text-center">Status</th>
                                        <th class="text-center">Reserved</th>
                                        <th class="text-center" style="width:120px">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(!$table_spots): ?>
                                        <tr><td colspan="7" class="text-center p-4 text-muted">No spots found.</td></tr>
                                    <?php else: foreach($table_spots as $s): ?>
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
                                                <?php if ((int)$s['is_occupied'] === 1): ?>
                                                    <span class="badge bg-danger">Occupied</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">Available</span>
                                                <?php endif; ?>
                                            </td>
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
                        <nav>
                            <ul class="pagination mb-0">
                                <?php $base='?'.http_build_query(array_filter(['q'=>$q?:null])); $mk=fn($p)=>$base.($base!='?'?'&':'')."page=".$p; ?>
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

            <!-- 3D View -->
            <div id="threeDView" class="view-3d">
                <canvas id="parking3d"></canvas>
                
                <div class="controls-panel-3d">
                    <h6><i class="fas fa-sliders-h"></i> Controls & Filters</h6>
                    
                    <div class="form-group">
                        <label><i class="fas fa-university"></i> Campus</label>
                        <select class="form-control" id="campusFilter" onchange="applyFilters()">
                            <option value="">All Campuses</option>
                            <?php foreach($campuses as $campus): ?>
                                <option value="<?= $campus['id'] ?>"><?= htmlspecialchars($campus['name']) ?> (<?= htmlspecialchars($campus['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-building"></i> Block</label>
                        <select class="form-control" id="blockFilter" onchange="applyFilters()">
                            <option value="">All Blocks</option>
                            <?php foreach($blocks as $block): ?>
                                <option value="<?= $block['id'] ?>" data-campus="<?= $block['campus_id'] ?>">Block <?= htmlspecialchars($block['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-search"></i> Search Spot</label>
                        <input type="text" class="form-control" id="spotSearch" placeholder="Enter spot number..." onkeyup="applyFilters()">
                    </div>
                    
                    <div class="form-group">
                        <label>View Controls</label>
                        <div>
                            <button class="btn btn-secondary btn-sm" onclick="resetView()">
                                <i class="fas fa-home"></i> Reset
                            </button>
                            <button class="btn btn-secondary btn-sm" onclick="toggleGrid()" id="gridBtn">
                                <i class="fas fa-th"></i> Grid
                            </button>
                            <button class="btn btn-secondary btn-sm" onclick="toggleLabels()" id="labelBtn">
                                <i class="fas fa-tags"></i> Labels
                            </button>
                            <button class="btn btn-secondary btn-sm" onclick="toggleCars()" id="carBtn">
                                <i class="fas fa-car"></i> Cars
                            </button>
                        </div>
                    </div>

                    <div class="stats-3d">
                        <h6><i class="fas fa-chart-bar"></i> Statistics</h6>
                        <div class="stat-item">
                            <span>Total:</span>
                            <span class="stat-value" id="statsTotal"><?= number_format($stats['total']) ?></span>
                        </div>
                        <div class="stat-item">
                            <span>Available:</span>
                            <span class="stat-value status-available" id="statsAvailable"><?= number_format($stats['available']) ?></span>
                        </div>
                        <div class="stat-item">
                            <span>Occupied:</span>
                            <span class="stat-value status-occupied" id="statsOccupied"><?= number_format($stats['occupied']) ?></span>
                        </div>
                        <div class="stat-item">
                            <span>Reserved:</span>
                            <span class="stat-value status-reserved" id="statsReserved"><?= number_format($stats['reserved']) ?></span>
                        </div>
                    </div>

                    <div class="legend">
                        <h6><i class="fas fa-palette"></i> Legend</h6>
                        <div class="legend-item">
                            <div class="legend-color" style="background: #28a745;"></div>
                            <span>Available</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background: #dc3545;"></div>
                            <span>Occupied</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color" style="background: #007bff;"></div>
                            <span>Reserved</span>
                        </div>
                    </div>
                </div>
                
                <div class="spot-info" id="spotInfo">
                    <h5 id="spotTitle"><i class="fas fa-parking"></i> Spot Information</h5>
                    <div class="info-row">
                        <strong>Spot Number:</strong>
                        <span id="spotNumber">-</span>
                    </div>
                    <div class="info-row">
                        <strong>Campus:</strong>
                        <span id="spotCampus">-</span>
                    </div>
                    <div class="info-row">
                        <strong>Block:</strong>
                        <span id="spotBlock">-</span>
                    </div>
                    <div class="info-row">
                        <strong>Status:</strong>
                        <span id="spotStatus">-</span>
                    </div>
                    <div class="info-row">
                        <strong>Reserved:</strong>
                        <span id="spotReserved">-</span>
                    </div>
                    <div id="occupiedInfo" style="display:none;">
                        <hr>
                        <div class="info-row">
                            <strong>Occupied by:</strong>
                            <span id="occupantName">-</span>
                        </div>
                        <div class="info-row">
                            <strong>Since:</strong>
                            <span id="occupantTime">-</span>
                        </div>
                    </div>
                </div>
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
                        <label class="form-label">Block</label>
                        <select class="form-select" name="block_id" id="add-block">
                            <option value="0">No Block (Campus General)</option>
                            <?php foreach($blocks as $b): ?>
                                <option value="<?=$b['id']?>" data-campus="<?=$b['campus_id']?>"><?=htmlspecialchars($b['name'])?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">Leave as "No Block" if the campus doesn't have blocks yet</small>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Spot Number:</strong> Will be automatically generated based on campus code (e.g., BEI-001, TRP-001)
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_reserved" id="add-reserved">
                        <label class="form-check-label" for="add-reserved">Reserved</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary" type="submit" name="create_spot"><i class="fa fa-plus me-1"></i>Generate Spot</button>
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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
   <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script>
    // Real parking data from PHP
    const parkingData = <?= json_encode($parking_data) ?>;

    // Three.js variables
    let scene, camera, renderer;
    let parkingSpots = [];
    let allParkingSpots = []; // Keep original data
    let selectedSpot = null;
    let showGrid = true;
    let showLabels = true;
    let showCars = true;
    let currentView = 'table';
    let currentFilters = { campus: '', block: '', search: '' };

    /* ----------------------------------------------------------------
       NEW: Canvas-texture helpers (no external files)
    ----------------------------------------------------------------- */
    function makeHazardTexture(size=512, stripe=48, angleDeg=45, colorA='#FFCC00', colorB='#222') {
      const c = document.createElement('canvas'), ctx = c.getContext('2d');
      c.width = c.height = size;
      ctx.fillStyle = colorA; ctx.fillRect(0,0,size,size);
      ctx.save(); ctx.translate(size/2, size/2); ctx.rotate(angleDeg*Math.PI/180); ctx.translate(-size/2,-size/2);
      ctx.fillStyle = colorB;
      for (let x=-size; x<size*2; x+=stripe*2) ctx.fillRect(x,0,stripe,size);
      ctx.restore();
      const tex = new THREE.CanvasTexture(c);
      tex.wrapS = tex.wrapT = THREE.RepeatWrapping;
      tex.anisotropy = 8;
      return tex;
    }

    function makeSignTexture({title='F2', subtitle='', w=512, h=512, bg='#2F80ED', fg='#fff'}) {
      const c = document.createElement('canvas'), ctx = c.getContext('2d');
      c.width = w; c.height = h;
      ctx.fillStyle = bg; ctx.fillRect(0,0,w,h);
      ctx.strokeStyle = 'rgba(255,255,255,0.7)'; ctx.lineWidth = Math.max(6, w*0.01);
      ctx.strokeRect(ctx.lineWidth, ctx.lineWidth, w-ctx.lineWidth*2, h-ctx.lineWidth*2);
      ctx.fillStyle = fg; ctx.textAlign = 'center';
      ctx.font = `bold ${Math.floor(h*0.45)}px Inter, Arial`; ctx.fillText(title, w/2, h*0.58);
      if (subtitle) { ctx.font = `bold ${Math.floor(h*0.14)}px Inter, Arial`; ctx.fillText(subtitle, w/2, h*0.85); }
      const tex = new THREE.CanvasTexture(c); tex.anisotropy = 8; return tex;
    }

    function makeFloorTextTexture({text='P', sub='PARKING', w=1024, h=1024, fg='#FFFFFF'}) {
      const c = document.createElement('canvas'), ctx = c.getContext('2d');
      c.width = w; c.height = h;
      ctx.clearRect(0,0,w,h);
      ctx.fillStyle = fg; ctx.textAlign = 'center';
      ctx.font = `bold ${Math.floor(h*0.55)}px Inter, Arial`; ctx.fillText(text, w/2, h*0.58);
      ctx.font = `bold ${Math.floor(h*0.14)}px Inter, Arial`; ctx.fillText(sub, w/2, h*0.90);
      const tex = new THREE.CanvasTexture(c); tex.anisotropy = 8; return tex;
    }

    function makeLineMaterial(color=0xFFFFFF, opacity=0.9) {
      return new THREE.MeshBasicMaterial({ color, transparent: true, opacity, side: THREE.DoubleSide });
    }

    /* ----------------------------------------------------------------
       NEW: Builders for pillars, cones, barriers, floor marks, walls
    ----------------------------------------------------------------- */
    function createPillar({x=0, z=0, height=4.2, size=1.2, levelText='F2'}) {
      const group = new THREE.Group();
      const colGeom = new THREE.BoxGeometry(size, height, size);
      const colMat  = new THREE.MeshStandardMaterial({ color: 0xE6E6EA, roughness: 0.9, metalness: 0.0 });
      const column  = new THREE.Mesh(colGeom, colMat);
      column.castShadow = column.receiveShadow = true;
      column.position.set(0, height/2, 0);
      group.add(column);

      const hzGeom = new THREE.BoxGeometry(size*1.02, height*0.35, size*1.02);
      const hzMat  = new THREE.MeshStandardMaterial({ map: makeHazardTexture(), roughness: 0.8 });
      const hazard = new THREE.Mesh(hzGeom, hzMat);
      hazard.position.set(0, hzGeom.parameters.height/2, 0);
      hazard.castShadow = hazard.receiveShadow = true;
      group.add(hazard);

      const bandGeom = new THREE.BoxGeometry(size*1.03, 0.18, size*1.03);
      const bandMat  = new THREE.MeshStandardMaterial({ color: 0xFF5E7A, roughness: 0.6 });
      const band = new THREE.Mesh(bandGeom, bandMat);
      band.position.set(0, hazard.position.y + hazard.geometry.parameters.height/2 + 0.1, 0);
      group.add(band);

      const signTex = makeSignTexture({title: levelText, bg:'#2E86C1'});
      const signMat = new THREE.MeshBasicMaterial({ map: signTex });
      const signGeom = new THREE.PlaneGeometry(size*0.9, size*0.9);
      [0, Math.PI/2, Math.PI, -Math.PI/2].forEach(rotY => {
        const p = new THREE.Mesh(signGeom, signMat);
        p.position.set(0, height*0.70, size*0.51);
        const holder = new THREE.Group();
        holder.rotation.y = rotY;
        holder.add(p);
        group.add(holder);
      });

      group.position.set(x, 0, z);
      return group;
    }

    function createTrafficCone({x=0, z=0, scale=1}) {
      const g = new THREE.Group();
      const ring = (y, rTop, rBottom, h, color) => {
        const geom = new THREE.CylinderGeometry(rTop, rBottom, h, 24);
        const mat  = new THREE.MeshStandardMaterial({ color, roughness: 0.6, metalness: 0.0 });
        const m    = new THREE.Mesh(geom, mat);
        m.position.y = y; m.castShadow = m.receiveShadow = true; return m;
      };
      g.add(ring(0.10, 0.34, 0.38, 0.20, 0xff6a00));
      g.add(ring(0.32, 0.26, 0.30, 0.18, 0xffffff));
      g.add(ring(0.48, 0.20, 0.24, 0.16, 0xff6a00));
      g.add(ring(0.62, 0.14, 0.18, 0.14, 0xffffff));
      const tip = new THREE.Mesh(new THREE.ConeGeometry(0.14, 0.22, 24),
                                 new THREE.MeshStandardMaterial({ color: 0xff6a00, roughness: 0.6 }));
      tip.position.y = 0.85; tip.castShadow = tip.receiveShadow = true; g.add(tip);
      g.position.set(x,0,z); g.scale.setScalar(scale); return g;
    }

    function createBarrier({x1=0, z1=0, x2=4, z2=0, height=0.9, thickness=0.2}) {
      const len = Math.hypot(x2-x1, z2-z1);
      const geom = new THREE.BoxGeometry(len, height, thickness);
      const mat  = new THREE.MeshStandardMaterial({ map: makeHazardTexture(512, 32, 15, '#FFD54A', '#222') });
      const mesh = new THREE.Mesh(geom, mat);
      mesh.castShadow = mesh.receiveShadow = true;
      mesh.position.set((x1+x2)/2, height/2, (z1+z2)/2);
      mesh.rotation.y = Math.atan2(x2-x1, z2-z1);
      return mesh;
    }

    function createFloorBox({x=0, z=0, w=3.0, h=5.5}) {
      const line = new THREE.Mesh(new THREE.PlaneGeometry(w, h), makeLineMaterial(0xFFFF66, 0.95));
      line.rotation.x = -Math.PI/2; line.position.set(x, 0.011, z); return line;
    }

    function createFloorLabel({x=0, z=0}) {
      const tex = makeFloorTextTexture({text:'P', sub:'PARKING'});
      const mat = new THREE.MeshBasicMaterial({ map: tex, transparent: true });
      const mesh = new THREE.Mesh(new THREE.PlaneGeometry(5, 5), mat);
      mesh.rotation.x = -Math.PI/2; mesh.position.set(x, 0.012, z); return mesh;
    }

    function createWallSign({x=0, z=0, w=2.2, h=2.2, text='P', sub='PARKING', bg='#2E86C1'}) {
      const tex = makeSignTexture({title:text, subtitle:sub, w:1024, h:1024, bg});
      const mat = new THREE.MeshBasicMaterial({ map: tex });
      const mesh = new THREE.Mesh(new THREE.PlaneGeometry(w, h), mat);
      mesh.position.set(x, 2.2, z); return mesh;
    }

    function buildRoom({w=80, h=12, d=60}) {
      const geom = new THREE.BoxGeometry(w, h, d);
      const mat  = new THREE.MeshStandardMaterial({ color: 0xE9EDF2, roughness: 0.95, metalness: 0.0, side: THREE.BackSide });
      const room = new THREE.Mesh(geom, mat);
      room.position.y = h/2; room.receiveShadow = true; return room;
    }

    function addCeilingLights() {
      // Simple ceiling point lights (no example libs needed)
      const p1 = new THREE.PointLight(0xffffff, 0.9, 60); p1.position.set(-12, 9, -10); scene.add(p1);
      const p2 = new THREE.PointLight(0xffffff, 0.9, 60); p2.position.set( 12, 9, -10); scene.add(p2);
      const p3 = new THREE.PointLight(0xffffff, 0.9, 60); p3.position.set(-12, 9,  10); scene.add(p3);
      const p4 = new THREE.PointLight(0xffffff, 0.9, 60); p4.position.set( 12, 9,  10); scene.add(p4);
    }

    // Master environment builder
    function buildEnvironment() {
      scene.background = new THREE.Color(0xEAF2FB);
      scene.fog = new THREE.Fog(0xEAF2FB, 40, 120);

      // Room/walls + a darker floor overlay
      const room = buildRoom({}); scene.add(room);
      const floorTint = new THREE.Mesh(
        new THREE.PlaneGeometry(100, 100),
        new THREE.MeshStandardMaterial({ color: 0x3C4048, roughness: 1.0, metalness: 0.0 })
      );
      floorTint.rotation.x = -Math.PI/2; floorTint.receiveShadow = true; floorTint.position.y = 0.001;
      scene.add(floorTint);

      // Ceiling lights
      addCeilingLights();

      // Pillars (similar spacing to the reference)
      [
        [-18,-10], [  0,-10], [ 18,-10],
        [-18, 10], [  0, 10], [ 18, 10]
      ].forEach(([x,z]) => scene.add(createPillar({x, z, levelText:'F2'})));

      // Wall signs
      const signs = [
        createWallSign({x:-38, z:  0, w:2.4, h:2.4, text:'P', sub:'PARKING', bg:'#2E86C1'}),
        createWallSign({x: 38, z:  0, w:2.4, h:2.4, text:'P', sub:'PARKING', bg:'#2E86C1'}),
        createWallSign({x:  0, z:-28, w:2.4, h:1.6, text:'NO', sub:'PARKING', bg:'#E74C3C'})
      ];
      signs[0].rotation.y =  Math.PI/2;  // left wall
      signs[1].rotation.y = -Math.PI/2;  // right wall
      signs.forEach(s => scene.add(s));

      // Painted parking bays + big "P PARKING" floor label
      scene.add(createFloorLabel({x: -30, z: 16}));
      const bayOrigin = {x:-24, z:14}; const bays = 6, gap=3.8;
      for (let i=0;i<bays;i++) scene.add(createFloorBox({x: bayOrigin.x + i*gap, z: bayOrigin.z, w:2.6, h:5.6}));

      // Cones (two rows like a practice lane)
      const cones = [];
      for (let i=0;i<8;i++){
        cones.push(createTrafficCone({x:-8+i*2.6, z: -2.0, scale:1}));
        cones.push(createTrafficCone({x:-8+i*2.6, z:  2.0, scale:1}));
      }
      cones.forEach(c => scene.add(c));

      // Yellow/black barriers
      scene.add(createBarrier({x1:-6, z1:-6, x2: 6, z2:-6, height:0.9}));
      scene.add(createBarrier({x1:-6, z1:  6, x2: 6, z2:  6, height:0.9}));

      // Long white lane line
      const longLane = new THREE.Mesh(new THREE.PlaneGeometry(40, 0.2), makeLineMaterial(0xFFFFFF, 0.9));
      longLane.rotation.x = -Math.PI/2; longLane.position.set(0, 0.012, 0); scene.add(longLane);
    }

    /* ----------------------------------------------------------------
       Existing view switching (unchanged)
    ----------------------------------------------------------------- */
    function switchView(view) {
      const tableView = document.getElementById('tableView');
      const threeDView = document.getElementById('threeDView');
      const tableBtn = document.getElementById('tableViewBtn');
      const threeDBtn = document.getElementById('threeDViewBtn');

      if (view === 'table') {
        tableView.style.display = 'block';
        threeDView.classList.remove('active');
        tableBtn.classList.add('active');
        threeDBtn.classList.remove('active');
        currentView = 'table';
      } else {
        tableView.style.display = 'none';
        threeDView.classList.add('active');
        tableBtn.classList.remove('active');
        threeDBtn.classList.add('active');
        currentView = '3d';
        if (!scene) setTimeout(initThree, 100);
      }
    }

    /* ----------------------------------------------------------------
       Filters (unchanged)
    ----------------------------------------------------------------- */
    function applyFilters() {
      const campusFilter = document.getElementById('campusFilter').value;
      const blockFilter = document.getElementById('blockFilter').value;
      const searchFilter = document.getElementById('spotSearch').value.toLowerCase();
      currentFilters = { campus: campusFilter, block: blockFilter, search: searchFilter };
      updateBlockDropdown();
      if (scene) filterAndUpdate3D();
    }

    function updateBlockDropdown() {
      const campusFilter = currentFilters.campus;
      const blockSelect = document.getElementById('blockFilter');
      const options = blockSelect.querySelectorAll('option[data-campus]');
      options.forEach(option => {
        if (campusFilter === '' || option.dataset.campus === campusFilter) {
          option.style.display = '';
        } else {
          option.style.display = 'none';
          if (option.selected) { blockSelect.value = ''; currentFilters.block = ''; }
        }
      });
    }

    function filterAndUpdate3D() {
      const spotsToRemove = [];
      scene.children.forEach(child => {
        if (child.userData && child.userData.spotData) spotsToRemove.push(child);
        if (child.name === 'car' || child.name === 'label') spotsToRemove.push(child);
      });
      spotsToRemove.forEach(spot => scene.remove(spot));

      let filteredData = parkingData.filter(spot => {
        let ok = true;
        if (currentFilters.campus && String(spot.campus_id) !== currentFilters.campus) ok = false;
        if (currentFilters.block && String(spot.block_id) !== currentFilters.block) ok = false;
        if (currentFilters.search &&
            !spot.spot_number.toLowerCase().includes(currentFilters.search) &&
            !(spot.campus_name && spot.campus_name.toLowerCase().includes(currentFilters.search)) &&
            !(spot.block_name && spot.block_name.toLowerCase().includes(currentFilters.search))) ok = false;
        return ok;
      });

      parkingSpots = [];
      createFilteredParkingLayout(filteredData);
      updateStats(filteredData);
    }

    /* ----------------------------------------------------------------
       Existing 3D creation of spots/cars/labels (unchanged)
    ----------------------------------------------------------------- */
    function createFilteredParkingLayout(filteredData) {
      const groupedSpots = {};
      filteredData.forEach(spot => {
        const key = `${spot.campus_name}_${spot.block_name || 'main'}`;
        if (!groupedSpots[key]) groupedSpots[key] = [];
        groupedSpots[key].push(spot);
      });

      Object.keys(groupedSpots).forEach((groupKey, groupIndex) => {
        const spots = groupedSpots[groupKey];
        const spotsPerRow = 8;
        const groupX = (groupIndex % 3) * 25 - 25;
        const groupZ = Math.floor(groupIndex / 3) * 20 - 10;

        spots.forEach((spot, index) => {
          const row = Math.floor(index / spotsPerRow);
          const col = index % spotsPerRow;
          const x = groupX + (col * 3);
          const z = groupZ + (row * 6);

          const spotMesh = createParkingSpot(spot, x, z);
          scene.add(spotMesh);
          parkingSpots.push({ ...spot, mesh: spotMesh, x, z });

          if (spot.is_occupied && showCars) scene.add(createCar(x, z));
          if (showLabels) scene.add(createLabel(spot.spot_number, x, z));
        });
      });
    }

    function updateStats(data) {
      const stats = {
        total: data.length,
        available: data.filter(s => !s.is_occupied && !s.is_reserved).length,
        occupied: data.filter(s => s.is_occupied).length,
        reserved: data.filter(s => s.is_reserved && !s.is_occupied).length
      };
      document.getElementById('statsTotal').textContent = stats.total;
      document.getElementById('statsAvailable').textContent = stats.available;
      document.getElementById('statsOccupied').textContent = stats.occupied;
      document.getElementById('statsReserved').textContent = stats.reserved;
    }

    // Initialize Three.js (MODIFIED: call buildEnvironment())
    function initThree() {
      const canvas = document.getElementById('parking3d');
      if (!canvas) return;

      scene = new THREE.Scene();

      camera = new THREE.PerspectiveCamera(75, canvas.clientWidth / canvas.clientHeight, 0.1, 1000);
      camera.position.set(15, 12, 15);

      renderer = new THREE.WebGLRenderer({ canvas: canvas, antialias: true });
      renderer.setSize(canvas.clientWidth, canvas.clientHeight);
      renderer.shadowMap.enabled = true;
      renderer.shadowMap.type = THREE.PCFSoftShadowMap;

      // Base lights
      const ambientLight = new THREE.AmbientLight(0x404040, 0.5); scene.add(ambientLight);
      const dir = new THREE.DirectionalLight(0xffffff, 0.6); dir.position.set(20, 20, 10);
      dir.castShadow = true; dir.shadow.mapSize.width = dir.shadow.mapSize.height = 2048; scene.add(dir);

      // Ground (keep)
      const groundGeometry = new THREE.PlaneGeometry(100, 100);
      const groundMaterial = new THREE.MeshLambertMaterial({ color: 0x555555 });
      const ground = new THREE.Mesh(groundGeometry, groundMaterial);
      ground.rotation.x = -Math.PI / 2; ground.receiveShadow = true; scene.add(ground);

      // Grid (toggle-able)
      const gridHelper = new THREE.GridHelper(100, 50, 0x444444, 0x444444); gridHelper.name = 'grid'; scene.add(gridHelper);

      // NEW: Build the indoor environment (pillars, cones, signs, lines)
      buildEnvironment();

      createParkingLayout();
      setupMouseControls();
      animate();
      window.addEventListener('resize', onWindowResize);
    }

    function createParkingLayout() {
      allParkingSpots = [...parkingData];
      createFilteredParkingLayout(parkingData);
    }

    function createParkingSpot(spot, x, z) {
      const boxGeometry = new THREE.BoxGeometry(2.2, 0.15, 4.8);
      let color;
      if (spot.is_occupied) color = 0xdc3545; else if (spot.is_reserved) color = 0x007bff; else color = 0x28a745;
      const material = new THREE.MeshLambertMaterial({ color });
      const mesh = new THREE.Mesh(boxGeometry, material);
      mesh.position.set(x, 0.075, z);
      mesh.castShadow = mesh.receiveShadow = true;
      mesh.userData = { spotData: spot };

      // Parking lines
      const lineGeometry = new THREE.PlaneGeometry(2.4, 5);
      const lineMaterial = new THREE.MeshBasicMaterial({ color: 0xffffff, transparent: true, opacity: 0.8, side: THREE.DoubleSide });
      const lines = new THREE.Mesh(lineGeometry, lineMaterial);
      lines.rotation.x = -Math.PI / 2; lines.position.set(x, 0.16, z);
      scene.add(lines);
      return mesh;
    }

    function createCar(x, z) {
      const carGroup = new THREE.Group();
      const bodyGeometry = new THREE.BoxGeometry(1.8, 0.8, 4.2);
      const bodyMaterial = new THREE.MeshLambertMaterial({ color: Math.random()*0xffffff });
      const carBody = new THREE.Mesh(bodyGeometry, bodyMaterial); carBody.position.y = 0.6; carBody.castShadow = true; carGroup.add(carBody);
      const roofGeometry = new THREE.BoxGeometry(1.6, 0.6, 2.5);
      const carRoof = new THREE.Mesh(roofGeometry, bodyMaterial); carRoof.position.set(0,1.1,-0.3); carRoof.castShadow=true; carGroup.add(carRoof);
      const wheelGeometry = new THREE.CylinderGeometry(0.3,0.3,0.2);
      const wheelMaterial = new THREE.MeshLambertMaterial({ color: 0x333333 });
      [{x:-0.8,z:1.4},{x:0.8,z:1.4},{x:-0.8,z:-1.4},{x:0.8,z:-1.4}].forEach(pos=>{
        const w=new THREE.Mesh(wheelGeometry,wheelMaterial); w.rotation.z=Math.PI/2; w.position.set(pos.x,0.3,pos.z); w.castShadow=true; carGroup.add(w);
      });
      carGroup.position.set(x,0,z); carGroup.name='car'; return carGroup;
    }

    function createLabel(text, x, z) {
      const canvas = document.createElement('canvas'); const ctx = canvas.getContext('2d');
      canvas.width = 256; canvas.height = 128;
      ctx.fillStyle = 'rgba(255,255,255,0.9)'; ctx.fillRect(0,0,256,128);
      ctx.strokeStyle = '#333'; ctx.strokeRect(0,0,256,128);
      ctx.fillStyle = '#333'; ctx.font = 'bold 24px Arial'; ctx.textAlign = 'center'; ctx.fillText(text, 128, 128/2+8);
      const texture = new THREE.CanvasTexture(canvas);
      const material = new THREE.SpriteMaterial({ map: texture });
      const sprite = new THREE.Sprite(material);
      sprite.position.set(x, 2.5, z); sprite.scale.set(2,1,1); sprite.name='label'; return sprite;
    }

    function setupMouseControls() {
      const canvas = document.getElementById('parking3d');
      const raycaster = new THREE.Raycaster(); const mouse = new THREE.Vector2();
      let isMouseDown = false; let mouseX = 0, mouseY = 0;

      canvas.addEventListener('mousedown', (e) => { if (e.button===0){isMouseDown=true; mouseX=e.clientX; mouseY=e.clientY;} });
      canvas.addEventListener('mouseup', (e) => {
        if (e.button===0 && !isMouseDown) {
          const rect = canvas.getBoundingClientRect();
          mouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
          mouse.y = -((e.clientY - rect.top) / rect.height) * 2 + 1;
          raycaster.setFromCamera(mouse, camera);
          const intersects = raycaster.intersectObjects(parkingSpots.map(s => s.mesh));
          if (intersects.length > 0) selectSpot(intersects[0].object.userData.spotData); else deselectSpot();
        }
        isMouseDown = false;
      });

      canvas.addEventListener('mousemove', (e) => {
        if (isMouseDown) {
          const dx = e.clientX - mouseX, dy = e.clientY - mouseY;
          const spherical = new THREE.Spherical(); spherical.setFromVector3(camera.position);
          spherical.theta -= dx*0.01; spherical.phi = Math.max(0.1, Math.min(Math.PI-0.1, spherical.phi + dy*0.01));
          camera.position.setFromSpherical(spherical); camera.lookAt(0,0,0);
          mouseX = e.clientX; mouseY = e.clientY;
        }
      });

      canvas.addEventListener('wheel', (e) => { e.preventDefault(); const s = e.deltaY>0 ? 1.1 : 0.9; camera.position.multiplyScalar(s); camera.lookAt(0,0,0); }, {passive:false});
    }

    function selectSpot(spot) { selectedSpot = spot; showSpotInfo(spot); }
    function deselectSpot() { selectedSpot = null; document.getElementById('spotInfo').style.display = 'none'; }

    function showSpotInfo(spot) {
      const info = document.getElementById('spotInfo');
      document.getElementById('spotNumber').textContent = spot.spot_number;
      document.getElementById('spotCampus').textContent = spot.campus_name || 'Unknown';
      document.getElementById('spotBlock').textContent = spot.block_name || 'Main';
      let statusText='Available', statusClass='status-available';
      if (spot.is_occupied){ statusText='Occupied'; statusClass='status-occupied'; }
      else if (spot.is_reserved){ statusText='Reserved'; statusClass='status-reserved'; }
      const statusSpan = document.getElementById('spotStatus'); statusSpan.textContent=statusText; statusSpan.className=statusClass;
      document.getElementById('spotReserved').textContent = spot.is_reserved ? 'Yes' : 'No';
      const occupiedInfo = document.getElementById('occupiedInfo');
      if (spot.is_occupied && spot.FIRST) {
        document.getElementById('occupantName').textContent = `${spot.FIRST} ${spot.Last || ''}`.trim();
        document.getElementById('occupantTime').textContent = spot.entrance_at ? new Date(spot.entrance_at).toLocaleString() : 'Unknown';
        occupiedInfo.style.display = 'block';
      } else { occupiedInfo.style.display = 'none'; }
      info.style.display = 'block';
    }

    function animate() {
      requestAnimationFrame(animate);
      if (renderer && scene && camera) renderer.render(scene, camera);
    }

    function onWindowResize() {
      if (!camera || !renderer) return;
      const canvas = document.getElementById('parking3d');
      camera.aspect = canvas.clientWidth / canvas.clientHeight; camera.updateProjectionMatrix();
      renderer.setSize(canvas.clientWidth, canvas.clientHeight);
    }

    // Controls
    function resetView() {
      if (camera) { camera.position.set(15, 12, 15); camera.lookAt(0, 0, 0); }
      document.getElementById('campusFilter').value = '';
      document.getElementById('blockFilter').value = '';
      document.getElementById('spotSearch').value = '';
      currentFilters = { campus: '', block: '', search: '' };
      if (scene) filterAndUpdate3D();
    }

    function toggleGrid() {
      if (!scene) return;
      showGrid = !showGrid;
      const grid = scene.getObjectByName('grid');
      if (grid) grid.visible = showGrid;
    }

    function toggleLabels() {
      if (!scene) return;
      showLabels = !showLabels;
      scene.children.forEach(child => { if (child.name === 'label') child.visible = showLabels; });
      if (currentFilters.campus || currentFilters.block || currentFilters.search) filterAndUpdate3D();
    }

    function toggleCars() {
      if (!scene) return;
      showCars = !showCars;
      scene.children.forEach(child => { if (child.name === 'car') child.visible = showCars; });
      if (currentFilters.campus || currentFilters.block || currentFilters.search) filterAndUpdate3D();
    }

    function refreshData() { window.location.reload(); }

    // Modal handling for table view (unchanged)
    function filterBlocks(selectEl, campusId) {
      const options = selectEl.querySelectorAll('option[data-campus]'); let firstVisible = null;
      options.forEach(opt => {
        const visible = String(opt.dataset.campus) === String(campusId);
        opt.hidden = !visible; if (visible && !firstVisible) firstVisible = opt;
      });
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

    const editModal = document.getElementById('editSpotModal');
    editModal.addEventListener('show.bs.modal', event => {
      const btn = event.relatedTarget;
      document.getElementById('edit-id').value = btn.getAttribute('data-id');
      document.getElementById('edit-spot').value = btn.getAttribute('data-spot');
      const campusId = btn.getAttribute('data-campus') || '';
      const blockId  = btn.getAttribute('data-block')  || '';
      const reserved = btn.getAttribute('data-reserved') === '1';
      const campusSel = document.getElementById('edit-campus'); campusSel.value = campusId;
      filterBlocks(document.getElementById('edit-block'), campusId);
      document.getElementById('edit-block').value = blockId;
      document.getElementById('edit-reserved').checked = reserved;
    });

    // Initialize block filters
    filterBlocks(document.getElementById('add-block'), document.getElementById('add-campus').value);
</script>

</body>
</html>