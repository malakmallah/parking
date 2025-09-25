<?php
/**
 * LIU Parking System - Enhanced Scanner with Gmail Verification & Testing
 * Two-step process: Wall QR + Gmail Verification
 */

session_start();

// Database configuration
$db_host = 'localhost';
$db_name = 'parking';
$db_user = 'root';
$db_pass = '';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection error. Please try again later.");
}

// Initialize test data if requested
if (isset($_GET['setup_test_data']) && $_GET['setup_test_data'] == '1') {
    try {
        // Create test campuses and blocks
        $pdo->exec("INSERT IGNORE INTO campuses (id, name, code) VALUES 
            (1, 'Main Campus', 'MAIN'),
            (2, 'Beirut Campus', 'BEI'),
            (3, 'Saida Campus', 'SAI')");
        
        $pdo->exec("INSERT IGNORE INTO blocks (id, campus_id, name) VALUES 
            (1, 2, 'Block A'),
            (2, 2, 'Block B'),
            (3, 2, 'Block G')");
        
        // Create test wall codes
        $pdo->exec("INSERT IGNORE INTO wall_codes (code, description) VALUES 
            ('CAMPUS:1', 'Main Campus Gate'),
            ('CAMPUS:2', 'Beirut Campus General Gate'),
            ('CAMPUS:2|BLOCK:3', 'Beirut Block G - Entry/Exit Gate'),
            ('CAMPUS:3', 'Saida Campus Gate')");
        
        // Create test users with proper campus assignments
        $pdo->exec("INSERT IGNORE INTO users (FIRST, Last, Email, parking_number, campus_id, block_id, role) VALUES 
            ('John', 'Doe', 'john.doe@liu.edu.lb', 'MAIN-001', 1, NULL, 'staff'),
            ('Jane', 'Smith', 'jane.smith@liu.edu.lb', 'BEI-002', 2, NULL, 'instructor'),
            ('Ahmad', 'Hassan', 'ahmad.hassan@liu.edu.lb', 'BEI-G003', 2, 3, 'staff'),
            ('Abaas', 'Makki', 'abaas.makki@liu.edu.lb', 'SAI-001281', 3, NULL, 'instructor'),
            ('Abbas', 'Bassam', 'abbas.bassam@liu.edu.lb', 'SAI-002', 3, NULL, 'staff')");
        
        // Create test parking spots
        $pdo->exec("INSERT IGNORE INTO parking_spots (spot_number, campus_id, block_id, is_occupied, is_reserved) VALUES 
            ('A-001', 1, NULL, 0, 0),
            ('A-002', 1, NULL, 0, 0),
            ('B-001', 2, NULL, 0, 0),
            ('G-001', 2, 3, 0, 0),
            ('G-002', 2, 3, 0, 0),
            ('S-001', 3, NULL, 0, 0)");
        
        $test_setup_message = "Test data created successfully!";
    } catch (Exception $e) {
        $test_setup_message = "Error creating test data: " . $e->getMessage();
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'scan_wall_code') {
        $wall_code = trim($_POST['wall_code'] ?? '');
        
        // Validate wall code exists
        $stmt = $pdo->prepare("SELECT * FROM wall_codes WHERE code = ?");
        $stmt->execute([$wall_code]);
        $wall_data = $stmt->fetch();
        
        if ($wall_data) {
            $_SESSION['selected_wall_code'] = $wall_data;
            echo json_encode([
                'status' => 'success',
                'message' => 'Wall code scanned successfully',
                'wall_description' => $wall_data['description'],
                'next_step' => 'gmail_verify'
            ]);
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid wall code. Please try again.'
            ]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'verify_gmail') {
        $entered_email = trim(strtolower($_POST['gmail'] ?? ''));
        
        if (!isset($_SESSION['selected_wall_code'])) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Session expired. Please start again.'
            ]);
            exit;
        }
        
        if (!$entered_email) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Please enter your Gmail address.'
            ]);
            exit;
        }
        
        $wall_code = $_SESSION['selected_wall_code'];
        
        try {
            // Find user by email address
            $stmt = $pdo->prepare("
                SELECT u.*, c.name as campus_name 
                FROM users u 
                LEFT JOIN campuses c ON u.campus_id = c.id
                WHERE LOWER(TRIM(u.Email)) = ? AND u.Email IS NOT NULL AND u.Email != ''
            ");
            $stmt->execute([$entered_email]);
            $user = $stmt->fetch();
            
            if (!$user) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'User not found with email: ' . $entered_email
                ]);
                exit;
            }

            // Validate campus/location access permissions
            $wall_code_payload = $wall_code['code'];
            $allowed_access = false;
            $access_error_message = '';

            // Parse wall code to get campus and block information
            if (preg_match('/CAMPUS:(\d+)/', $wall_code_payload, $campus_match)) {
                $gate_campus_id = (int)$campus_match[1];
                $gate_block_id = null;
                
                if (preg_match('/BLOCK:(\d+)/', $wall_code_payload, $block_match)) {
                    $gate_block_id = (int)$block_match[1];
                }

                // Check if user's campus matches the gate campus
                if ($user['campus_id'] == $gate_campus_id) {
                    // If gate has specific block, check if user belongs to that block or has general campus access
                    if ($gate_block_id !== null) {
                        if ($user['block_id'] == $gate_block_id || $user['block_id'] === null) {
                            $allowed_access = true;
                        } else {
                            // Get block name for error message
                            $block_stmt = $pdo->prepare("SELECT name FROM blocks WHERE id = ?");
                            $block_stmt->execute([$gate_block_id]);
                            $block_info = $block_stmt->fetch();
                            $access_error_message = "Access denied. This gate is for " . ($block_info['name'] ?? 'Block ' . $gate_block_id) . " only. Your parking assignment is for a different area.";
                        }
                    } else {
                        $allowed_access = true; // Campus-wide gate, user belongs to campus
                    }
                } else {
                    // User's campus doesn't match gate campus
                    $gate_campus_stmt = $pdo->prepare("SELECT name FROM campuses WHERE id = ?");
                    $gate_campus_stmt->execute([$gate_campus_id]);
                    $gate_campus_info = $gate_campus_stmt->fetch();
                    
                    $access_error_message = "Access denied. This is a " . ($gate_campus_info['name'] ?? 'Campus ' . $gate_campus_id) . " gate. Your parking assignment (ID: " . $user['parking_number'] . ") is for " . $user['campus_name'] . ". Please use the correct campus gate.";
                }
            }

            if (!$allowed_access) {
                echo json_encode([
                    'status' => 'error',
                    'message' => $access_error_message ?: 'Access denied. You do not have permission to use this gate.'
                ]);
                exit;
            }
            
            // Check if user has active parking session
            $stmt = $pdo->prepare("
                SELECT ps.*, s.spot_number, c.name as campus_name, b.name as block_name,
                       wc.description as entry_gate
                FROM parking_sessions ps
                JOIN parking_spots s ON ps.spot_id = s.id
                LEFT JOIN campuses c ON s.campus_id = c.id
                LEFT JOIN blocks b ON s.block_id = b.id
                LEFT JOIN wall_codes wc ON ps.wall_code_id = wc.id
                WHERE ps.user_id = ? AND ps.exit_at IS NULL
                ORDER BY ps.entrance_at DESC
                LIMIT 1
            ");
            $stmt->execute([$user['id']]);
            $active_session = $stmt->fetch();
            
            if ($active_session) {
                // EXIT PROCESS
                $stmt = $pdo->prepare("
                    UPDATE parking_sessions 
                    SET exit_at = NOW(), gate_out_id = ? 
                    WHERE id = ?
                ");
                $stmt->execute([null, $active_session['id']]);
                
                // Calculate duration
                $entry_time = new DateTime($active_session['entrance_at']);
                $exit_time = new DateTime();
                $duration = $exit_time->diff($entry_time);
                $duration_text = $duration->format('%h hours %i minutes');
                
                echo json_encode([
                    'status' => 'success',
                    'type' => 'EXIT',
                    'message' => 'Exit successful! Have a safe trip.',
                    'user_name' => $user['FIRST'] . ' ' . $user['Last'],
                    'email' => $user['Email'],
                    'parking_number' => $user['parking_number'],
                    'spot_number' => $active_session['spot_number'],
                    'campus' => $active_session['campus_name'],
                    'block' => $active_session['block_name'],
                    'entry_gate' => $active_session['entry_gate'],
                    'exit_gate' => $wall_code['description'],
                    'entry_time' => date('M j, Y g:i A', strtotime($active_session['entrance_at'])),
                    'exit_time' => date('M j, Y g:i A'),
                    'duration' => $duration_text,
                    'photo_url' => $user['photo_url'] ?? null
                ]);
            } else {
                // ENTRY PROCESS - Find available spot
                $stmt = $pdo->prepare("
                    SELECT * FROM parking_spots 
                    WHERE is_occupied = 0 AND is_reserved = 0
                    ORDER BY id 
                    LIMIT 1
                ");
                $stmt->execute();
                $available_spot = $stmt->fetch();
                
                if (!$available_spot) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'No available parking spots at this time.'
                    ]);
                    exit;
                }
                
                // Create parking session
                $stmt = $pdo->prepare("
                    INSERT INTO parking_sessions (
                        user_id, spot_id, entrance_at, gate_in_id, 
                        parking_number, wall_code_id
                    ) VALUES (?, ?, NOW(), ?, ?, ?)
                ");
                $stmt->execute([
                    $user['id'], 
                    $available_spot['id'], 
                    null,
                    $user['parking_number'],
                    $wall_code['id']
                ]);
                
                // Get spot details
                $stmt = $pdo->prepare("
                    SELECT ps.spot_number, c.name as campus_name, b.name as block_name
                    FROM parking_spots ps
                    LEFT JOIN campuses c ON ps.campus_id = c.id
                    LEFT JOIN blocks b ON ps.block_id = b.id
                    WHERE ps.id = ?
                ");
                $stmt->execute([$available_spot['id']]);
                $spot_details = $stmt->fetch();
                
                echo json_encode([
                    'status' => 'success',
                    'type' => 'ENTRY',
                    'message' => 'Welcome! Parking assigned successfully.',
                    'user_name' => $user['FIRST'] . ' ' . $user['Last'],
                    'email' => $user['Email'],
                    'parking_number' => $user['parking_number'],
                    'spot_number' => $spot_details['spot_number'],
                    'campus' => $spot_details['campus_name'],
                    'block' => $spot_details['block_name'],
                    'entry_gate' => $wall_code['description'],
                    'entry_time' => date('M j, Y g:i A'),
                    'photo_url' => $user['photo_url'] ?? null
                ]);
            }
            
        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'System error: ' . $e->getMessage()
            ]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'reset_scanner') {
        unset($_SESSION['selected_wall_code']);
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    if ($_POST['action'] === 'get_test_data') {
        try {
            $wall_codes = $pdo->query("SELECT * FROM wall_codes ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
            $users = $pdo->query("SELECT FIRST, Last, Email, parking_number, campus_id FROM users WHERE Email IS NOT NULL ORDER BY FIRST")->fetchAll(PDO::FETCH_ASSOC);
            $active_sessions = $pdo->query("
                SELECT u.FIRST, u.Last, u.Email, ps.entrance_at, s.spot_number 
                FROM parking_sessions ps 
                JOIN users u ON ps.user_id = u.id 
                JOIN parking_spots s ON ps.spot_id = s.id 
                WHERE ps.exit_at IS NULL
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'status' => 'success',
                'wall_codes' => $wall_codes,
                'users' => $users,
                'active_sessions' => $active_sessions
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }
}

$pageTitle = "Parking Scanner with Gmail Verification - LIU System";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

    <style>
        :root {
            --primary: #003366;
            --secondary: #FFB81C;
            --success: #10b981;
            --danger: #ef4444;
        }

        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, var(--primary) 0%, #004080 100%);
            min-height: 100vh;
            color: white;
        }

        .scanner-container {
            max-width: 700px;
            margin: 0 auto;
            padding: 20px;
        }

        .scanner-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }

        .scanner-header {
            background: linear-gradient(135deg, var(--primary), #3b82f6);
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .scanner-body {
            padding: 2rem;
            color: #333;
        }

        .camera-container {
            position: relative;
            width: 100%;
            height: 300px;
            background: #000;
            border-radius: 15px;
            overflow: hidden;
            margin-bottom: 1rem;
        }

        #camera-feed {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .scanner-overlay {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 200px;
            height: 200px;
            border: 3px solid var(--success);
            border-radius: 15px;
            background: rgba(16, 185, 129, 0.1);
        }

        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .step {
            display: flex;
            align-items: center;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-weight: 500;
            transition: all 0.3s;
            font-size: 0.9rem;
        }

        .step.active {
            background: var(--secondary);
            color: white;
        }

        .step.completed {
            background: var(--success);
            color: white;
        }

        .step.inactive {
            background: #e5e7eb;
            color: #6b7280;
        }

        .gmail-input-container {
            display: none;
            margin-bottom: 1rem;
        }

        .gmail-input {
            width: 100%;
            padding: 1rem;
            font-size: 1.1rem;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            margin-bottom: 1rem;
        }

        .gmail-input:focus {
            outline: none;
            border-color: var(--success);
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }

        .scan-btn, .verify-btn {
            width: 100%;
            padding: 1rem;
            font-size: 1.1rem;
            font-weight: 600;
            border: none;
            border-radius: 10px;
            margin-bottom: 1rem;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--success), #059669);
        }

        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--secondary), #f59e0b);
            color: white;
        }

        .result-display {
            margin-top: 2rem;
            padding: 1.5rem;
            border-radius: 15px;
            text-align: center;
            display: none;
        }

        .result-display.success {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            border: 2px solid var(--success);
            color: #065f46;
        }

        .result-display.error {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            border: 2px solid var(--danger);
            color: #991b1b;
        }

        .user-info {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 1rem;
            margin-top: 1rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 10px;
        }

        .user-photo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .spinner {
            width: 60px;
            height: 60px;
            border: 6px solid rgba(255, 255, 255, 0.3);
            border-top: 6px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .home-link {
            position: fixed;
            top: 1rem;
            left: 1rem;
            background: rgba(255, 255, 255, 0.9);
            color: var(--primary);
            padding: 0.5rem 1rem;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 500;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            z-index: 100;
        }

        .security-notice {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid var(--secondary);
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            text-align: center;
        }

        /* Testing Panel Styles */
        .testing-panel {
            background: rgba(52, 152, 219, 0.1);
            border: 2px solid #3498db;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .test-section {
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 10px;
        }

        .test-data-display {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
            max-height: 200px;
            overflow-y: auto;
        }

        .quick-test-btn {
            margin: 0.25rem;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        @media (max-width: 768px) {
            .scanner-container {
                padding: 1rem;
            }
            .camera-container {
                height: 250px;
            }
            .scanner-overlay {
                width: 150px;
                height: 150px;
            }
            .step {
                font-size: 0.8rem;
                padding: 0.4rem 0.8rem;
            }
            .testing-panel {
                padding: 1rem;
            }
        }
    </style>
</head>

<body>
    <a href="admin/" class="home-link">
        <i class="fas fa-home me-2"></i>Admin
    </a>

    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>

    <div class="scanner-container">
        <div class="scanner-card">
            <div class="scanner-header">
                <h1><i class="fas fa-qrcode me-2"></i>Secure Parking Scanner</h1>
                <p>Two-step verification process for entry/exit</p>
            </div>

            <div class="scanner-body">
                <?php if (isset($test_setup_message)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i><?php echo htmlspecialchars($test_setup_message); ?>
                    </div>
                <?php endif; ?>

                <!-- Testing Panel -->
                <div class="testing-panel" id="testingPanel">
                    <h5><i class="fas fa-flask me-2" style="color: #3498db;"></i>Testing Panel</h5>
                    
                    <!-- Test Data Setup -->
                    <div class="test-section">
                        <h6><i class="fas fa-database me-2"></i>Database Setup</h6>
                        <p class="small mb-2">Initialize test data for development:</p>
                        <a href="?setup_test_data=1" class="btn btn-sm btn-info">
                            <i class="fas fa-plus me-1"></i>Create Test Data
                        </a>
                        <button class="btn btn-sm btn-secondary" onclick="loadTestData()">
                            <i class="fas fa-refresh me-1"></i>Load Current Data
                        </button>
                    </div>

                    <!-- Manual Testing -->
                    <div class="test-section">
                        <h6><i class="fas fa-keyboard me-2"></i>Manual Testing</h6>
                        
                        <!-- Wall Code Test -->
                        <div id="wallCodeTest">
                            <label class="form-label">Test Wall Code:</label>
                            <div class="input-group mb-2">
                                <input type="text" id="wallCodeInput" class="form-control" placeholder="e.g., CAMPUS:2|BLOCK:3">
                                <button class="btn btn-success" onclick="testWallCode()">
                                    <i class="fas fa-qr-code me-1"></i>Test
                                </button>
                            </div>
                            <div class="mb-2">
                                <small class="text-muted">Quick test buttons:</small><br>
                                <button class="btn btn-outline-primary btn-sm quick-test-btn" onclick="quickTest('CAMPUS:1')">Main Campus</button>
                                <button class="btn btn-outline-primary btn-sm quick-test-btn" onclick="quickTest('CAMPUS:2')">Beirut Campus</button>
                                <button class="btn btn-outline-primary btn-sm quick-test-btn" onclick="quickTest('CAMPUS:2|BLOCK:3')">Beirut Block G</button>
                                <button class="btn btn-outline-primary btn-sm quick-test-btn" onclick="quickTest('CAMPUS:3')">Saida Campus</button>
                            </div>
                        </div>

                        <!-- Gmail Test -->
                        <div id="gmailTestSection" style="display: none;">
                            <label class="form-label">Test Gmail Address:</label>
                            <div class="input-group mb-2">
                                <input type="email" id="testGmailInput" class="form-control" placeholder="user@liu.edu.lb">
                                <button class="btn btn-warning" onclick="testGmail()">
                                    <i class="fas fa-envelope me-1"></i>Test
                                </button>
                            </div>
                            <div class="mb-2">
                                <small class="text-muted">Quick test emails:</small><br>
                                <button class="btn btn-outline-success btn-sm quick-test-btn" onclick="quickEmailTest('ahmad.hassan@liu.edu.lb')" title="Beirut Block G user">ahmad.hassan@liu.edu.lb ✓</button>
                                <button class="btn btn-outline-danger btn-sm quick-test-btn" onclick="quickEmailTest('abaas.makki@liu.edu.lb')" title="Saida user - should be denied">abaas.makki@liu.edu.lb ✗</button>
                                <button class="btn btn-outline-success btn-sm quick-test-btn" onclick="quickEmailTest('jane.smith@liu.edu.lb')" title="General Beirut user">jane.smith@liu.edu.lb ✓</button>
                            </div>
                        </div>
                    </div>

                    <!-- Test Data Display -->
                    <div class="test-section">
                        <h6><i class="fas fa-list me-2"></i>Current Test Data</h6>
                        <div id="testDataDisplay" class="test-data-display">
                            <p class="text-muted">Click "Load Current Data" to see available test data</p>
                        </div>
                    </div>

                    <div class="text-center">
                        <button class="btn btn-outline-secondary btn-sm" onclick="toggleTestingPanel()">
                            <i class="fas fa-eye-slash me-1"></i>Hide Testing Panel
                        </button>
                    </div>
                </div>

                <!-- Show Testing Panel Button (hidden initially) -->
                <div class="text-center mb-3" id="showTestingBtn" style="display: none;">
                    <button class="btn btn-outline-info btn-sm" onclick="toggleTestingPanel()">
                        <i class="fas fa-flask me-1"></i>Show Testing Panel
                    </button>
                </div>

                <!-- Step Indicator -->
                <div class="step-indicator">
                    <div class="step active" id="step1">
                        <i class="fas fa-door-open me-2"></i>1. Wall Code
                    </div>
                    <div class="step inactive" id="step2">
                        <i class="fas fa-envelope me-2"></i>2. Gmail Verify
                    </div>
                </div>

                <!-- Current Wall Code Display -->
                <div id="wallCodeDisplay" style="display: none;" class="alert alert-info text-center mb-3">
                    <strong>Current Location:</strong> <span id="currentWallCode"></span>
                    <button class="btn btn-sm btn-outline-secondary ms-2" onclick="resetScanner()">
                        <i class="fas fa-refresh me-1"></i>Change
                    </button>
                </div>

                <!-- Security Notice -->
                <div class="security-notice" id="securityNotice">
                    <i class="fas fa-shield-alt me-2"></i>
                    <strong>Enhanced Security:</strong> Gmail verification required to prevent unauthorized access
                </div>

                <!-- Instructions -->
                <div class="alert alert-warning" id="instructions">
                    <i class="fas fa-info-circle me-2"></i>
                    <span id="instructionText">First, scan the <strong>Wall QR Code</strong> to select location</span>
                </div>

                <!-- Camera Container -->
                <div class="camera-container" id="cameraContainer">
                    <video id="camera-feed" autoplay playsinline muted></video>
                    <div class="scanner-overlay"></div>
                </div>

                <!-- Gmail Input Container -->
                <div class="gmail-input-container" id="gmailContainer">
                    <div class="alert alert-info">
                        <i class="fas fa-user-check me-2"></i>
                        <strong>Location Selected:</strong> <span id="selectedLocation"></span><br>
                        <small>Please enter your Gmail address to verify your identity</small>
                    </div>
                    <label for="gmailInput" class="form-label">
                        <i class="fas fa-envelope me-2"></i>Enter your Gmail address:
                    </label>
                    <input type="email" 
                           id="gmailInput" 
                           class="gmail-input" 
                           placeholder="your.email@liu.edu.lb"
                           autocomplete="email">
                    <button class="verify-btn btn btn-warning" onclick="verifyGmail()">
                        <i class="fas fa-check-circle me-2"></i>Verify Access
                    </button>
                </div>

                <!-- Scan Button -->
                <button class="scan-btn btn btn-primary" id="scanBtn" onclick="startCamera()">
                    <i class="fas fa-camera me-2"></i>Start Camera
                </button>

                <!-- Result Display -->
                <div class="result-display" id="resultDisplay">
                    <div class="result-icon" id="resultIcon" style="font-size: 3rem; margin-bottom: 1rem;"></div>
                    <h3 id="resultTitle"></h3>
                    <p id="resultMessage"></p>
                    <div class="user-info" id="userInfo" style="display: none;">
                        <img id="userPhoto" class="user-photo" src="" alt="User Photo" 
                             onerror="this.src='data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'><circle cx=\'50\' cy=\'50\' r=\'50\' fill=\'%23e5e7eb\'/><text x=\'50\' y=\'55\' text-anchor=\'middle\' font-size=\'35\' fill=\'%236b7280\'>👤</text></svg>'">
                        <div class="user-details">
                            <h4 id="userName"></h4>
                            <p><strong>Email:</strong> <span id="userEmail"></span></p>
                            <p><strong>Parking Number:</strong> <span id="parkingNumber"></span></p>
                            <p><strong>Spot:</strong> <span id="spotInfo"></span></p>
                            <p><strong>Campus:</strong> <span id="campusInfo"></span></p>
                            <p><strong>Time:</strong> <span id="timeInfo"></span></p>
                            <div id="durationInfo" style="display: none;">
                                <p><strong>Duration:</strong> <span id="duration"></span></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>

    <script>
        let video, canvas, context, scanning = false, stream = null;
        let currentStep = 'wall_code'; // Only 2 values: 'wall_code' | 'gmail_verify'
        let wallCodeSelected = false;

        document.addEventListener('DOMContentLoaded', function() {
            canvas = document.createElement('canvas');
            context = canvas.getContext('2d');
            
            // Enter key handlers
            document.getElementById('gmailInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    verifyGmail();
                }
            });

            document.getElementById('testGmailInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    testGmail();
                }
            });

            document.getElementById('wallCodeInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    testWallCode();
                }
            });

            // Load test data on page load
            loadTestData();
        });

        async function startCamera() {
            const btn = document.getElementById('scanBtn');
            
            try {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Starting Camera...';

                video = document.getElementById('camera-feed');
                
                stream = await navigator.mediaDevices.getUserMedia({
                    video: { 
                        facingMode: 'environment',
                        width: { ideal: 640 },
                        height: { ideal: 480 }
                    }
                });

                video.srcObject = stream;
                video.play();

                scanning = true;
                btn.innerHTML = '<i class="fas fa-stop me-2"></i>Stop Scanning';
                btn.onclick = stopCamera;
                btn.disabled = false;

                requestAnimationFrame(scanLoop);

            } catch (error) {
                console.error('Camera error:', error);
                showError('Camera access denied. Please use the manual testing options or check camera permissions.');
                btn.innerHTML = '<i class="fas fa-camera me-2"></i>Start Camera';
                btn.disabled = false;
            }
        }

        function stopCamera() {
            scanning = false;
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
            
            const btn = document.getElementById('scanBtn');
            btn.innerHTML = '<i class="fas fa-camera me-2"></i>Start Camera';
            btn.onclick = startCamera;
        }

        function scanLoop() {
            if (!scanning || !video || video.readyState !== video.HAVE_ENOUGH_DATA) {
                if (scanning) requestAnimationFrame(scanLoop);
                return;
            }

            canvas.height = video.videoHeight;
            canvas.width = video.videoWidth;
            context.drawImage(video, 0, 0, canvas.width, canvas.height);

            const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
            const code = jsQR(imageData.data, imageData.width, imageData.height);

            if (code) {
                processScannedCode(code.data);
                return;
            }

            requestAnimationFrame(scanLoop);
        }

        function processScannedCode(scannedData) {
            stopCamera();
            showLoading(true);

            if (currentStep === 'wall_code') {
                // Process wall code
                fetch(window.location.pathname, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'scan_wall_code',
                        wall_code: scannedData
                    })
                })
                .then(response => response.json())
                .then(data => {
                    showLoading(false);
                    if (data.status === 'success') {
                        wallCodeSelected = true;
                        currentStep = 'gmail_verify';
                        updateUI();
                        document.getElementById('currentWallCode').textContent = data.wall_description;
                        document.getElementById('selectedLocation').textContent = data.wall_description;
                        document.getElementById('wallCodeDisplay').style.display = 'block';
                        document.getElementById('gmailContainer').style.display = 'block';
                        document.getElementById('cameraContainer').style.display = 'none';
                        document.getElementById('scanBtn').style.display = 'none';
                        document.getElementById('gmailInput').focus();
                        
                        // Show Gmail test section
                        document.getElementById('gmailTestSection').style.display = 'block';
                        document.getElementById('wallCodeTest').style.display = 'none';
                    } else {
                        showError(data.message);
                    }
                })
                .catch(error => {
                    showLoading(false);
                    showError('Network error. Please try again.');
                });
            }
        }

        function verifyGmail() {
            const gmail = document.getElementById('gmailInput').value.trim();
            
            if (!gmail) {
                showError('Please enter your Gmail address.');
                return;
            }
            
            if (!gmail.includes('@')) {
                showError('Please enter a valid email address.');
                return;
            }
            
            showLoading(true);
            
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'verify_gmail',
                    gmail: gmail
                })
            })
            .then(response => response.json())
            .then(data => {
                showLoading(false);
                displayResult(data);
            })
            .catch(error => {
                showLoading(false);
                showError('Network error. Please try again.');
            });
        }

        function updateUI() {
            const step1 = document.getElementById('step1');
            const step2 = document.getElementById('step2');
            const instructions = document.getElementById('instructionText');

            if (currentStep === 'wall_code') {
                step1.className = 'step active';
                step2.className = 'step inactive';
                instructions.innerHTML = 'First, scan the <strong>Wall QR Code</strong> to select location';
            } else if (currentStep === 'gmail_verify') {
                step1.className = 'step completed';
                step2.className = 'step active';
                instructions.innerHTML = 'Enter your <strong>Gmail address</strong> to verify your identity';
            }
        }

        function resetScanner() {
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'reset_scanner' })
            });
            
            wallCodeSelected = false;
            currentStep = 'wall_code';
            updateUI();
            document.getElementById('wallCodeDisplay').style.display = 'none';
            document.getElementById('gmailContainer').style.display = 'none';
            document.getElementById('cameraContainer').style.display = 'block';
            document.getElementById('scanBtn').style.display = 'block';
            document.getElementById('resultDisplay').style.display = 'none';
            document.getElementById('gmailInput').value = '';
            document.getElementById('testGmailInput').value = '';
            
            // Reset testing sections
            document.getElementById('wallCodeTest').style.display = 'block';
            document.getElementById('gmailTestSection').style.display = 'none';
        }

        function displayResult(data) {
            const resultDisplay = document.getElementById('resultDisplay');
            const resultIcon = document.getElementById('resultIcon');
            const resultTitle = document.getElementById('resultTitle');
            const resultMessage = document.getElementById('resultMessage');
            const userInfo = document.getElementById('userInfo');

            resultDisplay.className = 'result-display';
            userInfo.style.display = 'none';

            if (data.status === 'success') {
                resultDisplay.classList.add('success');
                
                if (data.type === 'ENTRY') {
                    resultIcon.innerHTML = '<i class="fas fa-check-circle" style="color: #10b981;"></i>';
                    resultTitle.textContent = 'Entry Successful';
                } else {
                    resultIcon.innerHTML = '<i class="fas fa-sign-out-alt" style="color: #10b981;"></i>';
                    resultTitle.textContent = 'Exit Successful';
                    document.getElementById('durationInfo').style.display = 'block';
                    document.getElementById('duration').textContent = data.duration || '';
                }

                resultMessage.innerHTML = data.message;
                
                // Show user info
                userInfo.style.display = 'grid';
                document.getElementById('userName').textContent = data.user_name || '';
                document.getElementById('userEmail').textContent = data.email || '';
                document.getElementById('parkingNumber').textContent = data.parking_number || '';
                document.getElementById('spotInfo').textContent = data.spot_number || '';
                document.getElementById('campusInfo').textContent = data.campus || '';
                document.getElementById('timeInfo').textContent = data.entry_time || data.exit_time || '';
                
                if (data.photo_url) {
                    document.getElementById('userPhoto').src = data.photo_url;
                }
                
            } else {
                resultDisplay.classList.add('error');
                resultIcon.innerHTML = '<i class="fas fa-times-circle" style="color: #ef4444;"></i>';
                resultTitle.textContent = 'Access Denied';
                resultMessage.textContent = data.message;
            }

            resultDisplay.style.display = 'block';
            
            // Hide Gmail container when showing results
            document.getElementById('gmailContainer').style.display = 'none';

            // Auto-hide and reset after delay
            setTimeout(() => {
                resultDisplay.style.display = 'none';
                if (data.status === 'success') {
                    resetScanner();
                } else {
                    // On error, allow retry from current step
                    if (currentStep === 'gmail_verify') {
                        document.getElementById('gmailContainer').style.display = 'block';
                        document.getElementById('gmailInput').value = '';
                        document.getElementById('gmailInput').focus();
                    } else {
                        resetScanner();
                    }
                }
            }, data.status === 'success' ? 8000 : 5000);
        }

        function showError(message) {
            displayResult({
                status: 'error',
                message: message
            });
        }

        function showLoading(show) {
            document.getElementById('loadingOverlay').style.display = show ? 'flex' : 'none';
        }

        // Testing Functions
        function testWallCode() {
            const wallCode = document.getElementById('wallCodeInput').value.trim();
            if (!wallCode) {
                alert('Please enter a wall code');
                return;
            }
            processScannedCode(wallCode);
        }

        function testGmail() {
            const gmail = document.getElementById('testGmailInput').value.trim();
            if (!gmail) {
                alert('Please enter a Gmail address');
                return;
            }
            document.getElementById('gmailInput').value = gmail;
            verifyGmail();
        }

        function quickTest(code) {
            document.getElementById('wallCodeInput').value = code;
            testWallCode();
        }

        function quickEmailTest(email) {
            document.getElementById('testGmailInput').value = email;
            testGmail();
        }

        function loadTestData() {
            showLoading(true);
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'get_test_data' })
            })
            .then(response => response.json())
            .then(data => {
                showLoading(false);
                if (data.status === 'success') {
                    let html = '';
                    
                    if (data.wall_codes.length > 0) {
                        html += '<h6><i class="fas fa-door-open me-2"></i>Available Wall Codes:</h6>';
                        data.wall_codes.forEach(wc => {
                            html += `<div class="mb-1"><code>${wc.code}</code> - ${wc.description}</div>`;
                        });
                        html += '<hr>';
                    }
                    
                    if (data.users.length > 0) {
                        html += '<h6><i class="fas fa-users me-2"></i>Available Users:</h6>';
                        data.users.forEach(user => {
                            const campusText = user.campus_id == 1 ? 'Main' : user.campus_id == 2 ? 'Beirut' : 'Saida';
                            html += `<div class="mb-1"><strong>${user.FIRST} ${user.Last}</strong> - <code>${user.Email}</code> (${user.parking_number} - ${campusText})</div>`;
                        });
                        html += '<hr>';
                    }
                    
                    if (data.active_sessions.length > 0) {
                        html += '<h6><i class="fas fa-car me-2"></i>Active Parking Sessions:</h6>';
                        data.active_sessions.forEach(session => {
                            html += `<div class="mb-1"><strong>${session.FIRST} ${session.Last}</strong> (${session.Email}) - Spot ${session.spot_number} since ${new Date(session.entrance_at).toLocaleString()}</div>`;
                        });
                    } else {
                        html += '<h6><i class="fas fa-car me-2"></i>Active Parking Sessions:</h6><div class="text-muted">No active sessions</div>';
                    }
                    
                    document.getElementById('testDataDisplay').innerHTML = html;
                } else {
                    document.getElementById('testDataDisplay').innerHTML = 
                        '<div class="alert alert-danger">Error loading test data: ' + data.message + '</div>';
                }
            })
            .catch(error => {
                showLoading(false);
                document.getElementById('testDataDisplay').innerHTML = 
                    '<div class="alert alert-danger">Network error loading test data</div>';
            });
        }

        function toggleTestingPanel() {
            const panel = document.getElementById('testingPanel');
            const showBtn = document.getElementById('showTestingBtn');
            
            if (panel.style.display === 'none') {
                panel.style.display = 'block';
                showBtn.style.display = 'none';
            } else {
                panel.style.display = 'none';
                showBtn.style.display = 'block';
            }
        }

        // Initialize UI
        updateUI();

        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
            }
        });
    </script>
</body>
</html>