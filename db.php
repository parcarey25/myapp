<?php

// Always use Manila time for PHP
date_default_timezone_set('Asia/Manila');

$servername = "localhost";
$db_username = "root";
$db_password = "";
$dbname = "fit_db2";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// Tell MySQL to use +08:00 (Philippines time)
$conn->query("SET time_zone = '+08:00'");