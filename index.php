<?php
/**
 * LIU Parking System - Complete Index Page
 * Professional parking management system
 */

// Start session
session_start();

// Database configuration
$db_host = 'localhost';
$db_name = 'parking';
$db_user = 'root';
$db_pass = '';

// Simple database connection
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db_connected = true;
} catch (PDOException $e) {
    $db_connected = false;
}

// Helper functions
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function getUserRole() {
    return $_SESSION['user_role'] ?? '';
}

// Get system statistics
$totalCampuses = 9;
$totalSpots = 0;
$activeUsers = 0;
$occupiedSpots = 0;

if ($db_connected) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM campuses");
        if ($stmt) {
            $result = $stmt->fetch();
            $totalCampuses = $result['count'] ?? 9;
        }
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM parking_spots");
        if ($stmt) {
            $result = $stmt->fetch();
            $totalSpots = $result['count'] ?? 0;
        }
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role IN ('staff', 'instructor')");
        if ($stmt) {
            $result = $stmt->fetch();
            $activeUsers = $result['count'] ?? 0;
        }
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM parking_sessions WHERE exit_at IS NULL");
        if ($stmt) {
            $result = $stmt->fetch();
            $occupiedSpots = $result['count'] ?? 0;
        }
    } catch (PDOException $e) {
        // Use default values on error
    }
}

$availableSpots = $totalSpots - $occupiedSpots;

$pageTitle = "LIU Parking Management System";
$additionalCSS = [];
$additionalJS = [];

include 'includes/header.php';
?>

<!-- Hero Section -->
<section id="hero" class="hero section">
  <div class="container text-center" data-aos="zoom-out" data-aos-delay="100">
    <div class="row justify-content-center">
      <div class="col-lg-8">
        <h2>LIU Smart Parking</h2>
        <p>Professional parking management system for all Lebanese International University campuses</p>
        <?php if (!isLoggedIn()): ?>
          <a href="scan.php" class="btn-get-started">Scan QR Code</a>
          <a href="login.php" class="btn-get-started">Staff Login</a>
        <?php else: ?>
          <a href="login.php" class="btn-get-started">Login</a>
          <a href="scan.php" class="btn-get-started">Scan QR Code</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- Stats Section -->
<section id="stats" class="stats section">
  <div class="container" data-aos="fade-up" data-aos-delay="100">
    <div class="row gy-4">
      <div class="col-lg-3 col-md-6 d-flex flex-column align-items-center">
        <div class="stats-item w-100">
          <i class="fas fa-university"></i>
          <span data-purecounter-start="0" data-purecounter-end="<?php echo $totalCampuses; ?>" data-purecounter-duration="1" class="purecounter"><?php echo $totalCampuses; ?></span>
          <p>University Campuses</p>
        </div>
      </div>

      <div class="col-lg-3 col-md-6 d-flex flex-column align-items-center">
        <div class="stats-item w-100">
          <i class="fas fa-car"></i>
          <span data-purecounter-start="0" data-purecounter-end="<?php echo $totalSpots; ?>" data-purecounter-duration="1" class="purecounter"><?php echo $totalSpots; ?></span>
          <p>Total Parking Spots</p>
        </div>
      </div>

      <div class="col-lg-3 col-md-6 d-flex flex-column align-items-center">
        <div class="stats-item w-100">
          <i class="fas fa-users"></i>
          <span data-purecounter-start="0" data-purecounter-end="<?php echo $activeUsers; ?>" data-purecounter-duration="1" class="purecounter"><?php echo $activeUsers; ?></span>
          <p>Active Users</p>
        </div>
      </div>

      <div class="col-lg-3 col-md-6 d-flex flex-column align-items-center">
        <div class="stats-item w-100">
          <i class="fas fa-check-circle"></i>
          <span data-purecounter-start="0" data-purecounter-end="<?php echo $availableSpots; ?>" data-purecounter-duration="1" class="purecounter"><?php echo $availableSpots; ?></span>
          <p>Available Spots</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- About Section -->
