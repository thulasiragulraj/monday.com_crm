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
# 📥 GET COMPANY ID
# =========================
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) {
    http_response_code(400);
    echo json_encode(["success" => false, "msg" => "Company ID required"]);
    exit;
}

# =========================
# 📋 FETCH COMPANY
# =========================
$stmt = $conn->prepare("
    SELECT id, name, email, phone, website, address, industry, created_at
    FROM companies
    WHERE id = ?
");

$stmt->bind_param("i", $id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(["success" => false, "msg" => "Company not found"]);
    exit;
}

$company = $result->fetch_assoc();

echo json_encode([
    "success" => true,
    "data" => $company
]);