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

/* ================= AUTH ================= */
$user = get_authenticated_user();
if (!$user) fail(401, "Unauthorized");

$role  = $user['role'] ?? '';
$my_id = (int)($user['id'] ?? 0);

if (!in_array($role, ['admin','manager','sales'], true)) fail(403, "Access denied");

/* ================= METHOD VALIDATION ================= */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail(405, "GET only");

/*
✅ Optional filters:
- q            (search in note text)
- entity_type  (customer/deal/lead)
- entity_id
- created_by
- from, to     (YYYY-MM-DD) filter on created_at
- page, limit
*/

/* ================= INPUTS ================= */
$q           = clean($_GET['q'] ?? '');
$entity_type = clean($_GET['entity_type'] ?? '');
$entity_id   = isset($_GET['entity_id']) ? (int)$_GET['entity_id'] : 0;
$created_by  = isset($_GET['created_by']) ? (int)$_GET['created_by'] : 0;
$from        = clean($_GET['from'] ?? '');
$to          = clean($_GET['to'] ?? '');

$page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 20;
$offset = ($page - 1) * $limit;

/* validations */
if ($q !== '' && mb_strlen($q) > 200) fail(400, "q too long (max 200 chars)");

$allowedTypes = ['customer','deal','lead'];
if ($entity_type !== '' && !in_array($entity_type, $allowedTypes, true)) {
    fail(400, "Invalid entity_type", ["allowed"=>$allowedTypes]);
}

if ($from !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $from);
    if (!$d || $d->format('Y-m-d') !== $from) fail(400, "Invalid from date (YYYY-MM-DD)");
}
if ($to !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $to);
    if (!$d || $d->format('Y-m-d') !== $to) fail(400, "Invalid to date (YYYY-MM-DD)");
}

/* ================= WHERE BUILD ================= */
$where  = [];
$params = [];
$types  = "";

/* filters */
if ($entity_type !== '') {
    $where[] = "n.entity_type = ?";
    $params[] = $entity_type;
    $types .= "s";
}

if ($entity_id > 0) {
    $where[] = "n.entity_id = ?";
    $params[] = $entity_id;
    $types .= "i";
}

if ($created_by > 0) {
    $where[] = "n.created_by = ?";
    $params[] = $created_by;
    $types .= "i";
}

if ($q !== '') {
    $where[] = "n.note LIKE ?";
    $params[] = "%".$q."%";
    $types .= "s";
}

if ($from !== '') {
    $where[] = "DATE(n.created_at) >= ?";
    $params[] = $from;
    $types .= "s";
}
if ($to !== '') {
    $where[] = "DATE(n.created_at) <= ?";
    $params[] = $to;
    $types .= "s";
}

/* ✅ SALES restriction:
   Sales user can see notes ONLY for assigned entities:
   - customer: customers.assigned_to = my_id
   - deal: deals.owner = my_id
   - lead: leads.assigned_to = my_id
*/
if ($role === 'sales') {
    $where[] = "(
        (n.entity_type='customer' AND EXISTS (SELECT 1 FROM customers c WHERE c.id=n.entity_id AND c.assigned_to=?))
        OR
        (n.entity_type='deal' AND EXISTS (SELECT 1 FROM deals d WHERE d.id=n.entity_id AND d.owner=?))
        OR
        (n.entity_type='lead' AND EXISTS (SELECT 1 FROM leads l WHERE l.id=n.entity_id AND l.assigned_to=?))
    )";
    $params[] = $my_id; $params[] = $my_id; $params[] = $my_id;
    $types .= "iii";
}

$whereSql = !empty($where) ? ("WHERE " . implode(" AND ", $where)) : "";

/* ================= COUNT ================= */
$countSql = "SELECT COUNT(*) AS total FROM notes n $whereSql";
$countStmt = $conn->prepare($countSql);
if (!$countStmt) fail(500, "Prepare failed", ["error"=>$conn->error]);

if (!empty($params)) $countStmt->bind_param($types, ...$params);
if (!$countStmt->execute()) fail(500, "Count query failed", ["error"=>$countStmt->error]);

$total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

/* ================= DATA (with created_by name) ================= */
$dataSql = "
SELECT
    n.id, n.entity_type, n.entity_id, n.note,
    n.created_by, u.name AS created_by_name,
    n.created_at
FROM notes n
LEFT JOIN users u ON u.id = n.created_by
$whereSql
ORDER BY n.created_at DESC, n.id DESC
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

/* cast */
foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['entity_id'] = (int)$r['entity_id'];
    $r['created_by'] = (int)$r['created_by'];
}
unset($r);

echo json_encode([
    "success" => true,
    "msg" => "Notes list",
    "user_role" => $role,
    "page" => $page,
    "limit" => $limit,
    "total" => $total,
    "total_pages" => (int)ceil($total / $limit),
    "filters" => [
        "q" => $q !== '' ? $q : null,
        "entity_type" => $entity_type !== '' ? $entity_type : null,
        "entity_id" => $entity_id > 0 ? $entity_id : null,
        "created_by" => $created_by > 0 ? $created_by : null,
        "from" => $from !== '' ? $from : null,
        "to" => $to !== '' ? $to : null
    ],
    "data" => $rows
]);