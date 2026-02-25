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

$user = get_authenticated_user();
if (!$user) fail(401, "Unauthorized");

$role = $user['role'] ?? '';
$my_id = (int)($user['id'] ?? 0);

if (!in_array($role, ['admin','manager','sales'], true)) fail(403, "Access denied");
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail(405, "GET only");

# pagination
$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
if ($page < 1) $page = 1;
if ($limit < 1) $limit = 20;
if ($limit > 100) $limit = 100;
$offset = ($page - 1) * $limit;

# filters
$q = trim($_GET['q'] ?? '');
$stage = trim($_GET['stage'] ?? '');
$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$owner = isset($_GET['owner']) ? (int)$_GET['owner'] : 0;

$allowedStages = ['prospect','negotiation','won','lost'];
if ($stage !== '' && !in_array($stage, $allowedStages, true)) {
    fail(400, "Invalid stage", ["allowed"=>$allowedStages]);
}

# sort whitelist
$sort_by = trim($_GET['sort_by'] ?? 'created_at');
$sort_dir = strtoupper(trim($_GET['sort_dir'] ?? 'DESC'));
$allowedSortBy = ['id','title','value','stage','expected_close_date','created_at'];
if (!in_array($sort_by, $allowedSortBy, true)) $sort_by = 'created_at';
if (!in_array($sort_dir, ['ASC','DESC'], true)) $sort_dir = 'DESC';

$where = [];
$params = [];
$types = "";

# sales restriction
if ($role === 'sales') {
    $where[] = "d.owner = ?";
    $params[] = $my_id;
    $types .= "i";
} else {
    if ($owner > 0) {
        $where[] = "d.owner = ?";
        $params[] = $owner;
        $types .= "i";
    }
}

if ($customer_id > 0) {
    $where[] = "d.customer_id = ?";
    $params[] = $customer_id;
    $types .= "i";
}

if ($stage !== '') {
    $where[] = "d.stage = ?";
    $params[] = $stage;
    $types .= "s";
}

if ($q !== '') {
    $where[] = "(d.title LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
    $like = "%".$q."%";
    $params[]=$like; $params[]=$like; $params[]=$like; $params[]=$like;
    $types .= "ssss";
}

$whereSql = !empty($where) ? ("WHERE ".implode(" AND ", $where)) : "";

# count
$countSql = "SELECT COUNT(*) AS total FROM deals d LEFT JOIN customers c ON c.id=d.customer_id $whereSql";
$countStmt = $conn->prepare($countSql);
if (!empty($params)) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);

# fetch
$sql = "
SELECT
  d.id, d.title, d.customer_id, c.name AS customer_name,
  d.value, d.stage, d.owner, u.name AS owner_name,
  d.expected_close_date, d.created_at
FROM deals d
LEFT JOIN customers c ON c.id = d.customer_id
LEFT JOIN users u ON u.id = d.owner
$whereSql
ORDER BY d.$sort_by $sort_dir
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
    $row['customer_id'] = $row['customer_id'] !== null ? (int)$row['customer_id'] : null;
    $row['owner'] = $row['owner'] !== null ? (int)$row['owner'] : null;
    $row['value'] = $row['value'] !== null ? (float)$row['value'] : null;
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