<?php
/**
 * LIU Parking System - Capacities Management
 * File: admin/capacities.php
 * Manage parking capacity limits for campuses and blocks
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
    if (isset($_POST['update_capacity'])) {
        $capacity_id = (int)($_POST['capacity_id'] ?? 0);
        $campus_id = (int)($_POST['campus_id'] ?? 0);
        $block_id = (int)($_POST['block_id'] ?? 0);
        $capacity = (int)($_POST['capacity'] ?? 0);

        if ($campus_id <= 0 || $capacity < 0) {
            $error_message = 'Please provide valid campus and capacity values.';
        } else {
            try {
                if ($capacity_id > 0) {
                    // Update existing capacity
                    $stmt = $pdo->prepare("UPDATE capacities SET capacity=? WHERE id=?");
                    $stmt->execute([$capacity, $capacity_id]);
                    $success_message = 'Capacity updated successfully.';
                } else {
                    // Insert new capacity
                    $block_id_val = $block_id > 0 ? $block_id : NULL;
                    
                    // Check if capacity already exists for this campus/block combination
                    $check = $pdo->prepare("SELECT id FROM capacities WHERE campus_id=? AND (block_id=? OR (block_id IS NULL AND ? IS NULL))");
                    $check->execute([$campus_id, $block_id_val, $block_id_val]);
                    
                    if ($check->fetch()) {
                        $error_message = 'Capacity already exists for this campus/block combination.';
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO capacities (campus_id, block_id, capacity) VALUES (?, ?, ?)");
                        $stmt->execute([$campus_id, $block_id_val, $capacity]);
                        $success_message = 'Capacity added successfully.';
                    }
                }
                $selected_campus = $campus_id;
            } catch(PDOException $e) {
                $error_message = 'Database error: '.$e->getMessage();
            }
        }
    }
    
    if (isset($_POST['delete_capacity'])) {
        $capacity_id = (int)($_POST['capacity_id'] ?? 0);
        if ($capacity_id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM capacities WHERE id=?");
                $stmt->execute([$capacity_id]);
                $success_message = 'Capacity deleted successfully.';
            } catch(PDOException $e) {
                $error_message = 'Database error: '.$e->getMessage();
            }
        }
    }
}

// Get campuses for dropdown
$campuses = $pdo->query("SELECT id, name, code FROM campuses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get blocks for selected campus
$blocks = [];
if ($selected_campus > 0) {
    $stmt = $pdo->prepare("SELECT id, name FROM blocks WHERE campus_id=? ORDER BY name");
    $stmt->execute([$selected_campus]);
    $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get all blocks for JavaScript
$all_blocks = $pdo->query("SELECT id, campus_id, name FROM blocks ORDER BY campus_id, name")->fetchAll(PDO::FETCH_ASSOC);

// Get capacity data with current usage
$capacity_query = "
    SELECT 
        cap.id,
        cap.campus_id,
        cap.block_id,
        cap.capacity,
        c.name as campus_name,
        c.code as campus_code,
        b.name as block_name,
        COUNT(DISTINCT ps.id) as total_spots,
        COUNT(DISTINCT CASE WHEN ps.is_occupied = 1 THEN ps.id END) as occupied_spots,
        COUNT(DISTINCT CASE WHEN ps.is_reserved = 1 AND ps.is_occupied = 0 THEN ps.id END) as reserved_spots
    FROM capacities cap
    LEFT JOIN campuses c ON c.id = cap.campus_id
    LEFT JOIN blocks b ON b.id = cap.block_id
    LEFT JOIN parking_spots ps ON (
        ps.campus_id = cap.campus_id AND 
        (cap.block_id IS NULL OR ps.block_id = cap.block_id)
    )
    " . ($selected_campus > 0 ? "WHERE cap.campus_id = ?" : "") . "
    GROUP BY cap.id, cap.campus_id, cap.block_id, cap.capacity, c.name, c.code, b.name
    ORDER BY c.name, b.name
";

if ($selected_campus > 0) {
    $stmt = $pdo->prepare($capacity_query);
    $stmt->execute([$selected_campus]);
} else {
    $stmt = $pdo->query($capacity_query);
}
$capacities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get campus overview data
$campus_overview_query = "
    SELECT 
        c.id,
        c.name,
        c.code,
        COUNT(DISTINCT ps.id) as total_spots,
        COUNT(DISTINCT CASE WHEN ps.is_occupied = 1 THEN ps.id END) as occupied_spots,
        COALESCE(SUM(cap.capacity), 0) as total_capacity,
        COUNT(DISTINCT cap.id) as capacity_entries
    FROM campuses c
    LEFT JOIN parking_spots ps ON ps.campus_id = c.id
    LEFT JOIN capacities cap ON cap.campus_id = c.id
    GROUP BY c.id, c.name, c.code
    ORDER BY c.name
";
$campus_overview = $pdo->query($campus_overview_query)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Capacity Management - LIU Parking System</title>
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

        .progress {
            height: 8px;
            border-radius: 4px;
        }

        .progress-bar {
            border-radius: 4px;
        }

        .campus-filter {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            margin-bottom: 30px;
        }

        .overview-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .overview-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.04);
            border-left: 4px solid var(--primary);
        }

        .overview-card h5 {
            color: var(--primary);
            margin-bottom: 15px;
            font-weight: 600;
        }

        .overview-stat {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .overview-stat .value {
            font-weight: 600;
        }

        .status-excellent { color: #28a745; }
        .status-good { color: #17a2b8; }
        .status-warning { color: #ffc107; }
        .status-danger { color: #dc3545; }

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
            .overview-cards {
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
                <h1><i class="fas fa-chart-bar"></i> Capacity Management</h1>
            </div>
            <div class="header-right">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#capacityModal">
                    <i class="fas fa-plus"></i> Set Capacity
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

            <!-- Campus Overview -->
            <div class="overview-cards">
                <?php foreach($campus_overview as $overview): ?>
                    <div class="overview-card">
                        <h5><?= htmlspecialchars($overview['name']) ?> (<?= htmlspecialchars($overview['code']) ?>)</h5>
                        <div class="overview-stat">
                            <span>Total Spots:</span>
                            <span class="value"><?= number_format($overview['total_spots']) ?></span>
                        </div>
                        <div class="overview-stat">
                            <span>Occupied:</span>
                            <span class="value"><?= number_format($overview['occupied_spots']) ?></span>
                        </div>
                        <div class="overview-stat">
                            <span>Set Capacity:</span>
                            <span class="value"><?= number_format($overview['total_capacity']) ?></span>
                        </div>
                        <div class="overview-stat">
                            <span>Utilization:</span>
                            <span class="value">
                                <?php 
                                if ($overview['total_capacity'] > 0) {
                                    $utilization = ($overview['occupied_spots'] / $overview['total_capacity']) * 100;
                                    $status_class = '';
                                    if ($utilization < 50) $status_class = 'status-excellent';
                                    elseif ($utilization < 70) $status_class = 'status-good';
                                    elseif ($utilization < 90) $status_class = 'status-warning';
                                    else $status_class = 'status-danger';
                                    echo '<span class="' . $status_class . '">' . number_format($utilization, 1) . '%</span>';
                                } else {
                                    echo '<span class="text-muted">No capacity set</span>';
                                }
                                ?>
                            </span>
                        </div>
                        <?php if ($overview['capacity_entries'] == 0): ?>
                            <a href="?campus_id=<?= $overview['id'] ?>" class="btn btn-outline-primary btn-sm mt-2 w-100">
                                <i class="fas fa-plus"></i> Set Capacity
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Capacity Details -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i>
                        <?= $selected_campus > 0 ? 'Campus Capacity Details' : 'All Capacity Settings' ?>
                    </h3>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Campus</th>
                                    <th>Block/Area</th>
                                    <th>Set Capacity</th>
                                    <th>Current Spots</th>
                                    <th>Occupied</th>
                                    <th>Utilization</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(!$capacities): ?>
                                    <tr>
                                        <td colspan="7" class="text-center p-4 text-muted">
                                            <i class="fas fa-info-circle fa-2x mb-3 d-block"></i>
                                            <?= $selected_campus > 0 ? 'No capacity limits set for this campus.' : 'No capacity limits configured yet.' ?>
                                            <br>
                                            <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#capacityModal">
                                                <i class="fas fa-plus"></i> Set First Capacity
                                            </button>
                                        </td>
                                    </tr>
                                <?php else: foreach($capacities as $cap): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($cap['campus_name']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($cap['campus_code']) ?></small>
                                        </td>
                                        <td>
                                            <?php if ($cap['block_name']): ?>
                                                <span class="badge bg-info">Block <?= htmlspecialchars($cap['block_name']) ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Entire Campus</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="fw-semibold"><?= number_format($cap['capacity']) ?></span>
                                        </td>
                                        <td><?= number_format($cap['total_spots']) ?></td>
                                        <td><?= number_format($cap['occupied_spots']) ?></td>
                                        <td>
                                            <?php 
                                            $utilization = $cap['capacity'] > 0 ? ($cap['occupied_spots'] / $cap['capacity']) * 100 : 0;
                                            $progress_class = '';
                                            if ($utilization < 50) $progress_class = 'bg-success';
                                            elseif ($utilization < 70) $progress_class = 'bg-info';
                                            elseif ($utilization < 90) $progress_class = 'bg-warning';
                                            else $progress_class = 'bg-danger';
                                            ?>
                                            <div class="progress" style="width: 100px;">
                                                <div class="progress-bar <?= $progress_class ?>" 
                                                     style="width: <?= min(100, $utilization) ?>%"
                                                     title="<?= number_format($utilization, 1) ?>%">
                                                </div>
                                            </div>
                                            <small class="text-muted"><?= number_format($utilization, 1) ?>%</small>
                                        </td>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-outline-primary me-1" 
                                                    onclick="editCapacity(<?= $cap['id'] ?>, <?= $cap['campus_id'] ?>, <?= $cap['block_id'] ?: 0 ?>, <?= $cap['capacity'] ?>)"
                                                    data-bs-toggle="modal" data-bs-target="#capacityModal">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this capacity limit?')">
                                                <input type="hidden" name="capacity_id" value="<?= $cap['id'] ?>">
                                                <button type="submit" name="delete_capacity" class="btn btn-sm btn-outline-danger">
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

    <!-- Capacity Modal -->
    <div class="modal fade" id="capacityModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" method="post">
                <input type="hidden" name="capacity_id" id="capacity_id" value="0">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-chart-bar me-2"></i>
                        <span id="modal_title">Set Capacity Limit</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Campus *</label>
                        <select class="form-select" name="campus_id" id="modal_campus" required onchange="loadBlocks()">
                            <option value="">Select campus</option>
                            <?php foreach($campuses as $campus): ?>
                                <option value="<?= $campus['id'] ?>" <?= $selected_campus == $campus['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($campus['name']) ?> (<?= htmlspecialchars($campus['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Block/Area</label>
                        <select class="form-select" name="block_id" id="modal_block">
                            <option value="0">Entire Campus</option>
                        </select>
                        <small class="form-text text-muted">Leave as "Entire Campus" to set capacity for the whole campus</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Capacity Limit *</label>
                        <input type="number" name="capacity" id="modal_capacity" class="form-control" required min="0" placeholder="Enter maximum capacity">
                        <small class="form-text text-muted">Maximum number of vehicles that can be accommodated</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_capacity" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i><span id="submit_text">Save Capacity</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // All blocks data for JavaScript
        const allBlocks = <?= json_encode($all_blocks) ?>;
        
        function filterByCampus() {
            const campusId = document.getElementById('campusFilter').value;
            if (campusId == 0) {
                window.location.href = 'capacities.php';
            } else {
                window.location.href = 'capacities.php?campus_id=' + campusId;
            }
        }
        
        function clearFilter() {
            window.location.href = 'capacities.php';
        }
        
        function loadBlocks() {
            const campusId = document.getElementById('modal_campus').value;
            const blockSelect = document.getElementById('modal_block');
            
            // Clear existing options except "Entire Campus"
            blockSelect.innerHTML = '<option value="0">Entire Campus</option>';
            
            if (campusId) {
                // Add blocks for selected campus
                allBlocks.forEach(block => {
                    if (block.campus_id == campusId) {
                        const option = document.createElement('option');
                        option.value = block.id;
                        option.textContent = 'Block ' + block.name;
                        blockSelect.appendChild(option);
                    }
                });
            }
        }
        
        function editCapacity(capacityId, campusId, blockId, capacity) {
            document.getElementById('capacity_id').value = capacityId;
            document.getElementById('modal_campus').value = campusId;
            document.getElementById('modal_capacity').value = capacity;
            document.getElementById('modal_title').textContent = 'Edit Capacity Limit';
            document.getElementById('submit_text').textContent = 'Update Capacity';
            
            loadBlocks();
            setTimeout(() => {
                document.getElementById('modal_block').value = blockId;
            }, 100);
        }
        
        // Reset modal when it's closed
        document.getElementById('capacityModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('capacity_id').value = '0';
            document.getElementById('modal_title').textContent = 'Set Capacity Limit';
            document.getElementById('submit_text').textContent = 'Save Capacity';
            document.querySelector('form').reset();
            
            // Set default campus if filtered
            <?php if ($selected_campus > 0): ?>
            document.getElementById('modal_campus').value = '<?= $selected_campus ?>';
            loadBlocks();
            <?php endif; ?>
        });
        
        // Load blocks on page load if campus is selected
        <?php if ($selected_campus > 0): ?>
        document.addEventListener('DOMContentLoaded', function() {
            loadBlocks();
        });
        <?php endif; ?>
    </script>
</body>
</html>