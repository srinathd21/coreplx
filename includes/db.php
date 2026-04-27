<?php
$host = "srv483.hstgr.io";
$username = "u399080022_dms";
$password = "Ariharan@2025";
$database = "u399080022_dms";

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?>