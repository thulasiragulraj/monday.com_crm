<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = "THIS_IS_MY_SUPER_SECRET_KEY_123456789"; 
$algo = "HS256";

function generate_jwt($user) {
    global $secret_key, $algo;

    $payload = [
        "iss" => "localhost",
        "aud" => "localhost",
        "iat" => time(),
        "exp" => time() + (60 * 60 * 24), // 1 day
        "data" => $user
    ];

    return JWT::encode($payload, $secret_key, $algo);
}

function verify_jwt($token) {
    global $secret_key, $algo;

    try {
        $decoded = JWT::decode($token, new Key($secret_key, $algo));
        return (array) $decoded->data;
    } catch (Exception $e) {
        return false;
    }
}