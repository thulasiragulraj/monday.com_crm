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

$user = get_authenticated_user();
if (!$user) fail(401, "Unauthorized");

$role  = $user['role'] ?? '';
$my_id = (int)($user['id'] ?? 0);

if (!in_array($role, ['admin','manager','sales'], true)) {
    fail(403, "Access denied");
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    fail(405, "GET only");
}

/*
 Optional filters (GET):
 - q (search in name/phone/email)
 - assigned_to (admin/manager only)
 - source_id
 - product_id
 - limit, page
*/
$q          = isset($_GET['q']) ? clean($_GET['q']) : '';
$source_id  = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;

$page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 20;
$offset = ($page - 1) * $limit;

$filter_assigned_to = isset($_GET['assigned_to']) ? (int)$_GET['assigned_to'] : 0;
if ($role === 'sales') {
    $filter_assigned_to = $my_id; // sales only self
} else {
    // admin/manager: allow assigned_to filter
    // if not provided -> show all
    if ($filter_assigned_to <= 0) $filter_assigned_to = 0;
}

$where = [];
$params = [];
$types = "";

if ($filter_assigned_to > 0) {
    $where[] = "assigned_to = ?";
    $params[] = $filter_assigned_to;
    $types .= "i";
}

if ($source_id > 0) {
    $where[] = "source_id = ?";
    $params[] = $source_id;
    $types .= "i";
}

if ($product_id > 0) {
    $where[] = "product_id = ?";
    $params[] = $product_id;
    $types .= "i";
}

if ($q !== '') {
    $where[] = "(name LIKE ? OR phone LIKE ? OR email LIKE ?)";
    $like = "%".$q."%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= "sss";
}

$whereSql = "";
if (!empty($where)) $whereSql = "WHERE " . implode(" AND ", $where);

# -------- total count ----------
$countSql = "SELECT COUNT(*) AS total FROM leads_lost $whereSql";
$countStmt = $conn->prepare($countSql);
if (!$countStmt) fail(500, "Prepare failed", ["error"=>$conn->error]);

if (!empty($params)) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);

# -------- data ----------
$dataSql = "
    SELECT id, original_lead_id, name, phone, email, source_id, product_id,
           message, status, assigned_to, moved_at
    FROM leads_lost
    $whereSql
    ORDER BY moved_at DESC, id DESC
    LIMIT ? OFFSET ?
";

$dataStmt = $conn->prepare($dataSql);
if (!$dataStmt) fail(500, "Prepare failed", ["error"=>$conn->error]);

$params2 = $params;
$types2  = $types . "ii";
$params2[] = $limit;
$params2[] = $offset;

$dataStmt->bind_param($types2, ...$params2);
$dataStmt->execute();
$rows = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo json_encode([
    "success" => true,
    "msg" => "Leads lost list",
    "page" => $page,
    "limit" => $limit,
    "total" => $total,
    "data" => $rows
]);