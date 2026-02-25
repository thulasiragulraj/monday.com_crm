<?php
header("Content-Type: application/json");

require '../../config/db.php';
require '../../config/jwt.php';
require '../../middleware/auth.php';

# =========================
# 🔐 AUTH CHECK
# =========================
$user = get_authenticated_user();

$allowedRoles = ['admin','sales','manager'];

if (!$user || !in_array($user['role'], $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode([
        "success"=>false,
        "msg"=>"Access denied (admin/sales/manager only)"
    ]);
    exit;
}

# =========================
# METHOD CHECK
# =========================
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["success"=>false, "msg"=>"GET only"]);
    exit;
}

# =========================
# INPUTS (VALIDATION)
# =========================
$page  = isset($_GET['page'])  ? (int)$_GET['page']  : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

if ($page < 1) $page = 1;
if ($limit < 1) $limit = 20;
if ($limit > 100) $limit = 100;

$offset = ($page - 1) * $limit;

$q           = trim($_GET['q'] ?? '');
$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$type        = isset($_GET['type']) ? trim($_GET['type']) : null;
$is_public   = isset($_GET['is_public']) ? (int)$_GET['is_public'] : 1;
$include_images = isset($_GET['include_images']) ? (int)$_GET['include_images'] : 0;

# =========================
# SORT VALIDATION
# =========================
$sort_by = trim($_GET['sort_by'] ?? 'id');
$sort_dir = strtoupper(trim($_GET['sort_dir'] ?? 'DESC'));

$allowedSortBy = ['id','name','price','created_at'];
if (!in_array($sort_by, $allowedSortBy, true)) $sort_by = 'id';

if (!in_array($sort_dir, ['ASC','DESC'], true)) $sort_dir = 'DESC';

# =========================
# TYPE VALIDATION
# =========================
$allowedTypes = ['physical','digital','service'];
if ($type !== null && $type !== '' && !in_array($type, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode([
        "success"=>false,
        "msg"=>"Invalid type. Allowed: physical, digital, service"
    ]);
    exit;
}

# =========================
# is_public VALIDATION
# =========================
if (!in_array($is_public, [0,1], true)) {
    http_response_code(400);
    echo json_encode([
        "success"=>false,
        "msg"=>"Invalid is_public. Use 0 or 1"
    ]);
    exit;
}

# =========================
# BUILD WHERE
# =========================
$where = [];
$params = [];
$types = "";

$where[] = "p.is_public = ?";
$params[] = $is_public;
$types .= "i";

if ($category_id !== null && $category_id > 0) {
    $where[] = "p.category_id = ?";
    $params[] = $category_id;
    $types .= "i";
}

if ($type !== null && $type !== '') {
    $where[] = "p.type = ?";
    $params[] = $type;
    $types .= "s";
}

if ($q !== '') {
    $where[] = "(p.name LIKE ? OR p.description LIKE ?)";
    $like = "%".$q."%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

$whereSql = !empty($where)
    ? "WHERE ".implode(" AND ", $where)
    : "";

# =========================
# COUNT TOTAL
# =========================
$countSql = "SELECT COUNT(*) AS total FROM products p $whereSql";

$countStmt = $conn->prepare($countSql);
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();

$total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);

# =========================
# FETCH PRODUCTS
# =========================
$sql = "
SELECT
    p.id, p.name, p.description, p.price,
    p.category_id, p.type, p.is_public,
    p.product_img, p.created_at,
    c.name AS category_name
FROM products p
LEFT JOIN product_categories c ON c.id = p.category_id
$whereSql
ORDER BY p.$sort_by $sort_dir
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
$productIds = [];

while ($row = $res->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['price'] = (float)$row['price'];
    $row['category_id'] = (int)$row['category_id'];
    $row['is_public'] = (int)$row['is_public'];

    $items[] = $row;
    $productIds[] = (int)$row['id'];
}

# =========================
# INCLUDE GALLERY IMAGES
# =========================
$imagesMap = [];

if ($include_images === 1 && !empty($productIds)) {

    $placeholders = implode(",", array_fill(0, count($productIds), "?"));

    $imgSql = "
        SELECT product_id, image_url, position
        FROM product_images
        WHERE product_id IN ($placeholders)
        ORDER BY product_id, position
    ";

    $imgStmt = $conn->prepare($imgSql);
    $imgTypes = str_repeat("i", count($productIds));
    $imgStmt->bind_param($imgTypes, ...$productIds);
    $imgStmt->execute();

    $imgRes = $imgStmt->get_result();

    while ($img = $imgRes->fetch_assoc()) {
        $pid = (int)$img['product_id'];
        if (!isset($imagesMap[$pid])) $imagesMap[$pid] = [];

        $imagesMap[$pid][] = [
            "url"=>$img['image_url'],
            "position"=>(int)$img['position']
        ];
    }
}

if ($include_images === 1) {
    foreach ($items as &$p) {
        $pid = (int)$p['id'];
        $p['images'] = $imagesMap[$pid] ?? [];
    }
    unset($p);
}

# =========================
# RESPONSE
# =========================
echo json_encode([
    "success"=>true,
    "user_role"=>$user['role'],
    "page"=>$page,
    "limit"=>$limit,
    "total"=>$total,
    "total_pages"=>(int)ceil($total/$limit),
    "items"=>$items
]);