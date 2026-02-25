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

$role  = $user['role'] ?? '';
$my_id = (int)($user['id'] ?? 0);

if (!in_array($role, ['admin','manager','sales'], true)) fail(403, "Access denied");
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail(405, "GET only");

/*
Optional GET filters:
- q (single search key)
- customer_id
- owner (admin/manager only)
- from, to (date filter on won_at: YYYY-MM-DD)
- page, limit
*/

$q = isset($_GET['q']) ? clean($_GET['q']) : '';
$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

$page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 20;
$offset = ($page - 1) * $limit;

$from = isset($_GET['from']) ? clean($_GET['from']) : '';
$to   = isset($_GET['to']) ? clean($_GET['to']) : '';

/* q validation */
if ($q !== '' && mb_strlen($q) > 100) fail(400, "q too long (max 100 chars)");

/* owner filter */
$ownerFilter = isset($_GET['owner']) ? (int)$_GET['owner'] : 0;
if ($role === 'sales') {
    $ownerFilter = $my_id; // sales only their own
}

/* validate dates (optional) */
if ($from !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $from);
    if (!$d || $d->format('Y-m-d') !== $from) fail(400, "Invalid from date (YYYY-MM-DD)");
}
if ($to !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $to);
    if (!$d || $d->format('Y-m-d') !== $to) fail(400, "Invalid to date (YYYY-MM-DD)");
}

$where = [];
$params = [];
$types = "";

if ($ownerFilter > 0) {
    $where[] = "owner = ?";
    $params[] = $ownerFilter;
    $types .= "i";
}

if ($customer_id > 0) {
    $where[] = "customer_id = ?";
    $params[] = $customer_id;
    $types .= "i";
}

/* ✅ q search (single search key)
   q=ragul / q=10000 / q=5 etc...
   searches in: title, stage, value, id, customer_id, original_deal_id
*/
if ($q !== '') {
    if (ctype_digit($q)) {
        $qi = (int)$q;

        $where[] = "(
            id = ? OR original_deal_id = ? OR customer_id = ? OR value = ?
            OR title LIKE ? OR stage LIKE ?
        )";
        $params[] = $qi; $params[] = $qi; $params[] = $qi; $params[] = $qi;
        $types   .= "iiii";

        $like = "%".$q."%";
        $params[] = $like; $params[] = $like;
        $types   .= "ss";
    } else {
        $where[] = "(title LIKE ? OR stage LIKE ?)";
        $like = "%".$q."%";
        $params[] = $like; $params[] = $like;
        $types   .= "ss";
    }
}

if ($from !== '') {
    $where[] = "DATE(won_at) >= ?";
    $params[] = $from;
    $types .= "s";
}
if ($to !== '') {
    $where[] = "DATE(won_at) <= ?";
    $params[] = $to;
    $types .= "s";
}

$whereSql = !empty($where) ? ("WHERE " . implode(" AND ", $where)) : "";

/* ================= TOTAL ================= */
$countSql = "SELECT COUNT(*) AS total FROM deals_won $whereSql";
$countStmt = $conn->prepare($countSql);
if (!$countStmt) fail(500, "Prepare failed", ["error"=>$conn->error]);

if (!empty($params)) $countStmt->bind_param($types, ...$params);
if (!$countStmt->execute()) fail(500, "Count query failed", ["error"=>$countStmt->error]);

$total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

/* ================= DATA ================= */
$dataSql = "
    SELECT id, original_deal_id, title, customer_id, value, stage, owner,
           expected_close_date, created_at, won_at
    FROM deals_won
    $whereSql
    ORDER BY won_at DESC, id DESC
    LIMIT ? OFFSET ?
";
$dataStmt = $conn->prepare($dataSql);
if (!$dataStmt) fail(500, "Prepare failed", ["error"=>$conn->error]);

$params2 = $params;
$types2  = $types . "ii";
$params2[] = $limit;
$params2[] = $offset;

$dataStmt->bind_param($types2, ...$params2);
if (!$dataStmt->execute()) fail(500, "Data query failed", ["error"=>$dataStmt->error]);

$rows = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$dataStmt->close();

/* optional casting */
foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['original_deal_id'] = $r['original_deal_id'] !== null ? (int)$r['original_deal_id'] : null;
    $r['customer_id'] = $r['customer_id'] !== null ? (int)$r['customer_id'] : null;
    $r['owner'] = $r['owner'] !== null ? (int)$r['owner'] : null;
    $r['value'] = $r['value'] !== null ? (float)$r['value'] : null;
}
unset($r);

echo json_encode([
    "success" => true,
    "msg" => "Deals won list",
    "page" => $page,
    "limit" => $limit,
    "total" => $total,
    "total_pages" => (int)ceil($total / $limit),
    "filters" => [
        "q" => $q !== '' ? $q : null,
        "customer_id" => $customer_id > 0 ? $customer_id : null,
        "owner" => $ownerFilter > 0 ? $ownerFilter : null,
        "from" => $from !== '' ? $from : null,
        "to" => $to !== '' ? $to : null
    ],
    "data" => $rows
]);