<?php
// db.php - works on both localhost (XAMPP) and InfinityFree

// Detect if we are on localhost or on the hosting
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

if ($host === 'localhost' || $host === '127.0.0.1') {
    // ----- LOCAL XAMPP SETTINGS -----
    $servername  = "localhost";
    $db_username = "root";
    $db_password = "";
    $dbname      = "fit_db2";   // your local DB name
} else {
    // ----- INFINITYFREE SETTINGS -----
    $servername  = "sql303.infinityfree.com";   // MySQL Host Name
    $db_username = "if0_40434344";              // MySQL User Name
    $db_password = "YOUR_VPANEL_PASSWORD_HERE"; // 🔴 your control panel password
    $dbname      = "if0_40434344_myapp";        // MySQL DB Name
}

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
?>