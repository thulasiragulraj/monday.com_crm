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

/* 🔐 ROLE CHECK — DELETE only admin & manager */
$allowed_roles = ['admin', 'manager'];

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
# ❌ DELETE COMPANY
# =========================
$stmt = $conn->prepare("DELETE FROM companies WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {

    if ($stmt->affected_rows === 0) {
        http_response_code(404);
        echo json_encode(["success" => false, "msg" => "Company not found"]);
    } else {
        echo json_encode([
            "success" => true,
            "msg" => "Company deleted"
        ]);
    }

} else {
    http_response_code(500);
    echo json_encode(["success" => false, "msg" => "Delete failed"]);
}