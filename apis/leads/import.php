<?php
header("Content-Type: application/json");

require '../../config/db.php';
require '../../config/jwt.php';
require '../../middleware/auth.php';

# =========================
# HELPERS
# =========================
function fail($code, $msg, $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge([
        "success"=>false,
        "msg"=>$msg
    ], $extra));
    exit;
}

function clean($v){
    return trim((string)$v);
}

# =========================
# AUTH (admin / manager only)
# =========================
$user = get_authenticated_user();

if (!$user || !in_array(($user['role'] ?? ''), ['admin','manager'], true)) {
    fail(403, "Access denied (admin/manager only)");
}

# =========================
# METHOD CHECK
# =========================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, "POST only");
}

# =========================
# FILE CHECK
# =========================
if (empty($_FILES['file']['tmp_name'])) {
    fail(400, "CSV file required (field name: file)");
}

$file = $_FILES['file']['tmp_name'];

if (($handle = fopen($file, "r")) === false) {
    fail(400, "Unable to read CSV");
}

# =========================
# READ HEADER
# =========================
$header = fgetcsv($handle);

if (!$header) {
    fail(400, "Invalid CSV header");
}

# expected columns
$expected = ['name','phone','email','source_id','product_id','message'];

$headerLower = array_map('strtolower', $header);

if ($headerLower !== $expected) {
    fail(400, "Invalid CSV columns", [
        "expected"=>$expected,
        "received"=>$headerLower
    ]);
}

# =========================
# PREPARE STATEMENT
# =========================
$status = "new";
$assigned_to = null;

$stmt = $conn->prepare("
    INSERT INTO leads
    (name, phone, email, source_id, product_id, message, status, assigned_to)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

if (!$stmt) {
    fail(500, "Prepare failed");
}

# =========================
# LOOP CSV
# =========================
$inserted = 0;
$skipped = 0;
$errors = [];

while (($row = fgetcsv($handle)) !== FALSE) {

    if (count($row) < 6) {
        $skipped++;
        continue;
    }

    $name       = clean($row[0]);
    $phone      = clean($row[1]);
    $email      = clean($row[2]);
    $source_id  = (int)$row[3];
    $product_id = (int)$row[4];
    $message    = clean($row[5]);

    # basic validation
    if ($name === '' || $phone === '' || $source_id <= 0 || $product_id <= 0) {
        $skipped++;
        $errors[] = [
            "name"=>$name,
            "reason"=>"required fields missing"
        ];
        continue;
    }

    # source check
    $srcCheck = $conn->prepare("SELECT id FROM lead_sources WHERE id=? LIMIT 1");
    $srcCheck->bind_param("i", $source_id);
    $srcCheck->execute();

    if ($srcCheck->get_result()->num_rows === 0) {
        $skipped++;
        $errors[] = [
            "name"=>$name,
            "reason"=>"invalid source_id"
        ];
        continue;
    }

    # product check
    $prodCheck = $conn->prepare("SELECT id FROM products WHERE id=? LIMIT 1");
    $prodCheck->bind_param("i", $product_id);
    $prodCheck->execute();

    if ($prodCheck->get_result()->num_rows === 0) {
        $skipped++;
        $errors[] = [
            "name"=>$name,
            "reason"=>"invalid product_id"
        ];
        continue;
    }

    # insert lead
    $stmt->bind_param(
        "sssisssi",
        $name,
        $phone,
        $email,
        $source_id,
        $product_id,
        $message,
        $status,
        $assigned_to
    );

    if ($stmt->execute()) {
        $inserted++;
    } else {
        $skipped++;
        $errors[] = [
            "name"=>$name,
            "reason"=>"db insert failed"
        ];
    }
}

fclose($handle);

# =========================
# RESPONSE
# =========================
echo json_encode([
    "success" => true,
    "msg" => "CSV import completed",
    "status_default" => "new",
    "inserted" => $inserted,
    "skipped" => $skipped,
    "errors" => $errors
]);