<?php
header("Content-Type: application/json");

require '../../config/db.php';
require '../../config/jwt.php';
require '../../middleware/auth.php';

# =========================
# HELPERS
# =========================
function fail($code, $msg){
    http_response_code($code);
    echo json_encode([
        "success"=>false,
        "msg"=>$msg
    ]);
    exit;
}

# =========================
# AUTH CHECK
# =========================
$user = get_authenticated_user();

if (!$user) {
    fail(401, "Unauthorized");
}

$role = $user['role'] ?? '';
$user_id = (int)($user['id'] ?? 0);

if (!in_array($role, ['admin','manager','sales'], true)) {
    fail(403, "Access denied");
}

# =========================
# METHOD CHECK
# =========================
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    fail(405, "GET only");
}

# =========================
# PAGINATION
# =========================
$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;

if ($page < 1) $page = 1;
if ($limit < 1) $limit = 20;
if ($limit > 100) $limit = 100;

$offset = ($page - 1) * $limit;

# =========================
# ROLE BASED QUERY
# =========================

# ADMIN + MANAGER -> ALL USERS
if ($role === 'admin' || $role === 'manager') {

    # total count
    $countRes = $conn->query("SELECT COUNT(*) AS total FROM users");
    $total = (int)$countRes->fetch_assoc()['total'];

    $stmt = $conn->prepare("
        SELECT id, name, email, phone, role, created_at
        FROM users
        ORDER BY id DESC
        LIMIT ? OFFSET ?
    ");

    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $res = $stmt->get_result();

    $items = [];

    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
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

    exit;
}

# =========================
# SALES -> ONLY OWN DATA
# =========================
if ($role === 'sales') {

    $stmt = $conn->prepare("
        SELECT id, name, email, phone, role, created_at
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $data = $stmt->get_result()->fetch_assoc();

    if (!$data) {
        fail(404, "User not found");
    }

    $data['id'] = (int)$data['id'];

    echo json_encode([
        "success"=>true,
        "user_role"=>$role,
        "items"=>[$data]
    ]);

    exit;
}