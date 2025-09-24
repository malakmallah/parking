<?php
/**
 * LIU Parking System - Admin Dashboard
 * Complete admin panel for parking management
 * Location: admin/index.php
 */

session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Database configuration
$db_host = 'localhost';
$db_name = 'parking';
$db_user = 'root';
$db_pass = '';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get dashboard statistics
$stats = [];

// Total users
$stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role IN ('staff', 'instructor')");
$stats['total_users'] = $stmt->fetch()['count'] ?? 0;

// Total campuses
$stmt = $pdo->query("SELECT COUNT(*) as count FROM campuses");
$stats['total_campuses'] = $stmt->fetch()['count'] ?? 0;

// Total parking spots
$stmt = $pdo->query("SELECT COUNT(*) as count FROM parking_spots");
$stats['total_spots'] = $stmt->fetch()['count'] ?? 0;

// Currently occupied spots
$stmt = $pdo->query("SELECT COUNT(*) as count FROM parking_sessions WHERE exit_at IS NULL");
$stats['occupied_spots'] = $stmt->fetch()['count'] ?? 0;

// Available spots
$stats['available_spots'] = max(0, $stats['total_spots'] - $stats['occupied_spots']);

/* ------------------------------------------------------------------
   Recent parking sessions (last 10) with campus/block/spot fallback
   1) Prefer campus/block from the assigned spot
   2) Else campus from the user's campus
   3) Else campus/block parsed from wall_codes.code (CAMPUS:ID|BLOCK:ID)
-------------------------------------------------------------------*/
$stmt = $pdo->query("
SELECT 
    ps.id,
    u.FIRST,
    u.Last,
    u.Email,
    
    CASE 
        WHEN c_spot.name IS NOT NULL THEN c_spot.name
        WHEN c_user.name IS NOT NULL THEN c_user.name  
        WHEN c_wc.name IS NOT NULL THEN c_wc.name
        ELSE 'Unknown'
    END AS campus_name,
    
    CASE 
        WHEN b_spot.name IS NOT NULL THEN b_spot.name
        WHEN b_wc.name IS NOT NULL THEN b_wc.name
        ELSE NULL
    END AS block_name,
    
    spot.spot_number,
    ps.entrance_at,
    ps.exit_at

FROM parking_sessions ps
JOIN users u ON u.id = ps.user_id
LEFT JOIN parking_spots spot ON spot.id = ps.spot_id
LEFT JOIN blocks b_spot ON b_spot.id = spot.block_id
LEFT JOIN campuses c_spot ON c_spot.id = spot.campus_id
LEFT JOIN campuses c_user ON c_user.id = u.campus_id
LEFT JOIN wall_codes wc ON wc.id = ps.wall_code_id
LEFT JOIN campuses c_wc ON c_wc.id = CAST(
    SUBSTRING_INDEX(SUBSTRING_INDEX(wc.code, 'CAMPUS:', -1), '|', 1) AS UNSIGNED
)
LEFT JOIN blocks b_wc ON b_wc.id = CAST(
    SUBSTRING_INDEX(SUBSTRING_INDEX(wc.code, 'BLOCK:', -1), '|', 1) AS UNSIGNED
)
ORDER BY ps.entrance_at DESC
LIMIT 10
");
$recent_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get campuses for quick stats
$stmt = $pdo->query("
    SELECT 
        c.name,
        c.code,
        COUNT(DISTINCT ps2.id)     as total_spots,
        COUNT(DISTINCT sess.id)    as occupied_spots
    FROM campuses c
    LEFT JOIN parking_spots ps2 ON c.id = ps2.campus_id
    LEFT JOIN parking_sessions sess 
           ON ps2.id = sess.spot_id AND sess.exit_at IS NULL
    GROUP BY c.id, c.name, c.code
");
$campus_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Admin Dashboard - LIU Parking System";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="description" content="LIU Parking System Admin Dashboard">

    <!-- Favicons -->
    <link href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'></text></svg>" rel="icon">

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- CSS Files -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <style>
        :root {
            --primary-color: #003366;
            --secondary-color: #FFB81C;
            --success-color: #28a745;
            --info-color: #17a2b8;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --sidebar-width: 280px;
            --header-height: 70px;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter', sans-serif; background-color:#f5f7fa; color: white; text-align: center; }
        .sidebar{ position:fixed; top:0; left:0; height:100vh; width:var(--sidebar-width); background:linear-gradient(135deg, var(--primary-color) 0%, #004080 100%); z-index:1000; transition:.3s; box-shadow:2px 0 10px rgba(0,0,0,.1); }
        .sidebar-header{ padding:20px; text-align:center; border-bottom:1px solid rgba(255,255,255,.1); margin-bottom:20px;}
        .sidebar-header .logo{ width:50px; height:50px; background:var(--secondary-color); border-radius:12px; display:flex; align-items:center; justify-content:center; margin:0 auto 15px; font-size:24px; color:#fff;}
        .sidebar-header h4{ color:#fff; font-weight:600; font-size:18px; margin-bottom:5px;}
        .sidebar-header p{ color:rgba(255,255,255,.7); font-size:12px; margin:0;}
        .sidebar-menu{ padding:0 15px;}
        .sidebar-menu .menu-item{ margin-bottom:5px;}
        .sidebar-menu .menu-link{ display:flex; align-items:center; padding:12px 15px; color:rgba(255,255,255,.8); text-decoration:none; border-radius:8px; transition:.3s; font-size:14px; font-weight:500;}
        .sidebar-menu .menu-link:hover,.sidebar-menu .menu-link.active{ background:rgba(255,255,255,.1); color:#fff; transform:translateX(5px);}
        .sidebar-menu .menu-link i{ width:20px; margin-right:12px; text-align:center;}
        .main-content{ margin-left:var(--sidebar-width); min-height:100vh;}
        .header{ height:var(--header-height); background:#fff; box-shadow:0 2px 10px rgba(0,0,0,.05); display:flex; align-items:center; justify-content:between; padding:0 30px; position:sticky; top:0; z-index:999;}
        .header-left h1{ font-size:24px; font-weight:600; color:var(--primary-color); margin:0;}
        .header-right{ margin-left:auto; display:flex; align-items:center; gap:20px;}
        .user-info{ display:flex; align-items:center; gap:10px;}
        .content-area{ padding:30px;}
        .stats-row{ margin-bottom:30px;}
        .stat-card{ background:#fff; border-radius:12px; padding:25px; box-shadow:0 2px 10px rgba(0,0,0,.05); border:1px solid #e9ecef; transition:.3s; height:100%;}
        .stat-card:hover{ transform:translateY(-3px); box-shadow:0 8px 25px rgba(0,0,0,.1);}
        .stat-card .stat-icon{ width:60px; height:60px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:24px; color:#fff; margin-bottom:15px;}
        .stat-card.primary .stat-icon{ background:var(--primary-color);}
        .stat-card.success .stat-icon{ background:var(--success-color);}
        .stat-card.warning .stat-icon{ background:var(--warning-color);}
        .stat-card.info .stat-icon{ background:var(--info-color);}
        .stat-card .stat-number{ font-size:32px; font-weight:700; color:var(--primary-color); margin-bottom:5px;}
        .stat-card .stat-label{ font-size:14px; color:#6c757d; font-weight:500;}
        .table-card{ background:#fff; border-radius:12px; padding:25px; box-shadow:0 2px 10px rgba(0,0,0,.05); border:1px solid #e9ecef;}
        .table-card .card-header{ margin-bottom:20px; padding-bottom:15px; border-bottom:1px solid #e9ecef;}
        .table-card .card-title{ font-size:18px; font-weight:600; color:var(--primary-color); margin:0;}
        .table-responsive{ border-radius:8px; overflow:hidden;}
        .table{ margin:0;}
        .table th{ background:#f8f9fa; border:none; font-weight:600; color:var(--primary-color); padding:15px; font-size:13px; text-transform:uppercase; letter-spacing:.5px;}
        .table td{ padding:15px; border-bottom:1px solid #e9ecef; vertical-align:middle;}
        .table tr:last-child td{ border-bottom:none;}
        .status-badge{ padding:4px 12px; border-radius:20px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.5px;}
        .status-badge.active{ background:rgba(40,167,69,.1); color:#28a745;}
        .status-badge.inactive{ background:rgba(220,53,69,.1); color:#dc3545;}
        .campus-stats{ margin-top:30px;}
        .campus-card{ background:#fff; border-radius:12px; padding:20px; box-shadow:0 2px 10px rgba(0,0,0,.05); border:1px solid #e9ecef; margin-bottom:15px;}
        .campus-card .campus-header{ display:flex; align-items:center; justify-content:space-between; margin-bottom:15px;}
        .campus-card .campus-name{ font-size:16px; font-weight:600; color:var(--primary-color);}
        .campus-card .campus-code{ background:var(--secondary-color); color:#fff; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600;}
        .campus-stats-row{ display:flex; gap:20px;}
        .campus-stat{ text-align:center;}
        .campus-stat .number{ font-size:24px; font-weight:700; color:var(--primary-color);}
        .campus-stat .label{ font-size:12px; color:#6c757d; text-transform:uppercase; letter-spacing:.5px;}
        .quick-actions{ display:flex; gap:15px; margin-bottom:30px;}
        .quick-action-btn{ background:#fff; border:2px solid #e9ecef; border-radius:12px; padding:15px 20px; text-decoration:none; color:var(--primary-color); transition:.3s; font-weight:500; display:flex; align-items:center; gap:10px;}
        .quick-action-btn:hover{ border-color:var(--secondary-color); color:var(--secondary-color); transform:translateY(-2px); box-shadow:0 8px 25px rgba(0,0,0,.1);}
        @media (max-width:768px){
            .sidebar{ transform:translateX(-100%);}
            .main-content{ margin-left:0;}
            .content-area{ padding:20px;}
            .header{ padding:0 20px;}
            .quick-actions{ flex-direction:column;}
        }
    </style>
</head>
<body>
  <?php include 'includes/sidebar.php'; ?>

  <div class="main-content">
    <header class="header">
      <div class="header-left">
        <h1>Dashboard Overview</h1>
      </div>
      <div class="header-right">
        <div class="user-info">
          <div class="user-avatar">
            <?php echo strtoupper(substr($_SESSION['user_name'] ?? 'A', 0, 1)); ?>
          </div>
          <div>
            <div style="font-size:14px; font-weight:600; color:var(--primary-color);">
              <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Administrator'); ?>
            </div>
            <div style="font-size:12px; color:#6c757d;">Administrator</div>
          </div>
        </div>
      </div>
    </header>

    <div class="content-area">
      <!-- Quick Actions -->
      <div class="quick-actions">
        <a href="users.php?action=add" class="quick-action-btn">
          <i class="fas fa-user-plus"></i> Add New User
        </a>
        <a href="cards.php?action=generate" class="quick-action-btn">
          <i class="fas fa-id-card"></i> Generate ID Card
        </a>
        <a href="gates.php?action=generate" class="quick-action-btn">
          <i class="fas fa-qrcode"></i> Generate Wall Code
        </a>
        <a href="reports.php" class="quick-action-btn">
          <i class="fas fa-download"></i> Export Report
        </a>
      </div>

      <!-- Stats Cards -->
      <div class="stats-row">
        <div class="row">
          <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
            <div class="stat-card primary">
              <div class="stat-icon"><i class="fas fa-users"></i></div>
              <div class="stat-number"><?php echo number_format($stats['total_users']); ?></div>
              <div class="stat-label">Total Users</div>
            </div>
          </div>
          <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
            <div class="stat-card success">
              <div class="stat-icon"><i class="fas fa-parking"></i></div>
              <div class="stat-number"><?php echo number_format($stats['available_spots']); ?></div>
              <div class="stat-label">Available Spots</div>
            </div>
          </div>
          <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
            <div class="stat-card warning">
              <div class="stat-icon"><i class="fas fa-car"></i></div>
              <div class="stat-number"><?php echo number_format($stats['occupied_spots']); ?></div>
              <div class="stat-label">Occupied Spots</div>
            </div>
          </div>
          <div class="col-xl-3 col-lg-6 col-md-6 mb-4">
            <div class="stat-card info">
              <div class="stat-icon"><i class="fas fa-university"></i></div>
              <div class="stat-number"><?php echo number_format($stats['total_campuses']); ?></div>
              <div class="stat-label">Total Campuses</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent Parking Sessions -->
      <div class="row">
        <div class="col-lg-8">
          <div class="table-card">
            <div class="card-header">
              <h3 class="card-title">Recent Parking Sessions</h3>
            </div>
            <div class="table-responsive">
              <table class="table">
                <thead>
                  <tr>
                    <th>User</th>
                    <th>Campus</th>
                    <th>Block/Spot</th>
                    <th>Entry Time</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($recent_sessions as $session): ?>
                  <tr>
                    <td>
                      <div style="font-weight:600; color:var(--primary-color);">
                        <?php echo htmlspecialchars(($session['FIRST'] ?? '') . ' ' . ($session['Last'] ?? '')); ?>
                      </div>
                      <div style="font-size:12px; color:#6c757d;">
                        <?php echo htmlspecialchars($session['Email'] ?? ''); ?>
                      </div>
                    </td>

                    <!-- Campus (spot -> user -> wall code) -->
                    <td>
                      <?php echo !empty($session['campus_name']) ? htmlspecialchars($session['campus_name']) : '—'; ?>
                    </td>

                    <!-- Block/Spot (block name and/or spot number if available) -->
                    <td>
                      <?php
                        $parts = [];
                        if (!empty($session['block_name']))  { $parts[] = 'Block ' . htmlspecialchars($session['block_name']); }
                        if (!empty($session['spot_number'])) { $parts[] = 'Spot '  . htmlspecialchars($session['spot_number']); }
                        echo $parts ? implode(' — ', $parts) : '—';
                      ?>
                    </td>

                    <td><?php echo $session['entrance_at'] ? date('M j, Y g:i A', strtotime($session['entrance_at'])) : '—'; ?></td>
                    <td>
                      <span class="status-badge <?php echo empty($session['exit_at']) ? 'active' : 'inactive'; ?>">
                        <?php echo empty($session['exit_at']) ? 'Parked' : 'Exited'; ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>

                <?php if (empty($recent_sessions)): ?>
                  <tr>
                    <td colspan="5" class="text-center" style="padding:40px;">
                      <i class="fas fa-info-circle" style="font-size:24px; color:#6c757d; margin-bottom:10px;"></i>
                      <div style="color:#6c757d;">No parking sessions found</div>
                    </td>
                  </tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Campus Statistics -->
        <div class="col-lg-4">
          <div class="campus-stats">
            <h3 style="font-size:18px; font-weight:600; color:var(--primary-color); margin-bottom:20px;">
              Campus Overview
            </h3>

            <?php foreach ($campus_stats as $campus): ?>
            <div class="campus-card">
              <div class="campus-header">
                <div class="campus-name"><?php echo htmlspecialchars($campus['name']); ?></div>
                <div class="campus-code"><?php echo htmlspecialchars($campus['code']); ?></div>
              </div>
              <div class="campus-stats-row">
                <div class="campus-stat">
                  <div class="number"><?php echo number_format($campus['total_spots']); ?></div>
                  <div class="label">Total Spots</div>
                </div>
                <div class="campus-stat">
                  <div class="number"><?php echo number_format($campus['occupied_spots']); ?></div>
                  <div class="label">Occupied</div>
                </div>
                <div class="campus-stat">
                  <div class="number"><?php echo number_format(max(0,$campus['total_spots'] - $campus['occupied_spots'])); ?></div>
                  <div class="label">Available</div>
                </div>
              </div>
            </div>
            <?php endforeach; ?>

            <?php if (empty($campus_stats)): ?>
            <div class="campus-card">
              <div style="text-align:center; color:#6c757d; padding:20px;">
                <i class="fas fa-university" style="font-size:24px; margin-bottom:10px;"></i>
                <div>No campuses configured</div>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Scripts -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Auto-refresh dashboard every 30 seconds
    setTimeout(function(){ location.reload(); }, 30000);

    // Smooth scrolling
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
      anchor.addEventListener('click', function (e) {
        e.preventDefault();
        document.querySelector(this.getAttribute('href')).scrollIntoView({behavior:'smooth'});
      });
    });
  </script>
</body>
</html>
