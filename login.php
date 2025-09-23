<?php
/**
 * LIU Parking System - Enhanced Login Page
 * Staff and Instructor authentication with modern UI
 */

session_start();

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Database configuration
$db_host = 'localhost';
$db_name = 'parking';
$db_user = 'root';
$db_pass = '';

$login_error = '';
$login_success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $login_error = 'Please enter both email and password.';
    } else {
        try {
            $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Check user credentials
            $stmt = $pdo->prepare("
                SELECT id, Email, FIRST, Last, role, password_md5, Campus 
                FROM users 
                WHERE LOWER(Email) = LOWER(?) 
                AND role IN ('staff', 'instructor', 'admin')
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user && md5($password) === $user['password_md5']) {
                // Successful login
                // after fetching the user row from DB:
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['user_role'] = ($user['role'] === 'admin') ? 'admin' : 'user';  

                
                // Redirect based on role
                if ($user['role'] === 'admin') {
                    header('Location: admin/index.php');
                } else {
                    header('Location: dashboard.php');
                }
                exit;
            } else {
                $login_error = 'Invalid email or password. Please try again.';
            }
        } catch (PDOException $e) {
            $login_error = 'Database connection error. Please try again later.';
            error_log("Login error: " . $e->getMessage());
        }
    }
}

// Check for logout message
if (isset($_GET['logout'])) {
    $login_success = 'You have been successfully logged out.';
}

// Check for timeout message
if (isset($_GET['timeout'])) {
    $login_error = 'Your session has expired. Please log in again.';
}

