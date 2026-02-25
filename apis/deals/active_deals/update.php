<?php
header("Content-Type: application/json");

require '../../../config/db.php';
require '../../../config/jwt.php';
require '../../../middleware/auth.php';

function fail($code, $msg, $extra = []) {
    http_response_code($code);
    echo json_encode(array_merge(["success"=>false,"msg"=>$msg], $extra));
    exit;
}
function clean($v){ return trim((string)$v); }

$user = get_authenticated_user();
if (!$user) fail(401, "Unauthorized");

$role = $user['role'] ?? '';
$my_id = (int)($user['id'] ?? 0);

# ✅ Only SALES can update deals (as requested)
if ($role !== 'sales') fail(403, "Sales owner only");
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, "POST only");

# INPUT (POST/GET/JSON)
$input = $_POST;
if (!empty($_GET)) $input = array_merge($_GET, $input);
if (empty($input)) {
    $raw = file_get_contents("php://input");
    $json = json_decode($raw, true);
    if (is_array($json)) $input = $json;
}

$deal_id = isset($input['deal_id']) ? (int)$input['deal_id'] : 0;
if ($deal_id <= 0) fail(400, "deal_id required");

$title = array_key_exists('title', $input) ? clean($input['title']) : null;
$value = array_key_exists('value', $input) ? $input['value'] : null; // can be null/""/number
$stage = array_key_exists('stage', $input) ? clean($input['stage']) : null;
$expected_close_date = array_key_exists('expected_close_date', $input) ? clean($input['expected_close_date']) : null;

# optional: only for lost
$lost_reason = array_key_exists('lost_reason', $input) ? clean($input['lost_reason']) : null;

$allowedStages = ['prospect','negotiation','won','lost'];
if ($stage !== null && $stage !== '' && !in_array($stage, $allowedStages, true)) {
    fail(400, "Invalid stage", ["allowed"=>$allowedStages]);
}

# validate date if provided
if ($expected_close_date !== null && $expected_close_date !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $expected_close_date);
    if (!$d || $d->format('Y-m-d') !== $expected_close_date) {
        fail(400, "Invalid expected_close_date (use YYYY-MM-DD)");
    }
} elseif ($expected_close_date === '') {
    $expected_close_date = null;
}

# value normalize
if ($value === '') $value = null;
if ($value !== null && !is_numeric($value)) fail(400, "value must be number or null");
if ($value !== null) $value = (float)$value;

# 1) Load deal (and enforce owner)
$get = $conn->prepare("
    SELECT id, title, customer_id, value, stage, owner, expected_close_date, created_at
    FROM deals
    WHERE id=? AND owner=?
    LIMIT 1
");
$get->bind_param("ii", $deal_id, $my_id);
$get->execute();
$deal = $get->get_result()->fetch_assoc();

if (!$deal) fail(404, "Deal not found (or not your deal)");

# 2) Compute final fields
$finalTitle = ($title !== null) ? $title : ($deal['title'] ?? null);

# if value key exists, allow explicit null
if (array_key_exists('value', $input)) {
    $finalValue = $value; // float or null
} else {
    $finalValue = ($deal['value'] !== null) ? (float)$deal['value'] : null;
}

$finalStage = ($stage !== null && $stage !== '') ? $stage : ($deal['stage'] ?? 'prospect');

if (array_key_exists('expected_close_date', $input)) {
    $finalExp = $expected_close_date; // string or null
} else {
    $finalExp = ($deal['expected_close_date'] ?? null);
}

# helper to delete from deals safely
function delete_active_deal($conn, $deal_id, $my_id) {
    $del = $conn->prepare("DELETE FROM deals WHERE id=? AND owner=?");
    $del->bind_param("ii", $deal_id, $my_id);
    if (!$del->execute() || $del->affected_rows === 0) {
        throw new Exception("Delete from deals failed");
    }
}

# 3) SHIFT to WON
if ($finalStage === 'won') {

    $conn->begin_transaction();
    try {
        $ins = $conn->prepare("
            INSERT INTO deals_won
            (original_deal_id, title, customer_id, value, stage, owner, expected_close_date, created_at, won_at)
            VALUES (?, ?, ?, ?, 'won', ?, ?, ?, NOW())
        ");

        $orig_id = (int)$deal['id'];
        $cust_id = (int)$deal['customer_id'];
        $own_id  = (int)$deal['owner'];
        $created = $deal['created_at']; // timestamp string or null
        $v = $finalValue;

        # types: i s i d i s s
        $ins->bind_param("isidiss", $orig_id, $finalTitle, $cust_id, $v, $own_id, $finalExp, $created);

        if (!$ins->execute()) throw new Exception("Insert into deals_won failed");

        $won_id = (int)$ins->insert_id;

        delete_active_deal($conn, $deal_id, $my_id);

        $conn->commit();

        echo json_encode([
            "success" => true,
            "msg" => "Deal marked as WON and moved",
            "deal_id" => $deal_id,
            "moved_to" => "deals_won",
            "moved_id" => $won_id
        ]);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        fail(500, "WON shift failed", ["error"=>$e->getMessage()]);
    }
}

# 4) SHIFT to LOST
if ($finalStage === 'lost') {

    $conn->begin_transaction();
    try {
        $ins = $conn->prepare("
            INSERT INTO deals_lost
            (original_deal_id, title, customer_id, value, stage, owner, expected_close_date, created_at, lost_at, lost_reason)
            VALUES (?, ?, ?, ?, 'lost', ?, ?, ?, NOW(), ?)
        ");

        $orig_id = (int)$deal['id'];
        $cust_id = (int)$deal['customer_id'];
        $own_id  = (int)$deal['owner'];
        $created = $deal['created_at'];
        $v = $finalValue;
        $reason = ($lost_reason !== null && $lost_reason !== '') ? $lost_reason : null;

        # types: i s i d i s s s
        $ins->bind_param("isidisss", $orig_id, $finalTitle, $cust_id, $v, $own_id, $finalExp, $created, $reason);

        if (!$ins->execute()) throw new Exception("Insert into deals_lost failed");

        $lost_id = (int)$ins->insert_id;

        delete_active_deal($conn, $deal_id, $my_id);

        $conn->commit();

        echo json_encode([
            "success" => true,
            "msg" => "Deal marked as LOST and moved",
            "deal_id" => $deal_id,
            "moved_to" => "deals_lost",
            "moved_id" => $lost_id,
            "lost_reason" => $reason
        ]);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        fail(500, "LOST shift failed", ["error"=>$e->getMessage()]);
    }
}

# 5) Normal update (prospect/negotiation)
$upd = $conn->prepare("
    UPDATE deals
    SET title=?, value=?, stage=?, expected_close_date=?
    WHERE id=? AND owner=?
");
$upd->bind_param("sdssii", $finalTitle, $finalValue, $finalStage, $finalExp, $deal_id, $my_id);

if (!$upd->execute()) fail(500, "Update failed");

echo json_encode([
    "success" => true,
    "msg" => "Deal updated",
    "deal_id" => $deal_id,
    "moved_to" => null,
    "updated" => [
        "title" => $finalTitle,
        "value" => $finalValue,
        "stage" => $finalStage,
        "expected_close_date" => $finalExp
    ]
]);