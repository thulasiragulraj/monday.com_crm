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

# AUTH (admin/manager)
$user = get_authenticated_user();
if (!$user || !in_array(($user['role'] ?? ''), ['admin','manager'], true)) {
    fail(403, "Admin/Manager only");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, "POST only");
}

if (empty($_FILES['file']['tmp_name'])) {
    fail(400, "CSV file required (field name: file)");
}

$handle = fopen($_FILES['file']['tmp_name'], "r");
if (!$handle) fail(400, "Unable to read CSV");

$header = fgetcsv($handle);
if (!$header) fail(400, "Invalid CSV header");

$expected = ['name','phone','email','source_id','created_from_lead_id'];
$headerLower = array_map('strtolower', $header);

if ($headerLower !== $expected) {
    fail(400, "Invalid CSV columns", ["expected"=>$expected, "received"=>$headerLower]);
}

$inserted = 0;
$skipped = 0;
$errors = [];

$assigned_to = null; // ✅ always NULL

$stmt = $conn->prepare("
    INSERT INTO customers
    (name, phone, email, source_id, created_from_lead_id, assigned_to)
    VALUES (?, ?, ?, ?, ?, ?)
");

while (($row = fgetcsv($handle)) !== false) {

    $name = clean($row[0] ?? '');
    $phone = normalizePhone($row[1] ?? '');
    $email = clean($row[2] ?? '');
    $source_id = (int)($row[3] ?? 0);
    $created_from_lead_id = (int)($row[4] ?? 0);

    if ($name === '') { $skipped++; continue; }

    # validate source_id optional
    if ($source_id > 0) {
        $s = $conn->prepare("SELECT id FROM lead_sources WHERE id=? LIMIT 1");
        $s->bind_param("i", $source_id);
        $s->execute();
        if ($s->get_result()->num_rows === 0) $source_id = null;
    } else $source_id = null;

    # validate lead_id optional
    if ($created_from_lead_id > 0) {
        $l = $conn->prepare("SELECT id FROM leads WHERE id=? LIMIT 1");
        $l->bind_param("i", $created_from_lead_id);
        $l->execute();
        if ($l->get_result()->num_rows === 0) $created_from_lead_id = null;
    } else $created_from_lead_id = null;

    # duplicate check phone/email
    if ($phone !== '') {
        $dupP = $conn->prepare("SELECT id FROM customers WHERE phone=? LIMIT 1");
        $dupP->bind_param("s", $phone);
        $dupP->execute();
        if ($dupP->get_result()->num_rows > 0) { $skipped++; continue; }
    }
    if ($email !== '') {
        $dupE = $conn->prepare("SELECT id FROM customers WHERE email=? LIMIT 1");
        $dupE->bind_param("s", $email);
        $dupE->execute();
        if ($dupE->get_result()->num_rows > 0) { $skipped++; continue; }
    }

    $stmt->bind_param("sssiii",
        $name,
        $phone,
        $email,
        $source_id,
        $created_from_lead_id,
        $assigned_to
    );

    if ($stmt->execute()) $inserted++;
    else {
        $skipped++;
        $errors[] = ["name"=>$name, "reason"=>"db insert failed"];
    }
}

fclose($handle);

echo json_encode([
    "success"=>true,
    "msg"=>"Customers CSV imported (assigned_to NULL)",
    "inserted"=>$inserted,
    "skipped"=>$skipped,
    "errors"=>$errors
]);