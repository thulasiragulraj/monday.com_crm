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

# =========================
# 🔐 AUTH CHECK
# =========================
$user = get_authenticated_user();
$allowedRoles = ['admin','sales','manager'];

if (!$user || !in_array(($user['role'] ?? ''), $allowedRoles, true)) {
    fail(403, "Access denied (admin/sales/manager only)");
}

# =========================
# METHOD CHECK
# =========================
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    fail(405, "GET only");
}

# =========================
# INPUTS
# =========================
$id          = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$name        = clean($_GET['name'] ?? '');
$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$type        = clean($_GET['type'] ?? '');

# ✅ NEW: single search key
$q = clean($_GET['q'] ?? '');

$include_images = isset($_GET['include_images']) ? (int)$_GET['include_images'] : 1;
if (!in_array($include_images, [0,1], true)) {
    fail(400, "include_images must be 0 or 1");
}

# type validation (optional)
$allowedTypes = ['physical','digital','service'];
if ($type !== '' && !in_array($type, $allowedTypes, true)) {
    fail(400, "Invalid type. Allowed: physical, digital, service");
}

# q validation
if ($q !== '' && mb_strlen($q) > 200) {
    fail(400, "q too long (max 200 chars)");
}

# At least one filter required (now q also allowed)
if ($id <= 0 && $name === '' && $category_id <= 0 && $type === '' && $q === '') {
    fail(400, "Provide any one filter: id OR name OR category_id OR type OR q");
}

# =========================
# BUILD WHERE (supports multiple filters together)
# =========================
$where  = [];
$params = [];
$types  = "";

if ($id > 0) {
    $where[]  = "p.id = ?";
    $params[] = $id;
    $types   .= "i";
}

if ($name !== '') {
    # keep exact match like your old code
    $where[]  = "p.name = ?";
    $params[] = $name;
    $types   .= "s";
}

if ($category_id > 0) {
    $where[]  = "p.category_id = ?";
    $params[] = $category_id;
    $types   .= "i";
}

if ($type !== '') {
    $where[]  = "p.type = ?";
    $params[] = $type;
    $types   .= "s";
}

/*
✅ q search (single key)
- numeric q: matches p.id OR p.price OR LIKE fields
- text q: matches name/description/type/category_name
*/
if ($q !== '') {
    if (ctype_digit($q)) {
        $qi = (int)$q;
        $qf = (float)$q;
        $like = "%".$q."%";

        $where[] = "(
            p.id = ?
            OR p.price = ?
            OR p.name LIKE ?
            OR p.description LIKE ?
            OR p.type LIKE ?
            OR c.name LIKE ?
        )";
        $params[] = $qi;  $types .= "i";
        $params[] = $qf;  $types .= "d";
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= "ssss";
    } else {
        $like = "%".$q."%";
        $where[] = "(
            p.name LIKE ?
            OR p.description LIKE ?
            OR p.type LIKE ?
            OR c.name LIKE ?
        )";
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= "ssss";
    }
}

$whereSql = "WHERE " . implode(" AND ", $where);

# =========================
# FETCH PRODUCTS (can be 1 or many)
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
    ORDER BY p.id DESC
    LIMIT 200
";

$stmt = $conn->prepare($sql);
if (!$stmt) fail(500, "Prepare failed", ["error"=>$conn->error]);

$stmt->bind_param($types, ...$params);
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
$stmt->close();

if (empty($items)) {
    fail(404, "No products found");
}

# =========================
# INCLUDE IMAGES (optional)
# =========================
$imagesMap = [];

if ($include_images === 1 && !empty($productIds)) {
    $placeholders = implode(",", array_fill(0, count($productIds), "?"));

    $imgSql = "
        SELECT product_id, image_url, position
        FROM product_images
        WHERE product_id IN ($placeholders)
        ORDER BY product_id ASC, position ASC
    ";

    $imgStmt = $conn->prepare($imgSql);
    if (!$imgStmt) fail(500, "Prepare failed", ["error"=>$conn->error]);

    $imgTypes = str_repeat("i", count($productIds));
    $imgStmt->bind_param($imgTypes, ...$productIds);
    $imgStmt->execute();

    $imgRes = $imgStmt->get_result();
    while ($img = $imgRes->fetch_assoc()) {
        $pid = (int)$img['product_id'];
        if (!isset($imagesMap[$pid])) $imagesMap[$pid] = [];
        $imagesMap[$pid][] = [
            "url" => $img['image_url'],
            "position" => (int)$img['position']
        ];
    }
    $imgStmt->close();

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
    "success" => true,
    "user_role" => $user['role'],
    "filters" => [
        "id" => $id > 0 ? $id : null,
        "name" => $name !== '' ? $name : null,
        "category_id" => $category_id > 0 ? $category_id : null,
        "type" => $type !== '' ? $type : null,
        "q" => $q !== '' ? $q : null,
        "include_images" => $include_images
    ],
    "count" => count($items),
    "items" => $items
]);