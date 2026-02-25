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
function normalizePhone($phone) {
    $phone = preg_replace('/\s+/', '', (string)$phone);
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    return $phone;
}
function isValidEmail($email){
    if ($email === '') return true;
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

# AUTH (admin/manager)
$user = get_authenticated_user();
if (!$user || !in_array(($user['role'] ?? ''), ['admin','manager'], true)) {
    fail(403, "Admin/Manager only");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, "POST only");
}

# INPUT (POST/JSON)
$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents("php://input");
    $json = json_decode($raw, true);
    if (is_array($json)) $input = $json;
}

$name  = clean($input['name'] ?? '');
$phone = normalizePhone($input['phone'] ?? '');
$email = clean($input['email'] ?? '');

$source_id = isset($input['source_id']) ? (int)$input['source_id'] : null;
$created_from_lead_id = isset($input['created_from_lead_id']) ? (int)$input['created_from_lead_id'] : null;

# assigned_to must be NULL (as requested)
$assigned_to = null;

if ($name === '') fail(400, "name required");
if (!isValidEmail($email)) fail(400, "Invalid email");

# Validate source_id if provided
if ($source_id !== null && $source_id > 0) {
    $s = $conn->prepare("SELECT id FROM lead_sources WHERE id=? LIMIT 1");
    $s->bind_param("i", $source_id);
    $s->execute();
    if ($s->get_result()->num_rows === 0) fail(400, "Invalid source_id");
} else {
    $source_id = null;
}

# Validate created_from_lead_id if provided (optional)
if ($created_from_lead_id !== null && $created_from_lead_id > 0) {
    $l = $conn->prepare("SELECT id FROM leads WHERE id=? LIMIT 1");
    $l->bind_param("i", $created_from_lead_id);
    $l->execute();
    if ($l->get_result()->num_rows === 0) fail(400, "Invalid created_from_lead_id");
} else {
    $created_from_lead_id = null;
}

# Duplicate check (phone/email)
if ($phone !== '') {
    $dupP = $conn->prepare("SELECT id FROM customers WHERE phone=? LIMIT 1");
    $dupP->bind_param("s", $phone);
    $dupP->execute();
    if ($dupP->get_result()->num_rows > 0) fail(409, "Customer phone already exists");
}
if ($email !== '') {
    $dupE = $conn->prepare("SELECT id FROM customers WHERE email=? LIMIT 1");
    $dupE->bind_param("s", $email);
    $dupE->execute();
    if ($dupE->get_result()->num_rows > 0) fail(409, "Customer email already exists");
}

# Insert
$stmt = $conn->prepare("
    INSERT INTO customers
    (name, phone, email, source_id, created_from_lead_id, assigned_to)
    VALUES (?, ?, ?, ?, ?, ?)
");

$stmt->bind_param("sssiii",
    $name,
    $phone,
    $email,
    $source_id,
    $created_from_lead_id,
    $assigned_to
);

if (!$stmt->execute()) fail(500, "Customer create failed");

echo json_encode([
    "success"=>true,
    "msg"=>"Customer created (assigned_to NULL)",
    "customer_id"=>(int)$stmt->insert_id
]);