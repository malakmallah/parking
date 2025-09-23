<?php
/**
 * LIU Parking System - Header Include
 * Kelly Template Style Header
 */

// Set default values if not already set
if (!isset($pageTitle)) {
    $pageTitle = "LIU Parking System";
}
if (!isset($additionalCSS)) {
    $additionalCSS = [];
}
if (!isset($additionalJS)) {
    $additionalJS = [];
}

// Helper functions for includes
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
}

if (!function_exists('getUserRole')) {
    function getUserRole() {
        return $_SESSION['user_role'] ?? '';
    }
}

// Check database connection
global $db_connected;
if (!isset($db_connected)) {
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=parking;charset=utf8mb4", 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db_connected = true;
    } catch (PDOException $e) {
        $db_connected = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <title><?php echo htmlspecialchars($pageTitle); ?></title>
  <meta name="description" content="Lebanese International University Smart Parking Management System">
  <meta name="keywords" content="LIU, parking, management, university, QR code, Lebanon">

  <!-- Favicons -->
  <link href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🏫</text></svg>" rel="icon">

  <!-- Fonts -->
  <link href="https://fonts.googleapis.com" rel="preconnect">
  <link href="https://fonts.gstatic.com" rel="preconnect" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900&family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900&display=swap" rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

  <!-- Additional CSS Files -->
  <?php foreach ($additionalCSS as $cssFile): ?>
    <link href="<?php echo htmlspecialchars($cssFile); ?>" rel="stylesheet">
  <?php endforeach; ?>

  <!-- Main CSS -->
  <style>
    :root {
      --default-font: "Roboto", system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      --heading-font: "Poppins", sans-serif;
      --nav-font: "Poppins", sans-serif;
      
      /* LIU Colors */
      --accent-color: #003366;
      --liu-gold: #FFB81C;
      --liu-blue: #003366;
      --liu-light-blue: #004080;
      --contrast-color: #ffffff;
      --nav-color: rgba(255, 255, 255, 0.65);
      --nav-hover-color: #ffffff;
      
      /* Backgrounds */
      --background-color: #ffffff;
      --default-color: #272829;
      --heading-color: #45505b;
      --surface-color: #ffffff;
      --light-background: #f8f9fa;
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      color: var(--default-color);
      background-color: var(--background-color);
      font-family: var(--default-font);
    }

    a {
      color: var(--accent-color);
      text-decoration: none;
      transition: 0.3s;
    }

    a:hover {
      color: color-mix(in srgb, var(--accent-color), transparent 25%);
      text-decoration: none;
    }

    h1, h2, h3, h4, h5, h6 {
      color: var(--heading-color);
      font-family: var(--heading-font);
    }

    /* Header */
    .header {
      color: grey;
      background-color: var(--accent-color);
      padding: 15px 0;
      transition: all 0.5s;
      z-index: 997;
    }

    .header .logo {
      line-height: 1;
      display: flex;
      align-items: center;
    }

    .header .logo img {
      height: 45px;
      width: auto;
      margin-right: 10px;
      transition: all 0.3s ease;
    }

    .header .logo:hover img {
      transform: scale(1.05);
    }

    .header .logo h1 {
      font-size: 22px;
      margin: 0;
      font-weight: 700;
      color: var(--contrast-color);
      white-space: nowrap;
    }

    .navmenu {
      padding: 0;
      z-index: 9997;
    }

    .navmenu ul {
      list-style: none;
      padding: 0;
      margin: 0;
      display: flex;
      align-items: center;
    }

    .navmenu li {
      position: relative;
    }

    .navmenu a, .navmenu a:focus {
      color: var(--nav-color);
      padding: 15px 20px;
      font-size: 16px;
      font-family: var(--nav-font);
      font-weight: 500;
      display: flex;
      align-items: center;
      white-space: nowrap;
      transition: 0.3s;
      position: relative;
    }

    .navmenu a:hover, .navmenu .active, .navmenu .active:focus {
      color: var(--nav-hover-color);
      border-radius: 20px;
    }

    .mobile-nav-toggle {
      color: var(--nav-color);
      font-size: 28px;
      line-height: 0;
      margin-right: 10px;
      cursor: pointer;
      transition: color 0.3s;
    }

    .header-social-links a {
      color: var(--nav-color);
      padding-left: 15px;
      display: inline-block;
      transition: 0.3s;
    }

    .header-social-links a:hover {
      color: var(--nav-hover-color);
    }

    /* Hero Section */
    .hero {
      width: 100%;
      min-height: 100vh;
      position: relative;
      padding: 80px 0;
      display: flex;
      align-items: center;
      background-image: url('images/liu.png');
      background-size: cover;
      background-position: center;
      background-attachment: fixed;
      color: var(--contrast-color);
    }

    .hero::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: linear-gradient(135deg, rgba(0,51,102,0.8) 0%, rgba(0,64,128,0.7) 100%);
      z-index: 1;
    }

    .hero .container {
      position: relative;
      z-index: 3;
    }

    .hero h2 {
      margin: 0;
      font-size: 48px;
      font-weight: 700;
      color: var(--contrast-color);
    }

    .hero p {
      color: rgba(255, 255, 255, 0.8);
      margin: 10px 0 0 0;
      font-size: 20px;
    }

    .hero .btn-get-started {
      color: var(--contrast-color);
      background: var(--liu-gold);
      font-family: var(--heading-font);
      font-weight: 500;
      font-size: 16px;
      letter-spacing: 1px;
      display: inline-block;
      padding: 12px 40px;
      border-radius: 50px;
      transition: 0.5s;
      margin: 10px;
      border: 2px solid var(--liu-gold);
    }

    .hero .btn-get-started:hover {
      color: var(--accent-color);
      background: var(--contrast-color);
      border-color: var(--contrast-color);
    }

    /* Stats Section */
    .stats {
      padding: 0;
      position: relative;
      z-index: 10;
    }

    .stats .container {
      margin-top: -80px;
    }

    .stats-item {
      background-color: var(--surface-color);
      padding: 40px 30px;
      box-shadow: 0px 0 25px rgba(0, 0, 0, 0.08);
      border-radius: 10px;
      text-align: center;
      transition: 0.3s;
    }

    .stats-item:hover {
      transform: translateY(-10px);
    }

    .stats-item i {
      font-size: 48px;
      color: var(--accent-color);
      margin-bottom: 15px;
      display: block;
    }

    .stats-item span {
      font-size: 48px;
      display: block;
      font-weight: 700;
      color: var(--accent-color);
    }

    .stats-item p {
      padding: 0;
      margin: 0;
      font-family: var(--heading-font);
      font-size: 16px;
      font-weight: 600;
      color: rgba(var(--default-color-rgb), 0.6);
    }

    /* About Section */
    .about {
      padding: 120px 0;
    }

    .about .content h2 {
      font-weight: 700;
      font-size: 24px;
      color: var(--accent-color);
    }

    .about .content ul {
      list-style: none;
      padding: 0;
    }

    .about .content ul li {
      margin-bottom: 20px;
      display: flex;
      align-items: center;
    }

    .about .content ul strong {
      margin-right: 10px;
    }

    .about .content ul i {
      font-size: 16px;
      margin-right: 5px;
      color: var(--accent-color);
      line-height: 0;
    }

    /* Services Section */
    .services {
      background-color: var(--light-background);
      padding: 120px 0;
    }

    .services .service-item {
      background-color: var(--surface-color);
      padding: 50px 30px;
      text-align: center;
      border-radius: 10px;
      box-shadow: 0px 0 25px rgba(0, 0, 0, 0.08);
      transition: 0.3s;
      height: 100%;
    }

    .services .service-item:hover {
      transform: translateY(-10px);
    }

    .services .service-item .icon {
      margin: 0 auto 20px auto;
      width: 64px;
      height: 64px;
      background: var(--accent-color);
      border-radius: 50px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 20px;
      transition: 0.3s;
    }

    .services .service-item .icon i {
      color: var(--contrast-color);
      font-size: 28px;
      transition: ease-in-out 0.3s;
    }

    .services .service-item:hover .icon {
      background: var(--liu-gold);
    }

    .services .service-item h3 {
      font-weight: 700;
      margin: 10px 0 15px 0;
      font-size: 22px;
    }

    .services .service-item h3 a {
      color: var(--heading-color);
      transition: ease-in-out 0.3s;
    }

    .services .service-item h3 a:hover {
      color: var(--accent-color);
    }

    /* Performance Banner */
    .performance-banner {
      background: var(--liu-gold);
      padding: 80px 0;
      color: var(--accent-color);
    }

    .performance-banner .performance-item {
      text-align: center;
    }

    .performance-banner .performance-item i {
      font-size: 48px;
      margin-bottom: 15px;
      display: block;
    }

    .performance-banner .performance-item h3 {
      font-size: 36px;
      font-weight: 700;
      margin-bottom: 10px;
      color: var(--accent-color);
    }

    .performance-banner .performance-item p {
      font-size: 16px;
      margin: 0;
      color: var(--accent-color);
    }

    /* Connection Status */
    .connection-status {
      position: fixed;
      top: 10px;
      right: 10px;
      padding: 8px 15px;
      border-radius: 20px;
      font-size: 12px;
      z-index: 1000;
      font-weight: 500;
    }

    .connection-status.connected {
      background: #28a745;
      color: white;
    }

    .connection-status.disconnected {
      background: #dc3545;
      color: white;
    }

    /* Responsive */
    @media (max-width: 768px) {
      .hero h2 {
        font-size: 32px;
      }

      .hero p {
        font-size: 18px;
      }

      .stats .container {
        margin-top: 0;
      }

      .stats-item {
        margin-bottom: 20px;
      }
    }

    /* Preloader */
    #preloader {
      position: fixed;
      inset: 0;
      z-index: 999999;
      overflow: hidden;
      background: var(--background-color);
      transition: all 0.6s ease-out;
    }

    #preloader:before {
      content: "";
      position: fixed;
      top: calc(50% - 30px);
      left: calc(50% - 30px);
      border: 6px solid #f3f3f3;
      border-color: var(--accent-color) transparent var(--accent-color) transparent;
      border-radius: 50%;
      width: 60px;
      height: 60px;
      animation: animate-preloader 1.5s linear infinite;
    }

    @keyframes animate-preloader {
      0% {
        transform: rotate(0deg);
      }
      100% {
        transform: rotate(360deg);
      }
    }
  </style>
