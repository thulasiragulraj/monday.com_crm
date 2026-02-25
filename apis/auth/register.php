<?php
header("Content-Type: application/json");

/* ================== METHOD VALIDATION ================== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "msg" => "Method not allowed. Use POST."
    ]);
    exit;
}

require_once '../../vendor/autoload.php';
require '../../config/db.php';

/*
Expected JSON:
{
  "name": "Ragul",
  "email": "ragul@mail.com",
  "phone": "9876543210",
  "password": "Ragul@123",
  "role": "admin"
}
*/

function fail($code, $msg, $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge([
        "success" => false,
        "msg" => $msg
    ], $extra));
    exit;
}

/* ================== READ JSON ================== */
$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) fail(400, "Invalid JSON");

/* ================== INPUT ================== */
$name     = trim((string)($data['name'] ?? ''));
$email    = trim((string)($data['email'] ?? ''));
$phone    = trim((string)($data['phone'] ?? ''));
$password = (string)($data['password'] ?? '');
$role     = trim((string)($data['role'] ?? 'sales'));

/* ================== REQUIRED FIELDS ================== */
$errors = [];
if ($name === '')     $errors['name'] = "name is required";
if ($email === '')    $errors['email'] = "email is required";
if ($phone === '')    $errors['phone'] = "phone is required";
if ($password === '') $errors['password'] = "password is required";

if (!empty($errors)) {
    fail(400, "All fields required", ["errors" => $errors]);
}

/* ================== EMAIL VALIDATION ================== */
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail(400, "Invalid email format");
}

/* ================== PHONE VALIDATION ================== */
if (!preg_match('/^[6-9]\d{9}$/', $phone)) {
    fail(400, "Invalid phone number (must be 10 digits, start 6-9)");
}

/* ================== PASSWORD VALIDATION ================== */
if (
    strlen($password) < 8 ||
    !preg_match('/[A-Z]/', $password) ||
    !preg_match('/[a-z]/', $password) ||
    !preg_match('/[0-9]/', $password) ||
    !preg_match('/[\W]/', $password)
) {
    fail(400, "Password must be 8+ chars with uppercase, lowercase, number, special char");
}

/* ================== ROLE VALIDATION ================== */
$allowed_roles = ['admin', 'manager', 'sales'];
if (!in_array($role, $allowed_roles, true)) {
    fail(400, "Invalid role");
}

/* ================== EMAIL EXISTS CHECK ================== */
$stmt = $conn->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
if (!$stmt) fail(500, "Prepare failed", ["error"=>$conn->error]);

$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $stmt->close();
    fail(409, "Email already registered");
}
$stmt->close();

/* ================== 📱 MOBILE EXISTS CHECK (NEW) ================== */
$stmt = $conn->prepare("SELECT id FROM users WHERE phone=? LIMIT 1");
if (!$stmt) fail(500, "Prepare failed", ["error"=>$conn->error]);

$stmt->bind_param("s", $phone);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $stmt->close();
    fail(409, "Mobile number already registered");
}
$stmt->close();

/* ================== HASH PASSWORD ================== */
$hashed_password = password_hash($password, PASSWORD_BCRYPT);

/* ================== INSERT USER ================== */
$stmt = $conn->prepare("
    INSERT INTO users (name, email, phone, password, role)
    VALUES (?, ?, ?, ?, ?)
");

if (!$stmt) fail(500, "Prepare failed", ["error"=>$conn->error]);

$stmt->bind_param("sssss", $name, $email, $phone, $hashed_password, $role);

if ($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "msg" => "User registered successfully",
        "user_id" => $stmt->insert_id
    ]);
} else {
    fail(500, "Registration failed", ["error"=>$stmt->error]);
}

$stmt->close();