<?php
$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "fit_db2";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
?>