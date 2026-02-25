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
# 📥 GET INPUT JSON
# =========================
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["success" => false, "msg" => "Invalid JSON"]);
    exit;
}

$id = isset($data['id']) ? (int)$data['id'] : 0;

if (!$id) {
    http_response_code(400);
    echo json_encode(["success" => false, "msg" => "Company ID required"]);
    exit;
}

# =========================
# 📋 CHECK COMPANY EXISTS
# =========================
$stmt = $conn->prepare("SELECT id FROM companies WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(["success" => false, "msg" => "Company not found"]);
    exit;
}

# =========================
# ✍️ FIELDS TO UPDATE
# =========================
$name     = trim($data['name'] ?? '');
$email    = trim($data['email'] ?? '');
$phone    = trim($data['phone'] ?? '');
$website  = trim($data['website'] ?? '');
$address  = trim($data['address'] ?? '');
$industry = trim($data['industry'] ?? '');

# =========================
# 🔄 UPDATE COMPANY
# =========================
$stmt = $conn->prepare("
    UPDATE companies
    SET name=?, email=?, phone=?, website=?, address=?, industry=?
    WHERE id=?
");

$stmt->bind_param(
    "ssssssi",
    $name,
    $email,
    $phone,
    $website,
    $address,
    $industry,
    $id
);

if ($stmt->execute()) {

    echo json_encode([
        "success" => true,
        "msg" => "Company updated"
    ]);

} else {
    http_response_code(500);
    echo json_encode(["success" => false, "msg" => "Update failed"]);
}