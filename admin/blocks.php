<?php
/**
 * LIU Parking System - Blocks Management
 * File: admin/blocks.php
 * Manage parking blocks within campuses
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
$selected_campus = (int)($_GET['campus_id'] ?? 0);

/* ---------- Handle form submissions ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (isset($_POST['create_block'])) {
        $name = trim($_POST['name'] ?? '');
        $campus_id = (int)($_POST['campus_id'] ?? 0);

        if ($name === '' || $campus_id <= 0) {
            $error_message = 'Please provide block name and select a campus.';
        } else {
            try {
                // Check if block name already exists in this campus
                $check = $pdo->prepare("SELECT id FROM blocks WHERE campus_id=? AND name=?");
                $check->execute([$campus_id, $name]);
                
                if ($check->fetch()) {
                    $error_message = 'Block name already exists in this campus.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO blocks (campus_id, name) VALUES (?, ?)");
                    $stmt->execute([$campus_id, $name]);
                    $success_message = 'Block created successfully.';
                    $selected_campus = $campus_id;
                }
            } catch(PDOException $e) {
                $error_message = 'Database error: '.$e->getMessage();
            }
        }
    }
    
    if (isset($_POST['update_block'])) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $campus_id = (int)($_POST['campus_id'] ?? 0);

        if ($id <= 0 || $name === '' || $campus_id <= 0) {
            $error_message = 'Please provide valid block information.';
        } else {
            try {
                // Check if block name already exists in this campus (excluding current block)
                $check = $pdo->prepare("SELECT id FROM blocks WHERE campus_id=? AND name=? AND id<>?");
                $check->execute([$campus_id, $name, $id]);
                
                if ($check->fetch()) {
                    $error_message = 'Block name already exists in this campus.';
                } else {
                    $stmt = $pdo->prepare("UPDATE blocks SET campus_id=?, name=? WHERE id=?");
                    $stmt->execute([$campus_id, $name, $id]);
                    $success_message = 'Block updated successfully.';
                    $selected_campus = $campus_id;
                }
            } catch(PDOException $e) {
                $error_message = 'Database error: '.$e->getMessage();
            }
        }
    }
    
    if (isset($_POST['delete_block'])) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                // Check if block has parking spots
                $check = $pdo->prepare("SELECT COUNT(*) as spot_count FROM parking_spots WHERE block_id=?");
                $check->execute([$id]);
                $spot_count = $check->fetch()['spot_count'];
                
                if ($spot_count > 0) {
                    $error_message = "Cannot delete block: it contains {$spot_count} parking spots. Please remove or reassign the spots first.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM blocks WHERE id=?");
                    $stmt->execute([$id]);
                    $success_message = 'Block deleted successfully.';
                }
            } catch(PDOException $e) {
                $error_message = 'Database error: '.$e->getMessage();
            }
        }
    }
}

// Get campuses for dropdown
$campuses = $pdo->query("SELECT id, name, code FROM campuses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get blocks with related data
$blocks_query = "
    SELECT 
        b.id,
        b.name,
        b.campus_id,
        c.name as campus_name,
        c.code as campus_code,
        COUNT(DISTINCT ps.id) as total_spots,
        COUNT(DISTINCT CASE WHEN ps.is_occupied = 1 THEN ps.id END) as occupied_spots,
        COUNT(DISTINCT CASE WHEN ps.is_reserved = 1 THEN ps.id END) as reserved_spots,
        COALESCE(cap.capacity, 0) as capacity_limit
    FROM blocks b
    LEFT JOIN campuses c ON c.id = b.campus_id
    LEFT JOIN parking_spots ps ON ps.block_id = b.id
    LEFT JOIN capacities cap ON cap.block_id = b.id
    " . ($selected_campus > 0 ? "WHERE b.campus_id = ?" : "") . "
    GROUP BY b.id, b.name, b.campus_id, c.name, c.code, cap.capacity
    ORDER BY c.name, b.name
";

if ($selected_campus > 0) {
    $stmt = $pdo->prepare($blocks_query);
    $stmt->execute([$selected_campus]);
} else {
    $stmt = $pdo->query($blocks_query);
}
$blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get selected campus info
$selected_campus_info = null;
if ($selected_campus > 0) {
    $stmt = $pdo->prepare("SELECT name, code FROM campuses WHERE id=?");
    $stmt->execute([$selected_campus]);
    $selected_campus_info = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get campus statistics
$campus_stats_query = "
    SELECT 
        c.id,
        c.name,
        c.code,
        COUNT(DISTINCT b.id) as total_blocks,
        COUNT(DISTINCT ps.id) as total_spots,
        COUNT(DISTINCT CASE WHEN ps.is_occupied = 1 THEN ps.id END) as occupied_spots
    FROM campuses c
    LEFT JOIN blocks b ON b.campus_id = c.id
    LEFT JOIN parking_spots ps ON ps.block_id = b.id
    GROUP BY c.id, c.name, c.code
    ORDER BY c.name
";
$campus_stats = $pdo->query($campus_stats_query)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Block Management - LIU Parking System</title>
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

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        /* Content Area */
        .content-area {
            padding: 30px;
        }

        .card {
            border: 1px solid #e9ecef;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            background: white;
            margin-bottom: 30px;
        }

        .card-header {
            padding: 20px;
            border-bottom: 1px solid #e9ecef;
            background: #f8f9fa;
            border-radius: 12px 12px 0 0;
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
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

        .campus-filter {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            margin-bottom: 30px;
        }

        .campus-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .campus-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            border-left: 4px solid var(--primary);
            transition: transform 0.2s;
        }

        .campus-card:hover {
            transform: translateY(-2px);
        }

        .campus-card h5 {
            color: var(--primary);
            margin-bottom: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .campus-stat {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .campus-stat .value {
            font-weight: 600;
        }

        .selected-campus-banner {
            background: linear-gradient(135deg, var(--primary), #004080);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            text-align: center;
        }

        .selected-campus-banner h4 {
            margin: 0;
            font-weight: 600;
        }

        .selected-campus-banner p {
            margin: 5px 0 0;
            opacity: 0.9;
        }

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
            .campus-overview {
                grid-template-columns: 1fr;
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
                <h1><i class="fas fa-building"></i> Block Management</h1>
            </div>
            <div class="header-right">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#blockModal">
                    <i class="fas fa-plus"></i> Add Block
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

            <!-- Selected Campus Banner -->
            <?php if($selected_campus_info): ?>
                <div class="selected-campus-banner">
                    <h4><i class="fas fa-map-marker-alt me-2"></i>Viewing blocks for <?= htmlspecialchars($selected_campus_info['name']) ?> Campus</h4>
                    <p>Campus Code: <?= htmlspecialchars($selected_campus_info['code']) ?></p>
                </div>
            <?php endif; ?>

            <!-- Campus Filter -->
            <div class="campus-filter">
                <div class="row align-items-center">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Filter by Campus:</label>
                    </div>
                    <div class="col-md-6">
                        <select class="form-select" id="campusFilter" onchange="filterByCampus()">
                            <option value="0">All Campuses</option>
                            <?php foreach($campuses as $campus): ?>
                                <option value="<?= $campus['id'] ?>" <?= $selected_campus == $campus['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($campus['name']) ?> (<?= htmlspecialchars($campus['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-outline-primary w-100" onclick="clearFilter()">
                            <i class="fas fa-times"></i> Clear Filter
                        </button>
                    </div>
                </div>
            </div>

            <!-- Campus Overview (only show if not filtered) -->
            <?php if($selected_campus == 0): ?>
                <div class="campus-overview">
                    <?php foreach($campus_stats as $stat): ?>
                        <div class="campus-card">
                            <h5>
                                <?= htmlspecialchars($stat['name']) ?>
                                <span class="badge bg-secondary"><?= htmlspecialchars($stat['code']) ?></span>
                            </h5>
                            <div class="campus-stat">
                                <span>Blocks:</span>
                                <span class="value"><?= number_format($stat['total_blocks']) ?></span>
                            </div>
                            <div class="campus-stat">
                                <span>Total Spots:</span>
                                <span class="value"><?= number_format($stat['total_spots']) ?></span>
                            </div>
                            <div class="campus-stat">
                                <span>Occupied:</span>
                                <span class="value"><?= number_format($stat['occupied_spots']) ?></span>
                            </div>
                            <div class="mt-3">
                                <a href="?campus_id=<?= $stat['id'] ?>" class="btn btn-outline-primary btn-sm w-100">
                                    <i class="fas fa-eye"></i> View Blocks
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Blocks Table -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        <?= $selected_campus > 0 ? 'Campus Blocks' : 'All Blocks' ?>
                    </h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Block Name</th>
                                    <?php if($selected_campus == 0): ?>
                                        <th>Campus</th>
                                    <?php endif; ?>
                                    <th>Total Spots</th>
                                    <th>Occupied</th>
                                    <th>Reserved</th>
                                    <th>Capacity Limit</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(!$blocks): ?>
                                    <tr>
                                        <td colspan="<?= $selected_campus == 0 ? '8' : '7' ?>" class="text-center p-4 text-muted">
                                            <i class="fas fa-info-circle fa-2x mb-3 d-block"></i>
                                            <?= $selected_campus > 0 ? 'No blocks found for this campus.' : 'No blocks configured yet.' ?>
                                            <br>
                                            <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#blockModal">
                                                <i class="fas fa-plus"></i> Add First Block
                                            </button>
                                        </td>
                                    </tr>
                                <?php else: foreach($blocks as $block): ?>
                                    <tr>
                                        <td><?= $block['id'] ?></td>
                                        <td>
                                            <span class="fw-semibold">Block <?= htmlspecialchars($block['name']) ?></span>
                                        </td>
                                        <?php if($selected_campus == 0): ?>
                                            <td>
                                                <div><?= htmlspecialchars($block['campus_name']) ?></div>
                                                <small class="text-muted"><?= htmlspecialchars($block['campus_code']) ?></small>
                                            </td>
                                        <?php endif; ?>
                                        <td>
                                            <span class="badge bg-info"><?= number_format($block['total_spots']) ?></span>
                                        </td>
                                        <td>
                                            <?php if($block['occupied_spots'] > 0): ?>
                                                <span class="badge bg-warning"><?= number_format($block['occupied_spots']) ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-success">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if($block['reserved_spots'] > 0): ?>
                                                <span class="badge bg-primary"><?= number_format($block['reserved_spots']) ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if($block['capacity_limit'] > 0): ?>
                                                <span class="fw-semibold"><?= number_format($block['capacity_limit']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">Not set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-outline-primary me-1" 
                                                    onclick="editBlock(<?= $block['id'] ?>, '<?= htmlspecialchars($block['name']) ?>', <?= $block['campus_id'] ?>)"
                                                    data-bs-toggle="modal" data-bs-target="#blockModal">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-info me-1" 
                                                    onclick="window.location.href='spots.php?block_id=<?= $block['id'] ?>'">
                                                <i class="fas fa-parking"></i>
                                            </button>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this block? This will also affect <?= $block['total_spots'] ?> parking spots.')">
                                                <input type="hidden" name="id" value="<?= $block['id'] ?>">
                                                <button type="submit" name="delete_block" class="btn btn-sm btn-outline-danger"
                                                        <?= $block['total_spots'] > 0 ? 'disabled title="Cannot delete: contains parking spots"' : '' ?>>
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Block Modal -->
    <div class="modal fade" id="blockModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" method="post">
                <input type="hidden" name="id" id="block_id" value="0">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-building me-2"></i>
                        <span id="modal_title">Add New Block</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Campus *</label>
                        <select class="form-select" name="campus_id" id="modal_campus" required>
                            <option value="">Select campus</option>
                            <?php foreach($campuses as $campus): ?>
                                <option value="<?= $campus['id'] ?>" <?= $selected_campus == $campus['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($campus['name']) ?> (<?= htmlspecialchars($campus['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Block Name *</label>
                        <input type="text" name="name" id="modal_name" class="form-control" required 
                               placeholder="e.g., A, B, North, East, etc.">
                        <small class="form-text text-muted">Block names should be unique within each campus</small>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> After creating a block, you can add parking spots to it and set capacity limits.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_block" id="submit_btn" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i><span id="submit_text">Create Block</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function filterByCampus() {
            const campusId = document.getElementById('campusFilter').value;
            if (campusId == 0) {
                window.location.href = 'blocks.php';
            } else {
                window.location.href = 'blocks.php?campus_id=' + campusId;
            }
        }
        
        function clearFilter() {
            window.location.href = 'blocks.php';
        }
        
        function editBlock(id, name, campusId) {
            document.getElementById('block_id').value = id;
            document.getElementById('modal_name').value = name;
            document.getElementById('modal_campus').value = campusId;
            document.getElementById('modal_title').textContent = 'Edit Block';
            document.getElementById('submit_text').textContent = 'Update Block';
            document.getElementById('submit_btn').name = 'update_block';
        }
        
        // Reset modal when closed
        document.getElementById('blockModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('block_id').value = '0';
            document.getElementById('modal_title').textContent = 'Add New Block';
            document.getElementById('submit_text').textContent = 'Create Block';
            document.getElementById('submit_btn').name = 'create_block';
            document.querySelector('form').reset();
            
            // Set default campus if filtered
            <?php if ($selected_campus > 0): ?>
            document.getElementById('modal_campus').value = '<?= $selected_campus ?>';
            <?php endif; ?>
        });
    </script>
</body>
</html>