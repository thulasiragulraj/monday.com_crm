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

$customer_id = isset($input['customer_id']) ? (int)$input['customer_id'] : 0;
$type = clean($input['type'] ?? '');
$notes = clean($input['notes'] ?? '');
$next_followup_date = clean($input['next_followup_date'] ?? '');
$status = clean($input['status'] ?? 'pending'); // default

if ($customer_id <= 0) fail(400, "customer_id required");
if ($type === '') fail(400, "type required");
if ($next_followup_date === '') fail(400, "next_followup_date required");

$allowedTypes = ['call','whatsapp','visit','email'];
if (!in_array($type, $allowedTypes, true)) {
    fail(400, "Invalid type", ["allowed"=>$allowedTypes]);
}

$allowedStatus = ['pending','done','cancelled'];
if (!in_array($status, $allowedStatus, true)) {
    fail(400, "Invalid status", ["allowed"=>$allowedStatus]);
}

# validate datetime (YYYY-MM-DD HH:MM:SS) OR allow YYYY-MM-DD
$dt = DateTime::createFromFormat('Y-m-d H:i:s', $next_followup_date);
if (!$dt || $dt->format('Y-m-d H:i:s') !== $next_followup_date) {
    $d2 = DateTime::createFromFormat('Y-m-d', $next_followup_date);
    if (!$d2 || $d2->format('Y-m-d') !== $next_followup_date) {
        fail(400, "Invalid next_followup_date (use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS)");
    }
    # if only date given -> set time 00:00:00
    $next_followup_date = $d2->format('Y-m-d') . " 00:00:00";
}

# Load customer (check assigned_to)
$c = $conn->prepare("SELECT id, assigned_to FROM customers WHERE id=? LIMIT 1");
if (!$c) fail(500, "Prepare failed", ["error"=>$conn->error]);
$c->bind_param("i", $customer_id);
$c->execute();
$customer = $c->get_result()->fetch_assoc();
if (!$customer) fail(404, "Customer not found");

$customer_assigned_to = ($customer['assigned_to'] !== null) ? (int)$customer['assigned_to'] : null;

# Determine employee_id
$employee_id = null;

if ($role === 'sales') {
    # sales: only own assigned customers, employee_id always me
    if ($customer_assigned_to === null) {
        fail(403, "This customer is not assigned. Ask admin/manager to assign first.");
    }
    if ($customer_assigned_to !== $my_id) {
        fail(403, "Sales can create followups only for assigned customers");
    }
    $employee_id = $my_id;

} else {
    # admin/manager:
    # if customer assigned_to exists -> employee_id forced to assigned_to (clean workflow)
    # else -> employee_id required (must be sales user)
    if ($customer_assigned_to !== null) {
        if (isset($input['employee_id']) && (int)$input['employee_id'] !== $customer_assigned_to) {
            fail(400, "employee_id must match customer's assigned_to", [
                "customer_assigned_to" => $customer_assigned_to,
                "employee_id_given" => (int)$input['employee_id']
            ]);
        }
        $employee_id = $customer_assigned_to;
    } else {
        $employee_id = isset($input['employee_id']) ? (int)$input['employee_id'] : 0;
        if ($employee_id <= 0) fail(400, "employee_id required because customer is unassigned");

        $u = $conn->prepare("SELECT id, role FROM users WHERE id=? LIMIT 1");
        if (!$u) fail(500, "Prepare failed", ["error"=>$conn->error]);
        $u->bind_param("i", $employee_id);
        $u->execute();
        $uu = $u->get_result()->fetch_assoc();
        if (!$uu) fail(404, "employee_id user not found");
        if (($uu['role'] ?? '') !== 'sales') fail(400, "employee_id must be a sales user");
    }
}

# Insert followup
$ins = $conn->prepare("
    INSERT INTO followups (customer_id, employee_id, type, notes, next_followup_date, status)
    VALUES (?, ?, ?, ?, ?, ?)
");
if (!$ins) fail(500, "Prepare failed", ["error"=>$conn->error]);

$ins->bind_param("iissss", $customer_id, $employee_id, $type, $notes, $next_followup_date, $status);

if (!$ins->execute()) {
    fail(500, "Followup create failed", ["error"=>$conn->error]);
}

echo json_encode([
    "success" => true,
    "msg" => "Followup created",
    "followup_id" => (int)$ins->insert_id,
    "customer_id" => $customer_id,
    "employee_id" => $employee_id,
    "status" => $status
]);