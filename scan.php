<?php
/**
 * LIU Parking System - QR Code Scanner
 * Main interface for scanning wall-mounted QR codes
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

// Handle AJAX requests for QR code processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'process_qr') {
        $scanned_code = trim($_POST['scanned_code'] ?? '');
        $user_email = trim($_POST['user_email'] ?? '');
        
        try {
            // Validate wall code exists
            $stmt = $pdo->prepare("SELECT * FROM wall_codes WHERE code = ?");
            $stmt->execute([$scanned_code]);
            $wall_code = $stmt->fetch();
            
            if (!$wall_code) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Invalid QR code. Please scan a valid wall code.',
                    'type' => 'DENIED'
                ]);
                exit;
            }
            
            // Get campus from wall code
            $campus_code = substr($scanned_code, 0, 3);
            $stmt = $pdo->prepare("SELECT * FROM campuses WHERE code = ?");
            $stmt->execute([$campus_code]);
            $campus = $stmt->fetch();
            
            if (!$campus) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Campus not found for this QR code.',
                    'type' => 'DENIED'
                ]);
                exit;
            }
            
            // Validate user
            $stmt = $pdo->prepare("
                SELECT u.*, c.name as campus_name 
                FROM users u 
                LEFT JOIN campuses c ON u.campus_id = c.id
                WHERE LOWER(u.Email) = LOWER(?) 
                AND u.role IN ('staff', 'instructor')
            ");
            $stmt->execute([$user_email]);
            $user = $stmt->fetch();
            
            if (!$user) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'User not found or not authorized for parking.',
                    'type' => 'DENIED'
                ]);
                exit;
            }
            
            // Check if user has open parking session
            $stmt = $pdo->prepare("
                SELECT ps.*, s.spot_number, c.name as campus_name, b.name as block_name
                FROM parking_sessions ps
                JOIN parking_spots s ON ps.spot_id = s.id
                LEFT JOIN campuses c ON s.campus_id = c.id
                LEFT JOIN blocks b ON s.block_id = b.id
                WHERE ps.user_id = ? AND ps.exit_at IS NULL
            ");
            $stmt->execute([$user['id']]);
            $active_session = $stmt->fetch();
            
            if ($active_session) {
                // EXIT PROCESS
                try {
                    $stmt = $pdo->prepare("
                        UPDATE parking_sessions 
                        SET exit_at = NOW(), gate_out_id = NULL 
                        WHERE id = ?
                    ");
                    $stmt->execute([$active_session['id']]);
                    
                    $session_duration = date_diff(
                        new DateTime($active_session['entrance_at']), 
                        new DateTime()
                    )->format('%h hours %i minutes');
                    
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Exit successful! Have a safe trip.',
                        'type' => 'EXIT',
                        'user_name' => $user['FIRST'] . ' ' . $user['Last'],
                        'spot_number' => $active_session['spot_number'],
                        'campus' => $active_session['campus_name'],
                        'block' => $active_session['block_name'],
                        'entry_time' => date('M j, Y g:i A', strtotime($active_session['entrance_at'])),
                        'duration' => $session_duration,
                        'photo_url' => $user['photo_url']
                    ]);
                } catch (Exception $e) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Error processing exit. Please try again.',
                        'type' => 'DENIED'
                    ]);
                }
            } else {
                // ENTRY PROCESS
                // Find available spot in the scanned campus
                $stmt = $pdo->prepare("
                    SELECT * FROM parking_spots 
                    WHERE campus_id = ? AND is_occupied = 0 AND is_reserved = 0
                    ORDER BY spot_number 
                    LIMIT 1
                ");
                $stmt->execute([$campus['id']]);
                $available_spot = $stmt->fetch();
                
                if (!$available_spot) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'No available parking spots in ' . $campus['name'] . ' campus.',
                        'type' => 'DENIED'
                    ]);
                    exit;
                }
                
                // Check campus restrictions (if user has assigned campus)
                if ($user['campus_id'] && $user['campus_id'] != $campus['id']) {
                    // Different campus - check if cross-campus is allowed
                    $stmt = $pdo->prepare("SELECT v FROM settings WHERE k = 'allow_cross_campus'");
                    $stmt->execute();
                    $setting = $stmt->fetch();
                    
                    if (!$setting || $setting['v'] !== '1') {
                        echo json_encode([
                            'status' => 'error',
                            'message' => 'Access denied. You can only park at ' . $user['campus_name'] . ' campus.',
                            'type' => 'DENIED'
                        ]);
                        exit;
                    }
                }
                
                try {
                    // Create parking session
                    $stmt = $pdo->prepare("
                        INSERT INTO parking_sessions (user_id, spot_id, entrance_at, gate_in_id) 
                        VALUES (?, ?, NOW(), NULL)
                    ");
                    $stmt->execute([$user['id'], $available_spot['id']]);
                    
                    // Get block info if exists
                    $block_name = null;
                    if ($available_spot['block_id']) {
                        $stmt = $pdo->prepare("SELECT name FROM blocks WHERE id = ?");
                        $stmt->execute([$available_spot['block_id']]);
                        $block = $stmt->fetch();
                        $block_name = $block ? $block['name'] : null;
                    }
                    
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Welcome! Parking assigned successfully.',
                        'type' => 'ENTRY',
                        'user_name' => $user['FIRST'] . ' ' . $user['Last'],
                        'spot_number' => $available_spot['spot_number'],
                        'campus' => $campus['name'],
                        'block' => $block_name,
                        'entry_time' => date('M j, Y g:i A'),
                        'parking_number' => $user['parking_number'],
                        'photo_url' => $user['photo_url']
                    ]);
                } catch (Exception $e) {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Error processing entry. Please try again.',
                        'type' => 'DENIED'
                    ]);
                }
            }
        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => 'System error. Please contact support.',
                'type' => 'DENIED'
            ]);
        }
        exit;
    }
}

$pageTitle = "QR Scanner - LIU Parking System";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="description" content="QR Code Scanner for LIU Parking System">

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

    <style>
        :root {
            --primary-color: #2563eb;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
            --dark-color: #1e293b;
            --light-color: #f8fafc;
            --border-color: #e2e8f0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: var(--dark-color);
            overflow-x: hidden;
        }

        .scanner-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .scanner-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 500px;
            overflow: hidden;
        }

        .scanner-header {
            background: linear-gradient(135deg, var(--primary-color), #3b82f6);
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .scanner-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .scanner-header p {
            opacity: 0.9;
            margin: 0;
        }

        .scanner-body {
            padding: 2rem;
        }

        .camera-container {
            position: relative;
            width: 100%;
            height: 300px;
            background: #000;
            border-radius: 15px;
            overflow: hidden;
            margin-bottom: 2rem;
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
            border: 3px solid var(--success-color);
            border-radius: 15px;
            background: rgba(16, 185, 129, 0.1);
        }

        .scanner-corners {
            position: absolute;
            width: 30px;
            height: 30px;
            border: 4px solid var(--success-color);
        }

        .corner-tl {
            top: -2px;
            left: -2px;
            border-right: none;
            border-bottom: none;
        }

        .corner-tr {
            top: -2px;
            right: -2px;
            border-left: none;
            border-bottom: none;
        }

        .corner-bl {
            bottom: -2px;
            left: -2px;
            border-right: none;
            border-top: none;
        }

        .corner-br {
            bottom: -2px;
            right: -2px;
            border-left: none;
            border-top: none;
        }

        .email-input-section {
            margin-bottom: 2rem;
        }

        .email-input {
            width: 100%;
            padding: 1rem;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .email-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .scan-button {
            width: 100%;
            background: linear-gradient(135deg, var(--success-color), #059669);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 1rem;
        }

        .scan-button:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.3);
        }

        .scan-button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .manual-input-toggle {
            width: 100%;
            background: transparent;
            color: var(--primary-color);
            border: 2px solid var(--primary-color);
            padding: 0.75rem;
            border-radius: 10px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .manual-input-toggle:hover {
            background: var(--primary-color);
            color: white;
        }

        .manual-input-section {
            display: none;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        .manual-input-section.show {
            display: block;
        }

        .status-display {
            margin-top: 2rem;
            padding: 1.5rem;
            border-radius: 15px;
            text-align: center;
            display: none;
        }

        .status-display.success {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            border: 2px solid var(--success-color);
            color: #065f46;
        }

        .status-display.error {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            border: 2px solid var(--danger-color);
            color: #991b1b;
        }

        .status-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .user-info {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 1rem;
            align-items: center;
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
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .user-details h4 {
            margin: 0 0 0.5rem 0;
            color: var(--dark-color);
        }

        .user-details p {
            margin: 0.25rem 0;
            font-size: 0.9rem;
            color: #6b7280;
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

        .loading-spinner {
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
            top: 2rem;
            left: 2rem;
            background: rgba(255, 255, 255, 0.9);
            color: var(--primary-color);
            padding: 0.75rem 1rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 500;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            z-index: 100;
        }

        .home-link:hover {
            background: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            color: var(--primary-color);
            text-decoration: none;
        }

        @media (max-width: 768px) {
            .scanner-container {
                padding: 0.5rem;
            }

            .scanner-card {
                max-width: 100%;
            }

            .scanner-header {
                padding: 1.5rem;
            }

            .scanner-body {
                padding: 1.5rem;
            }

            .camera-container {
                height: 250px;
            }

            .scanner-overlay {
                width: 150px;
                height: 150px;
            }

            .home-link {
                top: 1rem;
                left: 1rem;
                padding: 0.5rem 0.75rem;
                font-size: 0.9rem;
            }
        }
    </style>
</head>

<body>
    <!-- Home Link -->
    <a href="index.php" class="home-link">
        <i class="fas fa-home me-2"></i>Home
    </a>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>

    <!-- Main Scanner Container -->
    <div class="scanner-container">
        <div class="scanner-card">
            <div class="scanner-header">
                <h1><i class="fas fa-qrcode me-2"></i>QR Scanner</h1>
                <p>Scan wall QR code or enter your email to access parking</p>
            </div>

            <div class="scanner-body">
                <!-- Email Input Section -->
                <div class="email-input-section">
                    <label for="userEmail" class="form-label">
                        <i class="fas fa-envelope me-2"></i>Your LIU Email Address
                    </label>
                    <input 
                        type="email" 
                        id="userEmail" 
                        class="email-input" 
                        placeholder="Enter your LIU email address"
                        required
                    >
                </div>

                <!-- Camera Container -->
                <div class="camera-container">
                    <video id="camera-feed" autoplay playsinline muted></video>
                    <div class="scanner-overlay">
                        <div class="scanner-corners corner-tl"></div>
                        <div class="scanner-corners corner-tr"></div>
                        <div class="scanner-corners corner-bl"></div>
                        <div class="scanner-corners corner-br"></div>
                    </div>
                </div>

                <!-- Scan Button -->
                <button class="scan-button" id="startScanBtn" onclick="startScanning()">
                    <i class="fas fa-camera me-2"></i>Start Camera
                </button>

                <!-- Manual Input Toggle -->
                <button class="manual-input-toggle" onclick="toggleManualInput()">
                    <i class="fas fa-keyboard me-2"></i>Enter Code Manually
                </button>

                <!-- Manual Input Section -->
                <div class="manual-input-section" id="manualInputSection">
                    <label for="manualCode" class="form-label">Wall Code</label>
                    <input 
                        type="text" 
                        id="manualCode" 
                        class="form-control mb-2" 
                        placeholder="e.g., BEI0000001"
                        style="text-transform: uppercase;"
                    >
                    <button class="btn btn-primary w-100" onclick="processManualCode()">
                        <i class="fas fa-check me-2"></i>Process Code
                    </button>
                </div>

                <!-- Status Display -->
                <div class="status-display" id="statusDisplay">
                    <div class="status-icon" id="statusIcon"></div>
                    <h3 id="statusTitle"></h3>
                    <p id="statusMessage"></p>
                    <div class="user-info" id="userInfo" style="display: none;">
                        <img id="userPhoto" class="user-photo" src="" alt="User Photo" onerror="this.src='data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 100 100\'><circle cx=\'50\' cy=\'50\' r=\'50\' fill=\'%23e5e7eb\'/><text x=\'50\' y=\'55\' text-anchor=\'middle\' font-size=\'35\' fill=\'%236b7280\'>👤</text></svg>'">
                        <div class="user-details">
                            <h4 id="userName"></h4>
                            <p><strong>Spot:</strong> <span id="spotInfo"></span></p>
                            <p><strong>Campus:</strong> <span id="campusInfo"></span></p>
                            <p><strong>Time:</strong> <span id="timeInfo"></span></p>
                            <p id="durationInfo" style="display: none;"><strong>Duration:</strong> <span id="duration"></span></p>
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
        let video = null;
        let canvas = null;
        let context = null;
        let scanning = false;
        let stream = null;

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            canvas = document.createElement('canvas');
            context = canvas.getContext('2d');
        });

        // Start camera scanning
        async function startScanning() {
            const email = document.getElementById('userEmail').value.trim();
            if (!email) {
                alert('Please enter your email address first');
                return;
            }

            if (!email.includes('@')) {
                alert('Please enter a valid email address');
                return;
            }

            const btn = document.getElementById('startScanBtn');
            
            try {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Starting Camera...';

                video = document.getElementById('camera-feed');
                
                // Request camera access
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
                btn.onclick = stopScanning;
                btn.disabled = false;

                // Start scanning loop
                requestAnimationFrame(scanQRCode);

            } catch (error) {
                console.error('Camera access error:', error);
                alert('Unable to access camera. Please check permissions and try again.');
                btn.innerHTML = '<i class="fas fa-camera me-2"></i>Start Camera';
                btn.disabled = false;
            }
        }

        // Stop camera scanning
        function stopScanning() {
            scanning = false;
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
            
            const btn = document.getElementById('startScanBtn');
            btn.innerHTML = '<i class="fas fa-camera me-2"></i>Start Camera';
            btn.onclick = startScanning;
        }

        // QR Code scanning loop
        function scanQRCode() {
            if (!scanning || !video || video.readyState !== video.HAVE_ENOUGH_DATA) {
                if (scanning) {
                    requestAnimationFrame(scanQRCode);
                }
                return;
            }

            canvas.height = video.videoHeight;
            canvas.width = video.videoWidth;
            context.drawImage(video, 0, 0, canvas.width, canvas.height);

            const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
            const code = jsQR(imageData.data, imageData.width, imageData.height);

            if (code) {
                console.log('QR Code detected:', code.data);
                processQRCode(code.data);
                return;
            }

            requestAnimationFrame(scanQRCode);
        }

        // Process scanned QR code
        function processQRCode(scannedCode) {
            const email = document.getElementById('userEmail').value.trim();
            
            if (!email) {
                alert('Please enter your email address');
                return;
            }

            stopScanning();
            showLoading(true);

            // Send to server for processing
            fetch('scan.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'process_qr',
                    scanned_code: scannedCode,
                    user_email: email
                })
            })
            .then(response => response.json())
            .then(data => {
                showLoading(false);
                displayResult(data);
            })
            .catch(error => {
                showLoading(false);
                console.error('Error:', error);
                displayResult({
                    status: 'error',
                    message: 'Network error. Please try again.',
                    type: 'DENIED'
                });
            });
        }

        // Process manual code entry
        function processManualCode() {
            const code = document.getElementById('manualCode').value.trim().toUpperCase();
            const email = document.getElementById('userEmail').value.trim();
            
            if (!code) {
                alert('Please enter a wall code');
                return;
            }

            if (!email) {
                alert('Please enter your email address');
                return;
            }

            processQRCode(code);
        }

        // Toggle manual input section
        function toggleManualInput() {
            const section = document.getElementById('manualInputSection');
            section.classList.toggle('show');
        }

        // Show/hide loading overlay
        function showLoading(show) {
            const overlay = document.getElementById('loadingOverlay');
            overlay.style.display = show ? 'flex' : 'none';
        }

        // Display scan result
        function displayResult(data) {
            const statusDisplay = document.getElementById('statusDisplay');
            const statusIcon = document.getElementById('statusIcon');
            const statusTitle = document.getElementById('statusTitle');
            const statusMessage = document.getElementById('statusMessage');
            const userInfo = document.getElementById('userInfo');

            // Reset display
            statusDisplay.className = 'status-display';
            userInfo.style.display = 'none';

            if (data.status === 'success') {
                statusDisplay.classList.add('success');
                
                if (data.type === 'ENTRY') {
                    statusIcon.innerHTML = '<i class="fas fa-times-circle"></i>';
                statusIcon.style.color = '#ef4444';
                statusTitle.textContent = 'Access Denied';
                statusMessage.textContent = data.message;
            }

            statusDisplay.style.display = 'block';

            // Auto-hide after 10 seconds for success, 15 seconds for error
            setTimeout(() => {
                statusDisplay.style.display = 'none';
                document.getElementById('durationInfo').style.display = 'none';
            }, data.status === 'success' ? 10000 : 15000);
        }

        // Format manual input to uppercase
        document.getElementById('manualCode').addEventListener('input', function(e) {
            e.target.value = e.target.value.toUpperCase();
        });

        // Enter key handlers
        document.getElementById('userEmail').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                startScanning();
            }
        });

        document.getElementById('manualCode').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                processManualCode();
            }
        });

        // Auto-focus email input
        document.getElementById('userEmail').focus();

        // Check for camera support
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            document.getElementById('startScanBtn').style.display = 'none';
            const manualToggle = document.querySelector('.manual-input-toggle');
            manualToggle.textContent = 'Camera not supported - Use manual entry';
            manualToggle.click();
        }

        // Add camera permission request on page load
        window.addEventListener('load', function() {
            // Check if camera permission is already granted
            navigator.permissions.query({name: 'camera'}).then(function(result) {
                if (result.state === 'denied') {
                    console.log('Camera permission denied');
                    document.querySelector('.manual-input-toggle').click();
                }
            }).catch(function(error) {
                console.log('Permission query not supported');
            });
        });

        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
            }
        });

        // Vibration feedback for mobile devices
        function vibrateDevice(pattern = [200]) {
            if ('vibrate' in navigator) {
                navigator.vibrate(pattern);
            }
        }

        // Enhanced QR processing with vibration
        function processQRCodeWithFeedback(scannedCode) {
            vibrateDevice([100, 50, 100]); // Double vibration for scan detection
            processQRCode(scannedCode);
        }

        // Update the scan loop to use vibration
        function scanQRCodeEnhanced() {
            if (!scanning || !video || video.readyState !== video.HAVE_ENOUGH_DATA) {
                if (scanning) {
                    requestAnimationFrame(scanQRCodeEnhanced);
                }
                return;
            }

            canvas.height = video.videoHeight;
            canvas.width = video.videoWidth;
            context.drawImage(video, 0, 0, canvas.width, canvas.height);

            const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
            const code = jsQR(imageData.data, imageData.width, imageData.height);

            if (code) {
                console.log('QR Code detected:', code.data);
                processQRCodeWithFeedback(code.data);
                return;
            }

            requestAnimationFrame(scanQRCodeEnhanced);
        }

        // Replace the original scan function
        function scanQRCode() {
            scanQRCodeEnhanced();
        }

        // Add torch/flashlight support for mobile devices
        let torchEnabled = false;
        
        function toggleTorch() {
            if (stream && stream.getVideoTracks().length > 0) {
                const track = stream.getVideoTracks()[0];
                const capabilities = track.getCapabilities();
                
                if (capabilities.torch) {
                    torchEnabled = !torchEnabled;
                    track.applyConstraints({
                        advanced: [{ torch: torchEnabled }]
                    }).then(() => {
                        console.log('Torch toggled:', torchEnabled);
                    }).catch(err => {
                        console.error('Torch toggle failed:', err);
                    });
                }
            }
        }

        // Add torch button if supported
        function addTorchButton() {
            const scannerBody = document.querySelector('.scanner-body');
            const torchBtn = document.createElement('button');
            torchBtn.className = 'btn btn-outline-primary w-100 mt-2';
            torchBtn.innerHTML = '<i class="fas fa-flashlight me-2"></i>Toggle Flashlight';
            torchBtn.onclick = toggleTorch;
            torchBtn.id = 'torchBtn';
            torchBtn.style.display = 'none';
            
            scannerBody.appendChild(torchBtn);
        }

        // Initialize torch button
        addTorchButton();

        // Show torch button when camera starts
        const originalStartScanning = startScanning;
        startScanning = async function() {
            await originalStartScanning();
            
            // Check if torch is supported
            if (stream && stream.getVideoTracks().length > 0) {
                const track = stream.getVideoTracks()[0];
                const capabilities = track.getCapabilities();
                
                if (capabilities.torch) {
                    document.getElementById('torchBtn').style.display = 'block';
                }
            }
        };

        // Add sound effects (optional)
        function playSound(type) {
            // Create audio context for sound effects
            try {
                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioContext.createOscillator();
                const gainNode = audioContext.createGain();
                
                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);
                
                if (type === 'success') {
                    oscillator.frequency.setValueAtTime(800, audioContext.currentTime);
                    oscillator.frequency.setValueAtTime(1000, audioContext.currentTime + 0.1);
                } else if (type === 'error') {
                    oscillator.frequency.setValueAtTime(300, audioContext.currentTime);
                    oscillator.frequency.setValueAtTime(200, audioContext.currentTime + 0.1);
                }
                
                gainNode.gain.setValueAtTime(0.1, audioContext.currentTime);
                gainNode.gain.setValueAtTime(0, audioContext.currentTime + 0.2);
                
                oscillator.start(audioContext.currentTime);
                oscillator.stop(audioContext.currentTime + 0.2);
            } catch (error) {
                // Sound not supported or blocked
                console.log('Sound effects not available');
            }
        }

        // Enhanced result display with sound
        const originalDisplayResult = displayResult;
        displayResult = function(data) {
            originalDisplayResult(data);
            
            // Play sound based on result
            if (data.status === 'success') {
                playSound('success');
                vibrateDevice([200, 100, 200]);
            } else {
                playSound('error');
                vibrateDevice([500]);
            }
        };
    </script>
</body>
</html>