<section id="about" class="about section">
  <div class="container" data-aos="fade-up" data-aos-delay="100">
    <div class="row gy-4 justify-content-center">
      <div class="col-lg-4">
        <div class="text-center">
          <i class="fas fa-qrcode" style="font-size: 8rem; color: var(--accent-color); opacity: 0.3;"></i>
        </div>
      </div>
      <div class="col-lg-8 content">
        <h2>Welcome to LIU Smart Parking System</h2>
        <p class="fst-italic py-3">
          Our comprehensive parking management system serves all Lebanese International University campuses with cutting-edge QR code technology, ensuring secure and efficient parking access for faculty and staff members.
        </p>
        <div class="row">
          <div class="col-lg-6">
            <ul>
              <li><i class="fas fa-check-double"></i> <strong>9 Campus Locations:</strong> Comprehensive coverage across all LIU campuses</li>
              <li><i class="fas fa-check-double"></i> <strong>QR Code Access:</strong> Contactless and secure entry system</li>
              <li><i class="fas fa-check-double"></i> <strong>Real-time Tracking:</strong> Live availability and occupancy monitoring</li>
            </ul>
          </div>
          <div class="col-lg-6">
            <ul>
              <li><i class="fas fa-check-double"></i> <strong>Multi-Block Support:</strong> Advanced system for Beirut campus blocks A-G</li>
              <li><i class="fas fa-check-double"></i> <strong>24/7 Operation:</strong> Round-the-clock system availability</li>
              <li><i class="fas fa-check-double"></i> <strong>Mobile Compatible:</strong> Works seamlessly on all devices</li>
            </ul>
          </div>
        </div>
        <p class="py-3">
          The system provides seamless integration across all campuses while maintaining individual campus-specific features and access controls.
        </p>
      </div>
    </div>
  </div>
</section>

<!-- Services Section -->
<section id="services" class="services section">
  <div class="container section-title" data-aos="fade-up">
    <h2>System Features</h2>
    <p>Comprehensive parking management solutions for modern university environments</p>
  </div>

  <div class="container">
    <div class="row gy-4">
      <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="100">
        <div class="service-item position-relative">
          <div class="icon">
            <i class="fas fa-qrcode"></i>
          </div>
          <a href="#" class="stretched-link">
            <h3>QR Code Access</h3>
          </a>
          <p>Quick and secure contactless entry system. Simply scan wall-mounted QR codes for instant parking access without physical cards or tokens.</p>
        </div>
      </div>

      <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="200">
        <div class="service-item position-relative">
          <div class="icon">
            <i class="fas fa-chart-line"></i>
          </div>
          <a href="#" class="stretched-link">
            <h3>Real-time Tracking</h3>
          </a>
          <p>Live parking spot availability across all campuses with instant updates, comprehensive occupancy statistics, and usage analytics.</p>
        </div>
      </div>

      <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="300">
        <div class="service-item position-relative">
          <div class="icon">
            <i class="fas fa-shield-alt"></i>
          </div>
          <a href="#" class="stretched-link">
            <h3>Secure Access Control</h3>
          </a>
          <p>Role-based access control with comprehensive audit logging and security monitoring for all parking activities and user interactions.</p>
        </div>
      </div>

      <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="400">
        <div class="service-item position-relative">
          <div class="icon">
            <i class="fas fa-mobile-alt"></i>
          </div>
          <a href="#" class="stretched-link">
            <h3>Mobile Optimized</h3>
          </a>
          <p>Responsive design that works perfectly on smartphones and tablets for easy QR code scanning and system access from anywhere.</p>
        </div>
      </div>

      <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="500">
        <div class="service-item position-relative">
          <div class="icon">
            <i class="fas fa-history"></i>
          </div>
          <a href="#" class="stretched-link">
            <h3>Activity Tracking</h3>
          </a>
          <p>Complete parking history with entry/exit times, duration tracking, and comprehensive user activity logs for administrative oversight.</p>
        </div>
      </div>

      <div class="col-lg-4 col-md-6" data-aos="fade-up" data-aos-delay="600">
        <div class="service-item position-relative">
          <div class="icon">
            <i class="fas fa-id-card"></i>
          </div>
          <a href="#" class="stretched-link">
            <h3>Digital ID Cards</h3>
          </a>
          <p>Each staff and instructor receives a digital parking ID card with photo, name, and assigned parking spot information for easy identification.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Performance Banner Section -->
<section class="performance-banner section">
  <div class="container" data-aos="fade-up">
    <div class="row gy-4">
      <div class="col-lg-3 col-md-6">
        <div class="performance-item">
          <i class="fas fa-clock"></i>
          <h3>24/7</h3>
          <p>System Availability</p>
        </div>
      </div>

      <div class="col-lg-3 col-md-6">
        <div class="performance-item">
          <i class="fas fa-shield-check"></i>
          <h3>99.9%</h3>
          <p>Security Uptime</p>
        </div>
      </div>

      <div class="col-lg-3 col-md-6">
        <div class="performance-item">
          <i class="fas fa-bolt"></i>
          <h3>&lt;2 sec</h3>
          <p>QR Scan Response</p>
        </div>
      </div>

      <div class="col-lg-3 col-md-6">
        <div class="performance-item">
          <i class="fas fa-check-circle"></i>
          <h3>100%</h3>
          <p>Mobile Compatible</p>
        </div>
      </div>
    </div>
  </div>
</section>

<?php include 'includes/footer.php'; ?>