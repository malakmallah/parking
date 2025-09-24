<?php
/**
 * Simple Parking Number Generator - No CONCAT functions
 * Run this to generate parking numbers for all users
 */

$db_host='localhost'; $db_name='parking'; $db_user='root'; $db_pass='';
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("DB error: ".$e->getMessage());
}

// Get all users who need parking numbers
$users = $pdo->query("
    SELECT u.id, u.FIRST, u.Last, u.Email, u.campus_id, c.code as campus_code
    FROM users u
    LEFT JOIN campuses c ON u.campus_id = c.id
    WHERE u.parking_number IS NULL OR u.parking_number = ''
    ORDER BY u.id
")->fetchAll(PDO::FETCH_ASSOC);

echo "<h2>Generating Parking Numbers</h2>";
echo "<p>Found " . count($users) . " users without parking numbers</p>";

$updateStmt = $pdo->prepare("UPDATE users SET parking_number = ? WHERE id = ?");
$generated = 0;

foreach ($users as $user) {
    // Create parking number using PHP string functions (no SQL CONCAT)
    $campus_prefix = $user['campus_code'] ? $user['campus_code'] : 'LIU';
    $user_id_padded = str_pad($user['id'], 6, '0', STR_PAD_LEFT);
    $parking_number = $campus_prefix . '-' . $user_id_padded;
    
    // Update the user
    $updateStmt->execute([$parking_number, $user['id']]);
    
    echo "<p>User #{$user['id']}: {$user['FIRST']} {$user['Last']} → <strong>{$parking_number}</strong></p>";
    $generated++;
}

echo "<hr>";
echo "<h3>✅ Generated {$generated} parking numbers!</h3>";

// Show sample results
echo "<h3>Sample Results:</h3>";
$sample = $pdo->query("
    SELECT u.id, u.FIRST, u.Last, u.Email, u.parking_number, c.code as campus_code
    FROM users u
    LEFT JOIN campuses c ON u.campus_id = c.id
    WHERE u.parking_number IS NOT NULL
    ORDER BY u.parking_number
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Campus</th><th>Parking Number</th></tr>";
foreach ($sample as $user) {
    echo "<tr>";
    echo "<td>{$user['id']}</td>";
    echo "<td>{$user['FIRST']} {$user['Last']}</td>";
    echo "<td>{$user['Email']}</td>";
    echo "<td>{$user['campus_code']}</td>";
    echo "<td><strong>{$user['parking_number']}</strong></td>";
    echo "</tr>";
}
echo "</table>";

// Count total users with parking numbers
$total = $pdo->query("SELECT COUNT(*) as count FROM users WHERE parking_number IS NOT NULL")->fetch()['count'];
echo "<p><strong>Total users with parking numbers: {$total}</strong></p>";
?>