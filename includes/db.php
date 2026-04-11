<?php
$host = "srv1740.hstgr.io";
$username = "u966043993_coreplx_DMS";
$password = "R=FV:EIe60#k";
$database = "u966043993_coreplx_DMS";

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
?>