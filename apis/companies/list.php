<?php
header("Content-Type: application/json");

require '../../config/db.php';
require '../../config/jwt.php';
require '../../middleware/auth.php';

$user = get_authenticated_user();

if (!$user) {
    http_response_code(401);
    echo json_encode(["success" => false, "msg" => "Unauthorized"]);
    exit;
}

/* 🔐 ROLE CHECK */
$allowed_roles = ['admin', 'manager', 'sales'];

if (!in_array($user['role'], $allowed_roles)) {
    http_response_code(403);
    echo json_encode(["success" => false, "msg" => "Access denied"]);
    exit;
}

# =========================
# 📋 FETCH ALL COMPANIES
# =========================

$sql = "SELECT id, name, email, phone, website, address, industry, created_at 
        FROM companies 
        ORDER BY id DESC";

$result = $conn->query($sql);

$companies = [];

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
    }
}

echo json_encode([
    "success" => true,
    "data" => $companies
]);