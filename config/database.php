<?php
/**
 * LIU Parking System - Database Configuration
 * Centralized database connection and helper functions
 */

// Database configuration constants
define('DB_HOST', 'localhost');
define('DB_NAME', 'parking');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Application configuration
define('APP_NAME', 'LIU Parking System');
define('APP_VERSION', '1.0.0');
define('BASE_URL', 'http://localhost/LiuParking/');
define('UPLOAD_PATH', 'uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB

// Session configuration
define('SESSION_TIMEOUT', 3600); // 1 hour in seconds

class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
            ];
            
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            throw new Exception("Database connection failed. Please try again later.");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Query Error: " . $e->getMessage() . " SQL: " . $sql);
            throw new Exception("Database query failed");
        }
    }
    
    public function fetch($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }
    
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
    
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    public function commit() {
        return $this->pdo->commit();
    }
    
    public function rollBack() {
        return $this->pdo->rollBack();
    }
}

// Global helper functions
function getDB() {
    return Database::getInstance();
}

function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

function generateId($campusCode, $lastId = 0) {
    $nextId = $lastId + 1;
    return $campusCode . str_pad($nextId, 7, '0', STR_PAD_LEFT);
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function requireAdmin() {
    requireLogin();
    if ($_SESSION['user_role'] !== 'admin') {
        header('Location: unauthorized.php');
        exit;
    }
}

function logActivity($userId, $action, $details = '') {
    try {
        $db = getDB();
        $sql = "INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())";
        $db->query($sql, [$userId, $action, $details]);
    } catch (Exception $e) {
        error_log("Activity log error: " . $e->getMessage());
    }
}

function formatDateTime($datetime, $format = 'Y-m-d H:i:s') {
    if (empty($datetime)) return 'N/A';
    return date($format, strtotime($datetime));
}

function getCampusList() {
    try {
        $db = getDB();
        return $db->fetchAll("SELECT id, name, code FROM campuses ORDER BY name");
    } catch (Exception $e) {
        return [];
    }
}

function getBlocksList($campusId = null) {
    try {
        $db = getDB();
        $sql = "SELECT b.id, b.name, c.name as campus_name FROM blocks b 
                JOIN campuses c ON b.campus_id = c.id";
        $params = [];
        
        if ($campusId) {
            $sql .= " WHERE b.campus_id = ?";
            $params[] = $campusId;
        }
        
        $sql .= " ORDER BY c.name, b.name";
        return $db->fetchAll($sql, $params);
    } catch (Exception $e) {
        return [];
    }
}

// Error reporting (for development)
if ($_SERVER['HTTP_HOST'] === 'localhost' || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', 'error.log');
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check session timeout
if (isLoggedIn() && isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
        session_destroy();
        header('Location: login.php?timeout=1');
        exit;
    }
}
$_SESSION['last_activity'] = time();

?>