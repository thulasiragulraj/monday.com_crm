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
function clean($v){ return trim((string)$v); }

$user = get_authenticated_user();
if (!$user) fail(401, "Unauthorized");

$role = $user['role'] ?? '';
$my_id = (int)($user['id'] ?? 0);

if (!in_array($role, ['admin','manager','sales'], true)) {
    fail(403, "Access denied");
}
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
$value = array_key_exists('value', $input) ? (float)$input['value'] : null;
$expected_close_date = clean($input['expected_close_date'] ?? ''); // YYYY-MM-DD
$stage = clean($input['stage'] ?? 'prospect');
$title = clean($input['title'] ?? '');

if ($customer_id <= 0) fail(400, "customer_id required");

$allowedStages = ['prospect','negotiation','won','lost'];
if (!in_array($stage, $allowedStages, true)) {
    fail(400, "Invalid stage", ["allowed"=>$allowedStages]);
}

# validate date if provided
if ($expected_close_date !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $expected_close_date);
    if (!$d || $d->format('Y-m-d') !== $expected_close_date) {
        fail(400, "Invalid expected_close_date (use YYYY-MM-DD)");
    }
} else {
    $expected_close_date = null;
}

# Load customer (need assigned_to)
$c = $conn->prepare("SELECT id, name, assigned_to FROM customers WHERE id=? LIMIT 1");
$c->bind_param("i", $customer_id);
$c->execute();
$customer = $c->get_result()->fetch_assoc();
if (!$customer) fail(404, "Customer not found");

$customer_name = $customer['name'] ?? '';
$assigned_to = $customer['assigned_to'] !== null ? (int)$customer['assigned_to'] : null;

# default title = customer_name
if ($title === '') $title = $customer_name;

# Determine owner + enforce rules
$owner = null;

if ($role === 'sales') {

    # ✅ Sales must create only for their assigned customers
    if ($assigned_to === null) {
        fail(403, "This customer is not assigned to anyone. Ask admin/manager to assign first.");
    }
    if ($assigned_to !== $my_id) {
        fail(403, "You can't create deal for other sales person's customer.");
    }

    $owner = $my_id; // always self

} else {

    # ✅ Admin/Manager create rule:
    # - If customer assigned_to NOT NULL => owner MUST equal assigned_to (no confusion)
    # - If customer assigned_to NULL => owner required (sales user)
    if ($assigned_to !== null) {

        # optional: if they passed owner, must match
        if (isset($input['owner']) && (int)$input['owner'] !== $assigned_to) {
            fail(400, "Owner must match customer's assigned_to (to avoid confusion)", [
                "customer_assigned_to" => $assigned_to,
                "owner_given" => (int)$input['owner']
            ]);
        }

        $owner = $assigned_to; // ✅ force owner = assigned_to

    } else {

        $owner = isset($input['owner']) ? (int)$input['owner'] : 0;
        if ($owner <= 0) fail(400, "owner required (sales user id) because customer is unassigned");

        $u = $conn->prepare("SELECT id, role, name FROM users WHERE id=? LIMIT 1");
        $u->bind_param("i", $owner);
        $u->execute();
        $ownerUser = $u->get_result()->fetch_assoc();
        if (!$ownerUser) fail(404, "Owner user not found");
        if (($ownerUser['role'] ?? '') !== 'sales') fail(400, "Owner must be sales user");
    }
}

# Duplicate open deal check (same customer with open stages)
if (!in_array($stage, ['won','lost'], true)) {
    $dup = $conn->prepare("
        SELECT id FROM deals
        WHERE customer_id=? AND stage IN ('prospect','negotiation')
        LIMIT 1
    ");
    $dup->bind_param("i", $customer_id);
    $dup->execute();
    if ($dup->get_result()->num_rows > 0) {
        fail(409, "Open deal already exists for this customer");
    }
}

# Insert deal
$stmt = $conn->prepare("
    INSERT INTO deals (title, customer_id, value, stage, owner, expected_close_date)
    VALUES (?, ?, ?, ?, ?, ?)
");

$val = $value;                 // float or null
$stg = $stage;                 // string
$exp = $expected_close_date;   // string or null

# types: title(s), customer_id(i), value(d), stage(s), owner(i), expected_close_date(s)
$stmt->bind_param("sidsis", $title, $customer_id, $val, $stg, $owner, $exp);

if (!$stmt->execute()) fail(500, "Deal create failed");

echo json_encode([
    "success"=>true,
    "msg"=>"Deal created",
    "deal_id"=>(int)$stmt->insert_id,
    "title"=>$title,
    "customer_id"=>$customer_id,
    "owner"=>$owner,
    "stage"=>$stage,
    "customer_assigned_to"=>$assigned_to
]);