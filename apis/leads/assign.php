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

# =========================
# AUTH
# =========================
$user = get_authenticated_user();
if (!$user) fail(401, "Unauthorized");

$role = $user['role'] ?? '';
$my_id = (int)($user['id'] ?? 0);

if (!in_array($role, ['admin','manager','sales'], true)) {
    fail(403, "Access denied");
}

# sales cannot assign
if ($role === 'sales') {
    fail(403, "Sales cannot assign leads");
}

# =========================
# METHOD
# =========================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, "POST only");
}

# =========================
# INPUT (POST/GET/JSON)
# =========================
$input = $_POST;
if (!empty($_GET)) $input = array_merge($_GET, $input);

if (empty($input)) {
    $raw = file_get_contents("php://input");
    $json = json_decode($raw, true);
    if (is_array($json)) $input = $json;
}

$lead_id = isset($input['lead_id']) ? (int)$input['lead_id'] : 0;
if ($lead_id <= 0) fail(400, "lead_id required");

# assigned_to required here
if (!array_key_exists('assigned_to', $input)) {
    fail(400, "assigned_to required");
}
$new_assigned_to = (int)$input['assigned_to'];

# =========================
# LOAD LEAD
# =========================
$leadStmt = $conn->prepare("
    SELECT id, assigned_to
    FROM leads
    WHERE id=?
    LIMIT 1
");
$leadStmt->bind_param("i", $lead_id);
$leadStmt->execute();
$lead = $leadStmt->get_result()->fetch_assoc();

if (!$lead) fail(404, "Lead not found");

# If admin/manager gives assigned_to -> validate target is sales
if ($new_assigned_to > 0) {
    $u = $conn->prepare("SELECT id, role FROM users WHERE id=? LIMIT 1");
    $u->bind_param("i", $new_assigned_to);
    $u->execute();
    $ur = $u->get_result()->fetch_assoc();
    if (!$ur) fail(404, "assigned_to user not found");
    if (($ur['role'] ?? '') !== 'sales') fail(400, "assigned_to must be a sales user");
}

# =========================
# BUILD UPDATE (assigned_to only)
# =========================
$conn->begin_transaction();

try {
    if ($new_assigned_to <= 0) {
        $upd = $conn->prepare("UPDATE leads SET assigned_to=NULL WHERE id=?");
        $upd->bind_param("i", $lead_id);
    } else {
        $upd = $conn->prepare("UPDATE leads SET assigned_to=? WHERE id=?");
        $upd->bind_param("ii", $new_assigned_to, $lead_id);
    }

    if (!$upd->execute()) {
        throw new Exception("Assign update failed");
    }

    $conn->commit();

} catch (Exception $e) {
    $conn->rollback();
    fail(500, "Assign failed", ["error"=>$e->getMessage()]);
}

echo json_encode([
    "success" => true,
    "msg" => "Lead assigned",
    "lead_id" => $lead_id,
    "updated_assigned_to" => ($new_assigned_to <= 0 ? null : $new_assigned_to)
]);