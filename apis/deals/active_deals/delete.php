<?php
header("Content-Type: application/json");

require '../../../config/db.php';
require '../../../config/jwt.php';
require '../../../middleware/auth.php';

function fail($code, $msg, $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge(["success"=>false,"msg"=>$msg], $extra));
    exit;
}

$user = get_authenticated_user();
if (!$user) fail(401, "Unauthorized");

$role = $user['role'] ?? '';
$my_id = (int)($user['id'] ?? 0);

if (!in_array($role, ['admin','manager','sales'], true)) fail(403, "Access denied");
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') fail(405, "DELETE only");

# INPUT (POST/GET/JSON)
$input = $_POST;
if (!empty($_GET)) $input = array_merge($_GET, $input);
if (empty($input)) {
    $raw = file_get_contents("php://input");
    $json = json_decode($raw, true);
    if (is_array($json)) $input = $json;
}

$deal_id = isset($input['deal_id']) ? (int)$input['deal_id'] : 0;
if ($deal_id <= 0) fail(400, "deal_id required");

# Check deal exists + role restriction
if ($role === 'sales') {
    $chk = $conn->prepare("SELECT id FROM deals WHERE id=? AND owner=? LIMIT 1");
    $chk->bind_param("ii", $deal_id, $my_id);
} else {
    $chk = $conn->prepare("SELECT id FROM deals WHERE id=? LIMIT 1");
    $chk->bind_param("i", $deal_id);
}

$chk->execute();
if ($chk->get_result()->num_rows === 0) {
    fail(404, "Deal not found (or no permission)");
}

# Delete
$del = $conn->prepare("DELETE FROM deals WHERE id=?");
$del->bind_param("i", $deal_id);

if (!$del->execute()) fail(500, "Delete failed");

echo json_encode([
    "success" => true,
    "msg" => "Deal deleted",
    "deal_id" => $deal_id
]);