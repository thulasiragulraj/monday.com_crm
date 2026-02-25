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
function clean($v){ return trim((string)$v); }

$user = get_authenticated_user();
if (!$user) fail(401, "Unauthorized");

$role  = $user['role'] ?? '';
$my_id = (int)($user['id'] ?? 0);

if (!in_array($role, ['admin','manager','sales'], true)) fail(403, "Access denied");
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, "POST only");

# INPUT (POST/GET/JSON)
$input = $_POST;
if (!empty($_GET)) $input = array_merge($_GET, $input);
if (empty($input)) {
    $raw = file_get_contents("php://input");
    $json = json_decode($raw, true);
    if (is_array($json)) $input = $json;
}

$entity_type = clean($input['entity_type'] ?? '');
$entity_id   = isset($input['entity_id']) ? (int)$input['entity_id'] : 0;
$note        = clean($input['note'] ?? '');

if ($entity_type === '') fail(400, "entity_type required");
if ($entity_id <= 0) fail(400, "entity_id required");
if ($note === '') fail(400, "note required");

$allowedTypes = ['customer','deal','lead'];
if (!in_array($entity_type, $allowedTypes, true)) {
    fail(400, "Invalid entity_type", ["allowed"=>$allowedTypes]);
}

# 1) Validate entity exists + get owner/assigned user (for sales restriction)
$assigned_to = null;

if ($entity_type === 'customer') {
    $st = $conn->prepare("SELECT id, assigned_to FROM customers WHERE id=? LIMIT 1");
    if (!$st) fail(500, "Prepare failed", ["error"=>$conn->error]);
    $st->bind_param("i", $entity_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) fail(404, "Customer not found");
    $assigned_to = ($row['assigned_to'] !== null) ? (int)$row['assigned_to'] : null;

} elseif ($entity_type === 'deal') {
    # deals table uses owner column
    $st = $conn->prepare("SELECT id, owner FROM deals WHERE id=? LIMIT 1");
    if (!$st) fail(500, "Prepare failed", ["error"=>$conn->error]);
    $st->bind_param("i", $entity_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) fail(404, "Deal not found");
    $assigned_to = ($row['owner'] !== null) ? (int)$row['owner'] : null;

} else { # lead
    $st = $conn->prepare("SELECT id, assigned_to FROM leads WHERE id=? LIMIT 1");
    if (!$st) fail(500, "Prepare failed", ["error"=>$conn->error]);
    $st->bind_param("i", $entity_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if (!$row) fail(404, "Lead not found");
    $assigned_to = ($row['assigned_to'] !== null) ? (int)$row['assigned_to'] : null;
}

# sales restriction: must be assigned to me
if ($role === 'sales') {
    if ($assigned_to === null) {
        fail(403, "This record is not assigned. Ask admin/manager to assign first.");
    }
    if ($assigned_to !== $my_id) {
        fail(403, "Sales can add notes only for assigned data");
    }
}

# 2) Insert note
$ins = $conn->prepare("
    INSERT INTO notes (entity_type, entity_id, note, created_by)
    VALUES (?, ?, ?, ?)
");
if (!$ins) fail(500, "Prepare failed", ["error"=>$conn->error]);

$ins->bind_param("sisi", $entity_type, $entity_id, $note, $my_id);

if (!$ins->execute()) {
    fail(500, "Note create failed", ["error"=>$conn->error]);
}

echo json_encode([
    "success" => true,
    "msg" => "Note created",
    "note_id" => (int)$ins->insert_id,
    "entity_type" => $entity_type,
    "entity_id" => $entity_id,
    "created_by" => $my_id
]);