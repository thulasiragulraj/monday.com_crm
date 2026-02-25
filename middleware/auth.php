<?php

require_once __DIR__ . '/../config/jwt.php';

function get_authenticated_user() {

    $headers = getallheaders();

    if (!isset($headers['Authorization'])) {
        http_response_code(401);
        echo json_encode(["success"=>false,"msg"=>"Token missing"]);
        exit;
    }

    $token = str_replace("Bearer ", "", $headers['Authorization']);

    $user = verify_jwt($token);

    if (!$user) {
        http_response_code(401);
        echo json_encode(["success"=>false,"msg"=>"Invalid or expired token"]);
        exit;
    }

    return $user; // logged in user data
}