$pageTitle = "Staff Login - LIU Parking System";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="description" content="Staff and Instructor login for LIU Parking Management System">
    <meta name="keywords" content="LIU, parking, login, staff, instructor, Lebanon">
    
    <!-- Favicons -->
    <link href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'></text></svg>" rel="icon">

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com" rel="preconnect">
    <link href="https://fonts.gstatic.com" rel="preconnect" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900;1,100;1,300;1,400;1,500;1,700;1,900&family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&family=Nunito:ital,wght@0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">

    <!-- Vendor CSS Files -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <style>
        /* Global Styles */
        :root {
            --default-font: "Roboto", system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", "Liberation Sans", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
            --heading-font: "Poppins", sans-serif;
            --nav-font: "Nunito", sans-serif;
        }

        /* LIU Parking Color Scheme */
        :root {
            --background-color: #ffffff;
            --default-color: #444444;
            --heading-color: #003366;
            --accent-color: #003366;
            --liu-gold: #FFB81C;
            --liu-blue: #003366;
            --liu-light-blue: #004080;
            --surface-color: #ffffff;
            --contrast-color: #ffffff;
            --error-color: #dc3545;
            --warning-color: #ffc107;
            --success-color: #28a745;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            color: var(--default-color);
            background-image: url('images/background.png');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            background-repeat: no-repeat;
            font-family: var(--default-font);
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
        }

        /* Background overlay */
        body::after {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(0, 51, 102, 0.9) 0%, rgba(0, 64, 128, 0.95) 100%);
            z-index: 1;
            pointer-events: none;
        }

        /* Animated Background */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                radial-gradient(circle at 20% 80%, rgba(255, 184, 28, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255, 255, 255, 0.08) 0%, transparent 50%),
                radial-gradient(circle at 40% 40%, rgba(255, 184, 28, 0.05) 0%, transparent 50%);
            animation: backgroundFloat 20s ease-in-out infinite;
            pointer-events: none;
            z-index: 2;
        }

        @keyframes backgroundFloat {
            0%, 100% { 
                transform: scale(1) rotate(0deg);
                opacity: 1;
            }
            50% { 
                transform: scale(1.1) rotate(5deg);
                opacity: 0.8;
            }
        }

        /* Floating particles effect */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 2;
            pointer-events: none;
        }

        .particle {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 184, 28, 0.6);
            animation: floatUp 15s infinite linear;
        }

        .particle:nth-child(1) { left: 10%; width: 10px; height: 10px; animation-delay: 0s; }
        .particle:nth-child(2) { left: 20%; width: 6px; height: 6px; animation-delay: 2s; }
        .particle:nth-child(3) { left: 30%; width: 8px; height: 8px; animation-delay: 4s; }
        .particle:nth-child(4) { left: 40%; width: 12px; height: 12px; animation-delay: 6s; }
        .particle:nth-child(5) { left: 50%; width: 4px; height: 4px; animation-delay: 8s; }
        .particle:nth-child(6) { left: 60%; width: 14px; height: 14px; animation-delay: 10s; }
        .particle:nth-child(7) { left: 70%; width: 8px; height: 8px; animation-delay: 12s; }
        .particle:nth-child(8) { left: 80%; width: 6px; height: 6px; animation-delay: 14s; }
        .particle:nth-child(9) { left: 90%; width: 10px; height: 10px; animation-delay: 16s; }

        @keyframes floatUp {
            0% {
                transform: translateY(100vh) rotateZ(0deg);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            100% {
                transform: translateY(-100px) rotateZ(360deg);
                opacity: 0;
            }
        }

        /* Main Container */
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            z-index: 3;
        }

   
        @keyframes cardSlideIn {
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--liu-gold), var(--liu-light-blue));
            border-radius: 20px 20px 0 0;
        }

        /* Logo Section */
        .logo-section {
            text-align: center;
            margin-bottom: 35px;
            animation: logoFadeIn 1s ease-out 0.5s both;
        }

        @keyframes logoFadeIn {
            from {
                opacity: 0;
                transform: scale(0.8);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .logo-container {
            width: 100px;
            height: 100px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, var(--liu-blue), var(--liu-light-blue));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 30px rgba(0, 51, 102, 0.4);
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .logo-container:hover {
            transform: scale(1.1) rotate(5deg);
        }

        .logo-container::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255, 184, 28, 0.3), transparent);
            animation: logoShine 3s ease-in-out infinite;
        }

        @keyframes logoShine {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .liu-logo {
            width: 70px;
            height: auto;
            max-height: 70px;
            z-index: 1;
            position: relative;
            transition: all 0.3s ease;
            object-fit: contain;
        }

        .logo-fallback {
            color: white;
            font-size: 36px;
            z-index: 1;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
        }

        .logo-fallback .parking-icon {
            color: white;
            font-size: 36px;
        }

        .login-title {
            color: white;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            font-family: var(--heading-font);
        }

        .login-subtitle {
            color: rgba(255, 255, 255, 0.9);
            font-size: 16px;
            opacity: 0.8;
            margin-bottom: 0;
        }

        /* Form Styling */
        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        .form-group.animated {
            animation: inputSlideIn 0.6s ease-out both;
        }

        .form-group:nth-child(1) { animation-delay: 0.6s; }
        .form-group:nth-child(2) { animation-delay: 0.7s; }

        @keyframes inputSlideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .input-wrapper {
            position: relative;
            overflow: hidden;
            border-radius: 12px;
        }

        .form-control {
            width: 100%;
            padding: 18px 20px 18px 55px;
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            font-size: 16px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            z-index: 1;
        }

        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.7);
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--liu-gold);
            background: rgba(255, 255, 255, 0.15);
            box-shadow: 0 0 0 4px rgba(255, 184, 28, 0.2);
            transform: translateY(-2px);
        }

        .form-control:focus::placeholder {
            opacity: 0;
            transform: translateX(10px);
        }

        .input-icon {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255, 255, 255, 0.7);
            font-size: 18px;
            z-index: 2;
            transition: all 0.3s ease;
        }

        .form-control:focus + .input-icon {
            color: var(--liu-gold);
            transform: translateY(-50%) scale(1.1);
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            border: none;
            animation: alertSlideIn 0.5s ease-out forwards;
        }

        .alert-danger {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
        }

        .alert-success {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
        }

        @keyframes alertSlideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Form Footer */
        .form-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            font-size: 14px;
            animation: footerFadeIn 0.6s ease-out 0.9s both;
        }

        @keyframes footerFadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            color: white;
        }

        .form-check input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--liu-gold);
        }

        .forgot-link {
            color: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
        }

        .forgot-link::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--liu-gold);
            transition: width 0.3s ease;
        }

        .forgot-link:hover::after {
            width: 100%;
        }

        .forgot-link:hover {
            color: var(--liu-gold);
        }

        /* Buttons */
        .btn-primary {
            width: 100%;
            background: linear-gradient(135deg, var(--liu-gold), #e67e22);
            border: none;
            color: white;
            padding: 18px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            font-family: var(--heading-font);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            cursor: pointer;
            margin-bottom: 15px;
            animation: buttonSlideIn 0.6s ease-out 1s both;
        }

        @keyframes buttonSlideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(255, 184, 28, 0.4);
        }

        .btn-primary:hover::before {
            left: 100%;
        }

        .btn-primary:active {
            transform: translateY(-1px);
        }

        .btn-outline-secondary {
            width: 100%;
            border: 2px solid rgba(255, 255, 255, 0.3);
            color: white;
            background: transparent;
            padding: 16px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 500;
            transition: all 0.3s ease;
            animation: buttonSlideIn 0.6s ease-out 1.1s both;
        }

        .btn-outline-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--liu-gold);
            color: var(--liu-gold);
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(255, 184, 28, 0.2);
        }

        /* Loading State */
        .btn-loading {
            pointer-events: none;
            position: relative;
            color: transparent;
        }

        .btn-loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* System Info */
        .system-info {
            text-align: center;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            font-size: 14px;
            color: rgba(255, 255, 255, 0.8);
            animation: infoFadeIn 0.6s ease-out 1.2s both;
        }

        @keyframes infoFadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 0.8;
                transform: translateY(0);
            }
        }

        /* Responsive Design */
        @media (max-width: 576px) {
            .login-card {
                padding: 40px 30px;
                margin: 10px;
                border-radius: 16px;
            }

            .login-title {
                font-size: 24px;
            }

            .form-control {
                padding: 16px 18px 16px 50px;
            }

            .form-footer {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .logo-container {
                width: 80px;
                height: 80px;
            }

            .logo-container .parking-icon {
                font-size: 28px;
            }
        }

        /* Enhanced focus states for accessibility */
        .form-control:focus,
        .btn-primary:focus,
        .btn-outline-secondary:focus,
        .forgot-link:focus {
            outline: 2px solid var(--liu-gold);
            outline-offset: 2px;
        }
    </style>
</head>

<body>
    <!-- Floating Particles -->
    <div class="particles">
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
    </div>

    <div class="login-container">
        <div class="login-card">
            <!-- Logo Section -->
            <div class="logo-section">
                <div class="logo-container">
                    <img src="images/image.png" alt="LIU Logo" class="liu-logo" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="logo-fallback" style="display: none;">
                        <i class="fas fa-car parking-icon"></i>
                    </div>
                </div>
                <h2 class="login-title">Parking Login</h2>
                <p class="login-subtitle">Access the Parking Management System</p>
            </div>

            <!-- Alert Messages -->
            <?php if (!empty($login_error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo htmlspecialchars($login_error); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($login_success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo htmlspecialchars($login_success); ?>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" id="loginForm">
                <div class="form-group animated">
                    <div class="input-wrapper">
                        <input 
                            type="email" 
                            id="email" 
                            name="email"
                            class="form-control" 
                            placeholder="Enter your university email"
                            required
                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                        >
                        <i class="fas fa-envelope input-icon"></i>
                    </div>
                </div>

                <div class="form-group animated">
                    <div class="input-wrapper">
                        <input 
                            type="password" 
                            id="password" 
                            name="password"
                            class="form-control" 
                            placeholder="Enter your password"
                            required
                        >
                        <i class="fas fa-lock input-icon"></i>
                    </div>
                </div>

                <div class="form-footer">
                    <label class="form-check">
                        <input type="checkbox" id="remember" name="remember">
                        <span>Remember me</span>
                    </label>

                    <a href="mailto:parking-support@liu.edu.lb" class="forgot-link">
                        Forgot Password?
                    </a>
                </div>

                <button type="submit" class="btn btn-primary" id="loginBtn">
                    <i class="fas fa-sign-in-alt me-2"></i>Sign In
                </button>

                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="fas fa-home me-2"></i>Back to Home
                </a>
            </form>

            <div class="system-info">
                <p><strong>Lebanese International University</strong></p>
                <p>Parking Management System &copy; 2025</p>
            </div>
        </div>
    </div>

    <!-- Vendor JS Files -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Enhanced form handling with animations
        document.addEventListener('DOMContentLoaded', function() {
            // Debug logo loading
            const logoImg = document.querySelector('.liu-logo');
            const logoFallback = document.querySelector('.logo-fallback');
            
            logoImg.addEventListener('load', function() {
                console.log('Logo loaded successfully');
                this.style.display = 'block';
                logoFallback.style.display = 'none';
            });
            
            logoImg.addEventListener('error', function() {
                console.log('Logo failed to load, using fallback');
                this.style.display = 'none';
                logoFallback.style.display = 'flex';
            });

            // Auto-focus on email field
            setTimeout(() => {
                const emailField = document.getElementById('email');
                if (emailField && !emailField.value) {
                    emailField.focus();
                }
            }, 1000);

            // Add input focus effects
            const inputs = document.querySelectorAll('.form-control');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.style.transform = 'translateY(-2px)';
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.style.transform = 'translateY(0)';
                });
            });

            // Enter key support
            document.getElementById('password').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    document.getElementById('loginForm').submit();
                }
            });

            // Form submission handling
            document.getElementById('loginForm').addEventListener('submit', function(e) {
                const button = document.getElementById('loginBtn');
                
                // Show loading state
                button.disabled = true;
                button.classList.add('btn-loading');
                button.innerHTML = 'Signing in...';
            });

            // Logo hover effect
            const logoContainer = document.querySelector('.logo-container');
            logoContainer.addEventListener('mouseenter', function() {
                this.style.transform = 'scale(1.1) rotate(5deg)';
            });

            logoContainer.addEventListener('mouseleave', function() {
                this.style.transform = 'scale(1) rotate(0deg)';
            });

            // Clear any error states on input
            document.querySelectorAll('input').forEach(input => {
                input.addEventListener('input', function() {
                    this.style.borderColor = 'rgba(255, 255, 255, 0.2)';
                });
            });

            // Email validation styling
            document.getElementById('email').addEventListener('blur', function() {
                const email = this.value.trim();
                if (email && !email.includes('@')) {
                    this.style.borderColor = '#e74c3c';
                } else {
                    this.style.borderColor = 'rgba(255, 255, 255, 0.2)';
                }
            });
        });
    </script>
</body>
</html>