<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

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
require '../../config/jwt.php'; // ✅ your jwt.php has verify_jwt()

/*
Logout Steps:
1. Get token from Authorization header
2. Verify token (verify_jwt)
3. Return success (Client must delete token)
*/

function fail($code, $msg) {
    http_response_code($code);
    echo json_encode(["success" => false, "msg" => $msg]);
    exit;
}

/* ✅ GET AUTH HEADER (works even if server returns lowercase keys) */
$headers = function_exists('getallheaders') ? getallheaders() : [];

$authHeader = $headers['Authorization']
    ?? $headers['authorization']
    ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

if (!$authHeader) {
    fail(401, "Authorization header missing");
}

/* ✅ FORMAT: Bearer <token> */
if (stripos($authHeader, 'Bearer ') !== 0) {
    fail(401, "Invalid Authorization format. Use: Bearer <token>");
}

$token = trim(substr($authHeader, 7));
if ($token === '') {
    fail(401, "Token missing");
}

/* ✅ VERIFY TOKEN using your function */
$userData = verify_jwt($token);

if (!$userData) {
    fail(401, "Invalid or expired token");
}

/* ✅ Logout success (client must delete token) */
echo json_encode([
    "success" => true,
    "msg" => "Logged out successfully",
    "user" => $userData // optional: remove if you don't want to send user data back
]);