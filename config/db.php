<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "monday_com_crm";

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die(json_encode([
        "success" => false,
        "msg" => "Database connection failed: " . $conn->connect_error
    ]));
}
