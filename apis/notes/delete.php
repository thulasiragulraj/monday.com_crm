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

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) fail(400, "id required");

# Load note
$st = $conn->prepare("SELECT id, created_by FROM notes WHERE id=? LIMIT 1");
if (!$st) fail(500, "Prepare failed", ["error"=>$conn->error]);
$st->bind_param("i", $id);
$st->execute();
$row = $st->get_result()->fetch_assoc();

if (!$row) fail(404, "Note not found");

# sales restriction: only delete own created notes
if ($role === 'sales') {
    if ((int)($row['created_by'] ?? 0) !== $my_id) {
        fail(403, "Sales can delete only their own notes");
    }
}

# Delete
$del = $conn->prepare("DELETE FROM notes WHERE id=?");
if (!$del) fail(500, "Prepare failed", ["error"=>$conn->error]);
$del->bind_param("i", $id);

if (!$del->execute()) {
    fail(500, "Delete failed", ["error"=>$conn->error]);
}

echo json_encode([
    "success" => true,
    "msg" => "Note deleted",
    "id" => $id
]);