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
if (!$user || !in_array(($user['role'] ?? ''), ['admin','manager'], true)) {
    fail(403, "Admin/Manager only");
}

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    fail(405, "DELETE only");
}

$input = $_POST;
if (!empty($_GET)) $input = array_merge($_GET, $input);
if (empty($input)) {
    $raw = file_get_contents("php://input");
    $json = json_decode($raw, true);
    if (is_array($json)) $input = $json;
}

$customer_id = isset($input['customer_id']) ? (int)$input['customer_id'] : 0;
if ($customer_id <= 0) fail(400, "customer_id required");

# check exists
$c = $conn->prepare("SELECT id FROM customers WHERE id=? LIMIT 1");
$c->bind_param("i", $customer_id);
$c->execute();
if ($c->get_result()->num_rows === 0) fail(404, "Customer not found");

# delete
$d = $conn->prepare("DELETE FROM customers WHERE id=?");
$d->bind_param("i", $customer_id);

if (!$d->execute()) fail(500, "Delete failed");

echo json_encode([
    "success"=>true,
    "msg"=>"Customer deleted",
    "customer_id"=>$customer_id
]);