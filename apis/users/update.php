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

function clean($v) {
    return trim((string)$v);
}

function normalizePhone($phone) {
    $phone = preg_replace('/\s+/', '', (string)$phone);
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    return $phone;
}

function isValidEmail($email) {
    if ($email === '') return false;
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
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

# =========================
# METHOD
# =========================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, "POST only");
}

# =========================
# INPUT (POST / GET / JSON)
# =========================
$input = $_POST;
if (!empty($_GET)) $input = array_merge($_GET, $input);

if (empty($input)) {
    $raw = file_get_contents("php://input");
    $json = json_decode($raw, true);
    if (is_array($json)) $input = $json;
}

# target user (admin/manager can pass user_id, sales cannot)
$target_id = isset($input['user_id']) ? (int)$input['user_id'] : 0;
if ($role === 'sales') {
    $target_id = $my_id; // sales can only update self
} else {
    if ($target_id <= 0) $target_id = $my_id; // optional: default self
}

# =========================
# LOAD TARGET USER
# =========================
$get = $conn->prepare("SELECT id, name, email, phone, role, created_at FROM users WHERE id=? LIMIT 1");
$get->bind_param("i", $target_id);
$get->execute();
$target = $get->get_result()->fetch_assoc();

if (!$target) fail(404, "User not found");

# manager restriction (optional safety):
# manager should not update admin users (common CRM rule)
if ($role === 'manager' && ($target['role'] ?? '') === 'admin') {
    fail(403, "Manager cannot update admin user");
}

# =========================
# INPUT FIELDS (optional)
# =========================
$new_name  = array_key_exists('name', $input) ? clean($input['name']) : null;
$new_phone = array_key_exists('phone', $input) ? normalizePhone($input['phone']) : null;
$new_email = array_key_exists('email', $input) ? clean($input['email']) : null;

$new_role  = array_key_exists('role', $input) ? clean($input['role']) : null;     // admin only
$new_pass  = array_key_exists('password', $input) ? (string)$input['password'] : null;

# validate email if provided
if ($new_email !== null) {
    if (!isValidEmail($new_email)) fail(400, "Invalid email");
}

# validate phone (if provided)
if ($new_phone !== null && $new_phone !== '' && strlen(preg_replace('/\D/','',$new_phone)) < 8) {
    fail(400, "Invalid phone");
}

# role update rules
$allowedRoles = ['admin','manager','sales'];
if ($new_role !== null) {
    if ($role !== 'admin') fail(403, "Only admin can change user role");
    if (!in_array($new_role, $allowedRoles, true)) fail(400, "Invalid role");
}

# password validation if provided
if ($new_pass !== null) {
    $new_pass = trim($new_pass);
    if ($new_pass !== '' && strlen($new_pass) < 6) {
        fail(400, "Password must be at least 6 characters");
    }
}

# =========================
# DUPLICATE EMAIL CHECK
# =========================
if ($new_email !== null && $new_email !== '') {
    $dup = $conn->prepare("SELECT id FROM users WHERE email=? AND id<>? LIMIT 1");
    $dup->bind_param("si", $new_email, $target_id);
    $dup->execute();
    if ($dup->get_result()->num_rows > 0) {
        fail(409, "Duplicate email already exists");
    }
}

# =========================
# BUILD UPDATE DYNAMIC
# =========================
$fields = [];
$params = [];
$types  = "";

if ($new_name !== null)  { $fields[] = "name=?";  $params[] = $new_name;  $types .= "s"; }
if ($new_phone !== null) { $fields[] = "phone=?"; $params[] = $new_phone; $types .= "s"; }
if ($new_email !== null) { $fields[] = "email=?"; $params[] = $new_email; $types .= "s"; }

if ($new_role !== null)  { $fields[] = "role=?";  $params[] = $new_role;  $types .= "s"; }

if ($new_pass !== null && trim($new_pass) !== '') {
    $hashed = password_hash($new_pass, PASSWORD_BCRYPT);
    $fields[] = "password=?";
    $params[] = $hashed;
    $types .= "s";
}

if (empty($fields)) {
    echo json_encode(["success"=>true, "msg"=>"Nothing to update", "user_id"=>$target_id]);
    exit;
}

$params[] = $target_id;
$types .= "i";

$sql = "UPDATE users SET " . implode(", ", $fields) . " WHERE id=?";

$stmt = $conn->prepare($sql);
if (!$stmt) fail(500, "Prepare failed");

$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
    fail(500, "Update failed");
}

# =========================
# FETCH UPDATED USER (safe fields only)
# =========================
$get2 = $conn->prepare("SELECT id, name, email, phone, role, created_at FROM users WHERE id=? LIMIT 1");
$get2->bind_param("i", $target_id);
$get2->execute();
$updated = $get2->get_result()->fetch_assoc();
$updated['id'] = (int)$updated['id'];

echo json_encode([
    "success" => true,
    "msg" => "User updated",
    "updated_user" => $updated,
    "updated_by" => [
        "id" => $my_id,
        "role" => $role
    ]
]);