</head>

<body class="index-page">



  <header id="header" class="header d-flex align-items-center sticky-top">
    <div class="container-fluid position-relative d-flex align-items-center justify-content-between">

      <a href="index.php" class="logo d-flex align-items-center me-auto me-xl-0">
        <img src="images/image.png" alt="LIU Logo" style="height: 45px; margin-right: 10px;">
        <h1 class="sitename">LIU Parking System</h1>
      </a>

      <nav id="navmenu" class="navmenu">
        <ul>
          <li><a href="index.php" class="active">Home</a></li>
          <li><a href="index.php#about">About</a></li>
          <li><a href="index.php#services">Features</a></li>
          <?php if (isLoggedIn()): ?>
            <li><a href="dashboard.php">Dashboard</a></li>
            <?php if (getUserRole() === 'admin'): ?>
              <li><a href="admin.php">Admin</a></li>
            <?php endif; ?>
          <?php endif; ?>
        </ul>
        <i class="mobile-nav-toggle d-xl-none bi bi-list"></i>
      </nav>

      <div class="header-social-links d-flex align-items-center">
        <?php if (isLoggedIn()): ?>
          <span class="text-white me-3 d-none d-md-inline">
            <i class="fas fa-user-circle me-1"></i>
            <?php echo htmlspecialchars(explode(' ', $_SESSION['user_name'] ?? 'User')[0]); ?>
          </span>
          <a href="scan.php" class="btn-get-started me-2" style="background: var(--liu-gold); color: var(--accent-color); padding: 8px 20px; border-radius: 20px; text-decoration: none; font-weight: 500;">
            <i class="fas fa-qrcode me-1"></i> Scan QR
          </a>
          <a href="logout.php" class="text-white" style="padding: 8px 12px; text-decoration: none;" title="Logout">
            <i class="fas fa-sign-out-alt"></i>
          </a>
        <?php else: ?>
          <a href="scan.php" class="text-white me-3 d-none d-md-inline" style="text-decoration: none;">
            <i class="fas fa-qrcode me-1"></i> Scan QR
          </a>
          <a href="login.php" class="btn-get-started" style="background: var(--liu-gold); color: var(--accent-color); padding: 8px 20px; border-radius: 20px; text-decoration: none; font-weight: 500;">
            <i class="fas fa-sign-in-alt me-1"></i> Login
          </a>
        <?php endif; ?>
      </div>

    </div>
  </header>

  <!-- Preloader -->
  <div id="preloader"></div>

  <main class="main"></main>