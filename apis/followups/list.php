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

if (!in_array($role, ['admin','manager','sales'], true)) fail(403, "Access denied");
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail(405, "GET only");

/*
✅ Added:
- q (single search key) -> searches in type, notes, status, customer_id, employee_id, id
*/

$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$status = clean($_GET['status'] ?? '');
$when = clean($_GET['when'] ?? ''); // today/overdue/upcoming
$from = clean($_GET['from'] ?? '');
$to   = clean($_GET['to'] ?? '');
$q    = clean($_GET['q'] ?? '');

$page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 20;
$offset = ($page - 1) * $limit;

/* q validation */
if ($q !== '' && mb_strlen($q) > 100) fail(400, "q too long (max 100 chars)");

$allowedStatus = ['pending','done','cancelled'];
if ($status !== '' && !in_array($status, $allowedStatus, true)) {
    fail(400, "Invalid status", ["allowed"=>$allowedStatus]);
}

$allowedWhen = ['today','overdue','upcoming'];
if ($when !== '' && !in_array($when, $allowedWhen, true)) {
    fail(400, "Invalid when", ["allowed"=>$allowedWhen]);
}

/* validate from/to dates (optional) */
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

/* sales restriction */
if ($role === 'sales') {
    $where[] = "employee_id = ?";
    $params[] = $my_id;
    $types .= "i";
}

if ($customer_id > 0) {
    $where[] = "customer_id = ?";
    $params[] = $customer_id;
    $types .= "i";
}

if ($status !== '') {
    $where[] = "status = ?";
    $params[] = $status;
    $types .= "s";
}

/* ✅ q search (single key) */
if ($q !== '') {
    if (ctype_digit($q)) {
        $qi = (int)$q;
        $where[] = "(
            id = ? OR customer_id = ? OR employee_id = ?
            OR type LIKE ? OR notes LIKE ? OR status LIKE ?
        )";
        $params[] = $qi; $params[] = $qi; $params[] = $qi;
        $types   .= "iii";

        $like = "%".$q."%";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types   .= "sss";
    } else {
        $where[] = "(type LIKE ? OR notes LIKE ? OR status LIKE ?)";
        $like = "%".$q."%";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types   .= "sss";
    }
}

/* when filter based on next_followup_date */
if ($when === 'today') {
    $where[] = "DATE(next_followup_date) = CURDATE()";
} elseif ($when === 'overdue') {
    $where[] = "next_followup_date < NOW()";
    if ($status === '') { // usually overdue means pending only
        $where[] = "status = 'pending'";
    }
} elseif ($when === 'upcoming') {
    $where[] = "next_followup_date > NOW()";
    if ($status === '') {
        $where[] = "status = 'pending'";
    }
}

if ($from !== '') {
    $where[] = "DATE(next_followup_date) >= ?";
    $params[] = $from;
    $types .= "s";
}
if ($to !== '') {
    $where[] = "DATE(next_followup_date) <= ?";
    $params[] = $to;
    $types .= "s";
}

$whereSql = !empty($where) ? ("WHERE " . implode(" AND ", $where)) : "";

/* ================= TOTAL ================= */
$countSql = "SELECT COUNT(*) AS total FROM followups $whereSql";
$countStmt = $conn->prepare($countSql);
if (!$countStmt) fail(500, "Prepare failed", ["error"=>$conn->error]);

if (!empty($params)) $countStmt->bind_param($types, ...$params);
if (!$countStmt->execute()) fail(500, "Count query failed", ["error"=>$countStmt->error]);

$total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

/* ================= DATA ================= */
$dataSql = "
    SELECT id, customer_id, employee_id, type, notes, next_followup_date, status, created_at
    FROM followups
    $whereSql
    ORDER BY next_followup_date ASC, id DESC
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
    $r['customer_id'] = $r['customer_id'] !== null ? (int)$r['customer_id'] : null;
    $r['employee_id'] = $r['employee_id'] !== null ? (int)$r['employee_id'] : null;
}
unset($r);

echo json_encode([
    "success" => true,
    "msg" => "Followups list",
    "page" => $page,
    "limit" => $limit,
    "total" => $total,
    "total_pages" => (int)ceil($total / $limit),
    "filters" => [
        "q" => $q !== '' ? $q : null,
        "customer_id" => $customer_id > 0 ? $customer_id : null,
        "status" => $status !== '' ? $status : null,
        "when" => $when !== '' ? $when : null,
        "from" => $from !== '' ? $from : null,
        "to" => $to !== '' ? $to : null
    ],
    "data" => $rows
]);