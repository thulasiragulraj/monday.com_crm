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

# AUTH (admin/manager)
$user = get_authenticated_user();
if (!$user || !in_array(($user['role'] ?? ''), ['admin','manager'], true)) {
    fail(403, "Admin/Manager only");
}

/* ================= METHOD VALIDATION ================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, "Method not allowed. Use POST only.");
}

# INPUT (POST/GET/JSON)
$input = $_POST;
if (!empty($_GET)) $input = array_merge($_GET, $input);
if (empty($input)) {
    $raw = file_get_contents("php://input");
    $json = json_decode($raw, true);
    if (is_array($json)) $input = $json;
}

$customer_id = isset($input['customer_id']) ? (int)$input['customer_id'] : 0;
$sales_user_id = isset($input['sales_user_id']) ? (int)$input['sales_user_id'] : 0;

if ($customer_id <= 0) fail(400, "customer_id required");
if ($sales_user_id <= 0) fail(400, "sales_user_id required");

# Check customer exists
$c = $conn->prepare("SELECT id FROM customers WHERE id=? LIMIT 1");
$c->bind_param("i", $customer_id);
$c->execute();
if ($c->get_result()->num_rows === 0) fail(404, "Customer not found");

# Check sales user exists + role
$u = $conn->prepare("SELECT id, name, role FROM users WHERE id=? LIMIT 1");
$u->bind_param("i", $sales_user_id);
$u->execute();
$usr = $u->get_result()->fetch_assoc();
if (!$usr) fail(404, "User not found");
if (($usr['role'] ?? '') !== 'sales') fail(400, "Must assign to sales user only");

# Update assignment
$upd = $conn->prepare("UPDATE customers SET assigned_to=? WHERE id=?");
$upd->bind_param("ii", $sales_user_id, $customer_id);

if (!$upd->execute()) fail(500, "Assign failed");

echo json_encode([
    "success"=>true,
    "msg"=>"Customer assigned",
    "customer_id"=>$customer_id,
    "assigned_to"=>$sales_user_id,
    "assigned_user_name"=>$usr['name'] ?? null
]);