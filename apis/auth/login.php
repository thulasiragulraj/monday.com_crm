<?php
header("Content-Type: application/json");

/* ✅ METHOD VALIDATION (POST only) */
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
require '../../config/jwt.php';

/*
Expected JSON:
{
  "email": "admin@mail.com",
  "password": "Ragul@123"
}
*/

$data = json_decode(file_get_contents("php://input"));

$email    = trim($data->email ?? '');
$password = $data->password ?? '';

# 🔎 REQUIRED CHECK
if (!$email || !$password) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "msg" => "Email and password required"
    ]);
    exit;
}

# 📧 EMAIL FORMAT CHECK
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "msg" => "Invalid email format"
    ]);
    exit;
}

# 🔍 FIND USER
$stmt = $conn->prepare("SELECT id, name, email, password, role FROM users WHERE email=?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if (!$user = $result->fetch_assoc()) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "msg" => "Invalid email or password"
    ]);
    exit;
}

# 🔐 VERIFY PASSWORD
if (!password_verify($password, $user['password'])) {
    http_response_code(401);
    echo json_encode([
        "success" => false,
        "msg" => "Invalid email or password"
    ]);
    exit;
}

# 🎟️ GENERATE JWT TOKEN
$token = generate_jwt($user);

# ✅ SUCCESS RESPONSE
echo json_encode([
    "success" => true,
    "msg" => "Login successful",
    "token" => $token,
    "user" => [
        "id" => $user['id'],
        "name" => $user['name'],
        "email" => $user['email'],
        "role" => $user['role']
    ]
]);