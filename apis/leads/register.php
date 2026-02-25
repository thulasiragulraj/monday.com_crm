<?php
header("Content-Type: application/json");
require '../../config/db.php';

# =========================
# HELPERS
# =========================
function fail($code, $msg, $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge(["success"=>false,"msg"=>$msg], $extra));
    exit;
}

function clean($v) {
    return trim((string)$v);
}

function isValidEmail($email) {
    if ($email === '') return true; // optional
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function normalizePhone($phone) {
    $phone = preg_replace('/\s+/', '', $phone);
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    return $phone;
}

# =========================
# METHOD CHECK
# =========================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, "POST only");
}

# =========================
# READ INPUT (JSON or form-data)
# =========================
$input = $_POST;

if (empty($input)) {
    $raw = file_get_contents("php://input");
    $json = json_decode($raw, true);
    if (is_array($json)) $input = $json;
}

$name        = clean($input['name'] ?? '');
$phone       = normalizePhone($input['phone'] ?? '');
$email       = clean($input['email'] ?? '');
$message     = clean($input['message'] ?? '');

$product_id  = isset($input['product_id']) ? (int)$input['product_id'] : 0;

$source_id   = isset($input['source_id']) ? (int)$input['source_id'] : 0;
$source_name = clean($input['source_name'] ?? '');

# =========================
# VALIDATION
# =========================
if ($name === '') fail(400, "name required");
if ($phone === '' || strlen(preg_replace('/\D/','',$phone)) < 8) fail(400, "valid phone required");
if (!isValidEmail($email)) fail(400, "invalid email");

if ($product_id <= 0) fail(400, "product_id required");

if ($source_id <= 0 && $source_name === '') {
    fail(400, "source_id or source_name required");
}

# =========================
# VALIDATE PRODUCT EXISTS + PUBLIC
# =========================
$pstmt = $conn->prepare("SELECT id, is_public FROM products WHERE id=? LIMIT 1");
$pstmt->bind_param("i", $product_id);
$pstmt->execute();
$prow = $pstmt->get_result()->fetch_assoc();

if (!$prow) fail(404, "Product not found");

# optional: only allow public products from website registration
if ((int)$prow['is_public'] !== 1) {
    fail(403, "Product not available for public registration");
}

# =========================
# RESOLVE SOURCE
# - If source_id provided -> validate active
# - Else source_name -> find active by name (case-insensitive), else create (active)
# =========================
if ($source_id > 0) {

    $sstmt = $conn->prepare("SELECT id, status FROM lead_sources WHERE id=? LIMIT 1");
    $sstmt->bind_param("i", $source_id);
    $sstmt->execute();
    $srow = $sstmt->get_result()->fetch_assoc();

    if (!$srow) fail(404, "Source not found");
    if (($srow['status'] ?? '') !== 'active') fail(403, "Source inactive");
    $resolved_source_id = (int)$srow['id'];

} else {

    // find by name (active)
    $sstmt = $conn->prepare("SELECT id, status FROM lead_sources WHERE LOWER(name)=LOWER(?) LIMIT 1");
    $sstmt->bind_param("s", $source_name);
    $sstmt->execute();
    $srow = $sstmt->get_result()->fetch_assoc();

    if ($srow) {
        if (($srow['status'] ?? '') !== 'active') fail(403, "Source inactive");
        $resolved_source_id = (int)$srow['id'];
    } else {
        // create new source as active
        $desc = "Auto-created from public lead register";
        $status = "active";
        $ins = $conn->prepare("INSERT INTO lead_sources (name, description, status) VALUES (?, ?, ?)");
        $ins->bind_param("sss", $source_name, $desc, $status);
        if (!$ins->execute()) fail(500, "Unable to create source");
        $resolved_source_id = (int)$ins->insert_id;
    }
}

# =========================
# DUPLICATE CHECK (optional but recommended)
# Same phone + product in last 30 days -> reject
# =========================
$dup = $conn->prepare("
    SELECT id FROM leads
    WHERE phone=? AND product_id=? AND created_at >= (NOW() - INTERVAL 30 DAY)
    LIMIT 1
");
$dup->bind_param("si", $phone, $product_id);
$dup->execute();

if ($dup->get_result()->num_rows > 0) {
    fail(409, "Lead already registered recently for this product");
}

# =========================
# INSERT LEAD
# status = new
# assigned_to = NULL (admin later assign)
# =========================
$status = "new";
$assigned_to = null;

$stmt = $conn->prepare("
    INSERT INTO leads
    (name, phone, email, source_id, product_id, message, status, assigned_to)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

$stmt->bind_param(
    "sssisssi",
    $name,
    $phone,
    $email,
    $resolved_source_id,
    $product_id,
    $message,
    $status,
    $assigned_to
);

if (!$stmt->execute()) {
    fail(500, "Lead register failed");
}

$lead_id = (int)$stmt->insert_id;

echo json_encode([
    "success" => true,
    "msg" => "Lead registered",
    "lead_id" => $lead_id,
    "source_id" => $resolved_source_id,
    "product_id" => $product_id,
    "status" => $status
]);