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
    echo json_encode(array_merge(["success"=>false,"msg"=>$msg], $extra));
    exit;
}

# =========================
# 🔐 AUTH CHECK (any user)
# =========================
$user = get_authenticated_user();
if (!$user) {
    fail(401, "Unauthorized");
}

# =========================
# METHOD CHECK
# =========================
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    fail(405, "GET only");
}

# =========================
# INPUTS (VALIDATION)
# =========================
$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

if ($page < 1) $page = 1;
if ($limit < 1) $limit = 20;
if ($limit > 100) $limit = 100;

$offset = ($page - 1) * $limit;

$q          = trim($_GET['q'] ?? '');                // search name/phone/email/message
$status     = trim($_GET['status'] ?? '');           // new/assigned/contacted/qualified/won/lost/closed etc
$source_id  = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$assigned_to = isset($_GET['assigned_to']) ? (int)$_GET['assigned_to'] : 0;

# include optional
$only_my = isset($_GET['only_my']) ? (int)$_GET['only_my'] : 0; // 1 => only my assigned leads

# sort whitelist
$sort_by  = trim($_GET['sort_by'] ?? 'created_at');
$sort_dir = strtoupper(trim($_GET['sort_dir'] ?? 'DESC'));

$allowedSortBy = ['id','created_at','name','status'];
if (!in_array($sort_by, $allowedSortBy, true)) $sort_by = 'created_at';
if (!in_array($sort_dir, ['ASC','DESC'], true)) $sort_dir = 'DESC';

# =========================
# ROLE BASE DEFAULT (recommended)
# sales user should see only their leads unless admin/manager asks otherwise
# =========================
$currentUserId = (int)($user['id'] ?? 0);
$currentRole = $user['role'] ?? '';

if ($currentRole === 'sales') {
    # default: show only assigned leads to this sales user
    if ($assigned_to <= 0) $assigned_to = $currentUserId;
    if ($only_my === 1) $assigned_to = $currentUserId;
}

# =========================
# BUILD WHERE
# =========================
$where = [];
$params = [];
$types = "";

if ($status !== '') {
    $where[] = "l.status = ?";
    $params[] = $status;
    $types .= "s";
}

if ($source_id > 0) {
    $where[] = "l.source_id = ?";
    $params[] = $source_id;
    $types .= "i";
}

if ($product_id > 0) {
    $where[] = "l.product_id = ?";
    $params[] = $product_id;
    $types .= "i";
}

if ($assigned_to > 0) {
    $where[] = "l.assigned_to = ?";
    $params[] = $assigned_to;
    $types .= "i";
}

if ($q !== '') {
    $where[] = "(l.name LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR l.message LIKE ?)";
    $like = "%" . $q . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "ssss";
}

$whereSql = !empty($where) ? ("WHERE " . implode(" AND ", $where)) : "";

# =========================
# COUNT TOTAL
# =========================
$countSql = "SELECT COUNT(*) AS total FROM leads l $whereSql";
$countStmt = $conn->prepare($countSql);
if (!empty($params)) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);

# =========================
# FETCH LEADS (with joins)
# =========================
$sql = "
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
ORDER BY l.$sort_by $sort_dir
LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);

$bindParams = $params;
$bindTypes  = $types . "ii";
$bindParams[] = $limit;
$bindParams[] = $offset;

$stmt->bind_param($bindTypes, ...$bindParams);

$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($row = $res->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['source_id'] = (int)$row['source_id'];
    $row['product_id'] = (int)$row['product_id'];
    $row['assigned_to'] = $row['assigned_to'] !== null ? (int)$row['assigned_to'] : null;

    $items[] = $row;
}

# =========================
# RESPONSE
# =========================
echo json_encode([
    "success" => true,
    "user" => [
        "id" => $currentUserId,
        "role" => $currentRole
    ],
    "page" => $page,
    "limit" => $limit,
    "total" => $total,
    "total_pages" => (int)ceil($total / $limit),
    "filters" => [
        "q" => $q,
        "status" => $status,
        "source_id" => $source_id ?: null,
        "product_id" => $product_id ?: null,
        "assigned_to" => $assigned_to ?: null,
        "sort_by" => $sort_by,
        "sort_dir" => $sort_dir,
        "only_my" => $only_my
    ],
    "items" => $items
]);