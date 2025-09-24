<?php
/**
 * LIU Parking System - Dual Scanner (Wall QR + User Barcode)
 * Complete Entry/Exit System
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
                'next_step' => 'user_barcode'
            ]);
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid wall code. Please try again.'
            ]);
        }
        exit;
    }
    
    if ($_POST['action'] === 'scan_user_barcode') {
        $user_barcode = trim($_POST['user_barcode'] ?? '');
        
        if (!isset($_SESSION['selected_wall_code'])) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Please scan wall code first'
            ]);
            exit;
        }
        
        $wall_code = $_SESSION['selected_wall_code'];
        
        try {
            // Find user by parking number (barcode)
            $stmt = $pdo->prepare("
                SELECT u.*, c.name as campus_name 
                FROM users u 
                LEFT JOIN campuses c ON u.campus_id = c.id
                WHERE u.parking_number = ? AND u.Email IS NOT NULL AND u.Email != ''
            ");
            $stmt->execute([$user_barcode]);
            $user = $stmt->fetch();
            
            if (!$user) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'User not found with barcode: ' . $user_barcode
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
                    'photo_url' => $user['photo_url']
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
                    'photo_url' => $user['photo_url']
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
}

$pageTitle = "Parking Scanner - LIU System";
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
            max-width: 600px;
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
        }

        .step {
            display: flex;
            align-items: center;
            padding: 0.5rem 1rem;
            margin: 0 0.5rem;
            border-radius: 25px;
            font-weight: 500;
            transition: all 0.3s;
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

        .scan-btn {
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
                <h1><i class="fas fa-qrcode me-2"></i>Parking Scanner</h1>
                <p>Two-step scanning process for entry/exit</p>
            </div>

            <div class="scanner-body">
                <!-- Step Indicator -->
                <div class="step-indicator">
                    <div class="step active" id="step1">
                        <i class="fas fa-door-open me-2"></i>1. Wall Code
                    </div>
                    <div class="step inactive" id="step2">
                        <i class="fas fa-barcode me-2"></i>2. User Barcode
                    </div>
                </div>

                <!-- Current Wall Code Display -->
                <div id="wallCodeDisplay" style="display: none;" class="alert alert-info text-center mb-3">
                    <strong>Current Location:</strong> <span id="currentWallCode"></span>
                    <button class="btn btn-sm btn-outline-secondary ms-2" onclick="resetScanner()">
                        <i class="fas fa-refresh me-1"></i>Change
                    </button>
                </div>

                <!-- Instructions -->
                <div class="alert alert-warning" id="instructions">
                    <i class="fas fa-info-circle me-2"></i>
                    <span id="instructionText">First, scan the <strong>Wall QR Code</strong> to select location</span>
                </div>

                <!-- Camera Container -->
                <div class="camera-container">
                    <video id="camera-feed" autoplay playsinline muted></video>
                    <div class="scanner-overlay"></div>
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
        let currentStep = 'wall_code'; // 'wall_code' or 'user_barcode'
        let wallCodeSelected = false;

        document.addEventListener('DOMContentLoaded', function() {
            canvas = document.createElement('canvas');
            context = canvas.getContext('2d');
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
                alert('Camera access denied. Please check permissions.');
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
                fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
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
                        currentStep = 'user_barcode';
                        updateUI();
                        document.getElementById('currentWallCode').textContent = data.wall_description;
                        document.getElementById('wallCodeDisplay').style.display = 'block';
                    } else {
                        showError(data.message);
                    }
                })
                .catch(error => {
                    showLoading(false);
                    showError('Network error. Please try again.');
                });

            } else if (currentStep === 'user_barcode') {
                // Process user barcode
                fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'scan_user_barcode',
                        user_barcode: scannedData
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
        }

        function updateUI() {
            const step1 = document.getElementById('step1');
            const step2 = document.getElementById('step2');
            const instructions = document.getElementById('instructionText');

            if (currentStep === 'wall_code') {
                step1.className = 'step active';
                step2.className = 'step inactive';
                instructions.innerHTML = 'First, scan the <strong>Wall QR Code</strong> to select location';
            } else {
                step1.className = 'step completed';
                step2.className = 'step active';
                instructions.innerHTML = 'Now scan your <strong>User Barcode</strong> (parking number)';
            }
        }

        function resetScanner() {
            fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'reset_scanner' })
            });
            
            wallCodeSelected = false;
            currentStep = 'wall_code';
            updateUI();
            document.getElementById('wallCodeDisplay').style.display = 'none';
            document.getElementById('resultDisplay').style.display = 'none';
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

            // Auto-hide and reset after delay
            setTimeout(() => {
                resultDisplay.style.display = 'none';
                if (data.status === 'success') {
                    resetScanner();
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