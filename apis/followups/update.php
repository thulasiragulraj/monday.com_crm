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

/* ================= METHOD VALIDATION (POST only) ================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, "Method not allowed. Use POST only.");
}

/* ================= AUTH ================= */
$user = get_authenticated_user();
if (!$user) fail(401, "Unauthorized");

$role  = $user['role'] ?? '';
$my_id = (int)($user['id'] ?? 0);

if (!in_array($role, ['admin','manager','sales'], true)) fail(403, "Access denied");

/* ================= INPUT (POST/GET/JSON) ================= */
$input = $_POST;
if (!empty($_GET)) $input = array_merge($_GET, $input);
if (empty($input)) {
    $raw = file_get_contents("php://input");
    $json = json_decode($raw, true);
    if (is_array($json)) $input = $json;
}

$id     = isset($input['id']) ? (int)$input['id'] : 0;
$status = clean($input['status'] ?? '');

if ($id <= 0) fail(400, "id required");

$allowedStatus = ['pending','done','cancelled'];
if ($status === '' || !in_array($status, $allowedStatus, true)) {
    fail(400, "Invalid status", ["allowed"=>$allowedStatus]);
}

/* ================= LOAD FOLLOWUP ================= */
$st = $conn->prepare("SELECT id, employee_id, status FROM followups WHERE id=? LIMIT 1");
if (!$st) fail(500, "Prepare failed", ["error"=>$conn->error]);

$st->bind_param("i", $id);
$st->execute();
$row = $st->get_result()->fetch_assoc();

if (!$row) fail(404, "Followup not found");

/* ================= SALES RESTRICTION ================= */
if ($role === 'sales') {
    if ((int)($row['employee_id'] ?? 0) !== $my_id) {
        fail(403, "Sales can update only their followups");
    }
}

/* ================= UPDATE STATUS ================= */
$up = $conn->prepare("UPDATE followups SET status=? WHERE id=?");
if (!$up) fail(500, "Prepare failed", ["error"=>$conn->error]);

$up->bind_param("si", $status, $id);

if (!$up->execute()) {
    fail(500, "Status update failed", ["error"=>$up->error]);
}

echo json_encode([
    "success" => true,
    "msg" => "Followup status updated",
    "id" => $id,
    "old_status" => $row['status'],
    "new_status" => $status
]);