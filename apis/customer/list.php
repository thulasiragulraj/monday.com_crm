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

# AUTH
$user = get_authenticated_user();
if (!$user) fail(401, "Unauthorized");

$role = $user['role'] ?? '';
$my_id = (int)($user['id'] ?? 0);

if (!in_array($role, ['admin','manager','sales'], true)) {
    fail(403, "Access denied");
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    fail(405, "GET only");
}

# pagination
$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
if ($page < 1) $page = 1;
if ($limit < 1) $limit = 20;
if ($limit > 100) $limit = 100;
$offset = ($page - 1) * $limit;

# filters
$q = trim($_GET['q'] ?? '');
$source_id = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;
$assigned_to = isset($_GET['assigned_to']) ? (int)$_GET['assigned_to'] : 0;

# sort whitelist
$sort_by  = trim($_GET['sort_by'] ?? 'created_at');
$sort_dir = strtoupper(trim($_GET['sort_dir'] ?? 'DESC'));
$allowedSortBy = ['id','name','created_at'];
if (!in_array($sort_by, $allowedSortBy, true)) $sort_by = 'created_at';
if (!in_array($sort_dir, ['ASC','DESC'], true)) $sort_dir = 'DESC';

$where = [];
$params = [];
$types = "";

# sales restriction
if ($role === 'sales') {
    $where[] = "c.assigned_to = ?";
    $params[] = $my_id;
    $types .= "i";
} else {
    # admin/manager can filter by assigned_to
    if ($assigned_to > 0) {
        $where[] = "c.assigned_to = ?";
        $params[] = $assigned_to;
        $types .= "i";
    }
}

if ($source_id > 0) {
    $where[] = "c.source_id = ?";
    $params[] = $source_id;
    $types .= "i";
}

if ($q !== '') {
    $where[] = "(c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
    $like = "%".$q."%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= "sss";
}

$whereSql = !empty($where) ? ("WHERE " . implode(" AND ", $where)) : "";

# count
$countSql = "SELECT COUNT(*) AS total FROM customers c $whereSql";
$countStmt = $conn->prepare($countSql);
if (!empty($params)) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);

# fetch
$sql = "
SELECT
  c.id, c.name, c.phone, c.email,
  c.source_id, ls.name AS source_name,
  c.created_from_lead_id,
  c.assigned_to, u.name AS assigned_user_name,
  c.created_at
FROM customers c
LEFT JOIN lead_sources ls ON ls.id = c.source_id
LEFT JOIN users u ON u.id = c.assigned_to
$whereSql
ORDER BY c.$sort_by $sort_dir
LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);

$bindTypes = $types . "ii";
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
    $row['created_from_lead_id'] = $row['created_from_lead_id'] !== null ? (int)$row['created_from_lead_id'] : null;
    $row['assigned_to'] = $row['assigned_to'] !== null ? (int)$row['assigned_to'] : null;
    $items[] = $row;
}

echo json_encode([
    "success"=>true,
    "user_role"=>$role,
    "page"=>$page,
    "limit"=>$limit,
    "total"=>$total,
    "total_pages"=>(int)ceil($total/$limit),
    "items"=>$items
]);