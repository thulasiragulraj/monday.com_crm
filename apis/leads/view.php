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

/* =========================
   AUTH CHECK
========================= */
$user = get_authenticated_user();
if (!$user) fail(401, "Unauthorized");

$role    = $user['role'] ?? '';
$user_id = (int)($user['id'] ?? 0);

if (!in_array($role, ['admin','manager','sales'], true)) fail(403, "Access denied");

/* =========================
   METHOD CHECK
========================= */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail(405, "GET only");

/* =========================
   INPUTS
========================= */
$id          = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$phone       = clean($_GET['phone'] ?? '');
$email       = clean($_GET['email'] ?? '');
$product_id  = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$status      = clean($_GET['status'] ?? '');
$assigned_to = isset($_GET['assigned_to']) ? (int)$_GET['assigned_to'] : 0;

/* ✅ NEW: single search key */
$q = clean($_GET['q'] ?? '');

/* pagination */
$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
if ($page < 1) $page = 1;
if ($limit < 1) $limit = 50;
if ($limit > 200) $limit = 200;
$offset = ($page - 1) * $limit;

/* q validation */
if ($q !== '' && mb_strlen($q) > 100) fail(400, "q too long (max 100 chars)");

/* if no filter provided -> reject */
if ($id <= 0 && $phone === '' && $email === '' && $product_id <= 0 && $status === '' && $assigned_to <= 0 && $q === '') {
    fail(400, "Provide any one filter: id OR phone OR email OR product_id OR status OR assigned_to OR q");
}

/* =========================
   ROLE-BASED RESTRICTION
========================= */
$where  = [];
$params = [];
$types  = "";

/* requested exact filters */
if ($id > 0) {
    $where[]  = "l.id = ?";
    $params[] = $id;
    $types   .= "i";
}
if ($phone !== '') {
    $where[]  = "l.phone = ?";
    $params[] = $phone;
    $types   .= "s";
}
if ($email !== '') {
    $where[]  = "l.email = ?";
    $params[] = $email;
    $types   .= "s";
}
if ($product_id > 0) {
    $where[]  = "l.product_id = ?";
    $params[] = $product_id;
    $types   .= "i";
}
if ($status !== '') {
    $where[]  = "l.status = ?";
    $params[] = $status;
    $types   .= "s";
}

/* ✅ q search (single key)
   q=ragul / q=9876 / q=@gmail.com / q=new / q=12 etc...
   searches in: id, phone, email, name, message, status, product_name
*/
if ($q !== '') {
    if (ctype_digit($q)) {
        $qi = (int)$q;
        $where[] = "(
            l.id = ? OR l.product_id = ? OR l.assigned_to = ?
            OR l.phone LIKE ? OR l.email LIKE ? OR l.name LIKE ? OR l.message LIKE ? OR l.status LIKE ?
            OR p.name LIKE ?
        )";
        $params[] = $qi; $params[] = $qi; $params[] = $qi;
        $types   .= "iii";

        $like = "%".$q."%";
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        $types   .= "sssss";

        $params[] = $like;
        $types   .= "s";
    } else {
        $where[] = "(
            l.name LIKE ? OR l.phone LIKE ? OR l.email LIKE ?
            OR l.message LIKE ? OR l.status LIKE ?
            OR p.name LIKE ?
        )";
        $like = "%".$q."%";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $params[] = $like; $params[] = $like;
        $params[] = $like;
        $types   .= "ssssss";
    }
}

/* assigned_to filter:
- admin/manager can use assigned_to filter
- sales can only use own user_id
*/
if ($role === 'sales') {
    $where[]  = "l.assigned_to = ?";
    $params[] = $user_id;
    $types   .= "i";
} else {
    if ($assigned_to > 0) {
        $where[]  = "l.assigned_to = ?";
        $params[] = $assigned_to;
        $types   .= "i";
    }
}

$whereSql = "WHERE " . implode(" AND ", $where);

/* =========================
   FETCH (single or list)
========================= */
$baseSql = "
SELECT
    l.id, l.name, l.phone, l.email,
    l.source_id, s.name AS source_name,
    l.product_id, p.name AS product_name,
    l.message, l.status, l.assigned_to,
    u.name AS assigned_user_name,
    l.created_at
FROM leads l
LEFT JOIN lead_sources s ON s.id = l.source_id
LEFT JOIN products p ON p.id = l.product_id
LEFT JOIN users u ON u.id = l.assigned_to
$whereSql
";

if ($id > 0) {
    $sql  = $baseSql . " LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) fail(500, "Prepare failed", ["error"=>$conn->error]);

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $lead = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$lead) fail(404, "Lead not found");

    $lead['id'] = (int)$lead['id'];
    $lead['source_id'] = $lead['source_id'] !== null ? (int)$lead['source_id'] : null;
    $lead['product_id'] = $lead['product_id'] !== null ? (int)$lead['product_id'] : null;
    $lead['assigned_to'] = $lead['assigned_to'] !== null ? (int)$lead['assigned_to'] : null;

    echo json_encode([
        "success" => true,
        "user_role" => $role,
        "filters" => ["id"=>$id, "q"=>$q ?: null],
        "lead" => $lead
    ]);
    exit;
}

/* list mode count total */
$countSql  = "SELECT COUNT(*) AS total FROM leads l LEFT JOIN products p ON p.id = l.product_id $whereSql";
$countStmt = $conn->prepare($countSql);
if (!$countStmt) fail(500, "Prepare failed", ["error"=>$conn->error]);

$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
$countStmt->close();

/* fetch list */
$sql  = $baseSql . " ORDER BY l.created_at DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if (!$stmt) fail(500, "Prepare failed", ["error"=>$conn->error]);

$bindTypes  = $types . "ii";
$bindParams = $params;
$bindParams[] = $limit;
$bindParams[] = $offset;

$stmt->bind_param($bindTypes, ...$bindParams);
$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($row = $res->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['source_id'] = $row['source_id'] !== null ? (int)$row['source_id'] : null;
    $row['product_id'] = $row['product_id'] !== null ? (int)$row['product_id'] : null;
    $row['assigned_to'] = $row['assigned_to'] !== null ? (int)$row['assigned_to'] : null;
    $items[] = $row;
}
$stmt->close();

echo json_encode([
    "success" => true,
    "user_role" => $role,
    "page" => $page,
    "limit" => $limit,
    "total" => $total,
    "total_pages" => (int)ceil($total / $limit),
    "filters" => [
        "id" => $id > 0 ? $id : null,
        "phone" => $phone !== '' ? $phone : null,
        "email" => $email !== '' ? $email : null,
        "product_id" => $product_id > 0 ? $product_id : null,
        "status" => $status !== '' ? $status : null,
        "assigned_to" => ($role === 'sales') ? $user_id : ($assigned_to > 0 ? $assigned_to : null),
        "q" => $q !== '' ? $q : null
    ],
    "items" => $items
]);