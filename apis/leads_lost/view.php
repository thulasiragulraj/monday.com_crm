<?php
header("Content-Type: application/json");

require '../../config/db.php';
require '../../config/jwt.php';
require '../../middleware/auth.php';

function fail($code, $msg, $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge(["success"=>false,"msg"=>$msg], $extra));
    exit;
}

$user = get_authenticated_user();
if (!$user) fail(401, "Unauthorized");

$role  = $user['role'] ?? '';
$my_id = (int)($user['id'] ?? 0);

if (!in_array($role, ['admin','manager','sales'], true)) {
    fail(403, "Access denied");
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    fail(405, "GET only");
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) fail(400, "id required");

$stmt = $conn->prepare("
    SELECT id, original_lead_id, name, phone, email, source_id, product_id,
           message, status, assigned_to, moved_at
    FROM leads_lost
    WHERE id=?
    LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) fail(404, "Record not found");

# sales can view only their assigned records
if ($role === 'sales') {
    if ((int)($row['assigned_to'] ?? 0) !== $my_id) {
        fail(403, "Sales can view only assigned data");
    }
}

echo json_encode([
    "success" => true,
    "msg" => "Leads lost view",
    "data" => $row
]);