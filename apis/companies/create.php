<?php
header("Content-Type: application/json");

require_once '../../vendor/autoload.php';
require '../../config/db.php';
require '../../config/jwt.php';
require '../../middleware/auth.php';

$user = get_authenticated_user();

if (!$user) {
    http_response_code(401);
    echo json_encode(["success" => false, "msg" => "Unauthorized"]);
    exit;
}

/* 🔐 ROLE CHECK */
$allowed_roles = ['admin', 'manager', 'sales'];

if (!in_array($user['role'], $allowed_roles)) {
    http_response_code(403);
    echo json_encode(["success" => false, "msg" => "Access denied"]);
    exit;
}

# =========================
# 📥 IMPORT MODE (CSV)
# =========================
if (!empty($_FILES['file']['name'])) {

    $file = $_FILES['file']['tmp_name'];

    if (($handle = fopen($file, "r")) !== FALSE) {

        $row = 0;
        $inserted = 0;

        $stmt = $conn->prepare("
            INSERT INTO companies
            (name, email, phone, website, address, industry, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {

            if ($row == 0) { $row++; continue; } // skip header

            $name     = trim($data[0] ?? '');
            $email    = trim($data[1] ?? '');
            $phone    = trim($data[2] ?? '');
            $website  = trim($data[3] ?? '');
            $address  = trim($data[4] ?? '');
            $industry = trim($data[5] ?? '');

            if (!$name) continue;

            $stmt->bind_param(
                "ssssssi",
                $name,
                $email,
                $phone,
                $website,
                $address,
                $industry,
                $user['id']
            );

            if ($stmt->execute()) $inserted++;
        }

        fclose($handle);

        echo json_encode([
            "success" => true,
            "msg" => "Companies imported",
            "inserted" => $inserted
        ]);
        exit;
    }
}

# =========================
# ✍️ MANUAL CREATE MODE
# =========================

$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["success" => false, "msg" => "Invalid JSON"]);
    exit;
}

$name     = trim($data['name'] ?? '');
$email    = trim($data['email'] ?? '');
$phone    = trim($data['phone'] ?? '');
$website  = trim($data['website'] ?? '');
$address  = trim($data['address'] ?? '');
$industry = trim($data['industry'] ?? '');

if (!$name) {
    http_response_code(400);
    echo json_encode(["success" => false, "msg" => "Company name required"]);
    exit;
}

$stmt = $conn->prepare("
    INSERT INTO companies
    (name, email, phone, website, address, industry, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

$stmt->bind_param(
    "ssssssi",
    $name,
    $email,
    $phone,
    $website,
    $address,
    $industry,
    $user['id']
);

if ($stmt->execute()) {

    echo json_encode([
        "success" => true,
        "msg" => "Company created",
        "company_id" => $stmt->insert_id
    ]);

} else {
    http_response_code(500);
    echo json_encode(["success" => false, "msg" => "Insert failed"]);
}