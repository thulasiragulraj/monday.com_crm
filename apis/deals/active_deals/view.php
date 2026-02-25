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

$deal_id = isset($_GET['deal_id']) ? (int)$_GET['deal_id'] : 0;
$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$stage = trim($_GET['stage'] ?? '');

$allowedStages = ['prospect','negotiation','won','lost'];
if ($stage !== '' && !in_array($stage, $allowedStages, true)) {
    fail(400, "Invalid stage", ["allowed"=>$allowedStages]);
}

# if no filter at all, force deal_id
if ($deal_id <= 0 && $customer_id <= 0 && $stage === '') {
    fail(400, "Provide deal_id OR customer_id OR stage");
}

# =========================
# SINGLE MODE (deal_id)
# =========================
if ($deal_id > 0) {

    $sql = "
    SELECT
      d.id, d.title, d.customer_id, c.name AS customer_name,
      c.assigned_to AS customer_assigned_to,
      d.value, d.stage, d.owner, u.name AS owner_name,
      d.expected_close_date, d.created_at
    FROM deals d
    LEFT JOIN customers c ON c.id = d.customer_id
    LEFT JOIN users u ON u.id = d.owner
    WHERE d.id = ?
    LIMIT 1
    ";

    # sales restriction: owner=self
    if ($role === 'sales') {
        $sql = str_replace("WHERE d.id = ?", "WHERE d.id = ? AND d.owner = ?", $sql);
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $deal_id, $my_id);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $deal_id);
    }

    $stmt->execute();
    $deal = $stmt->get_result()->fetch_assoc();

    if (!$deal) fail(404, "Deal not found");

    $deal['id'] = (int)$deal['id'];
    $deal['customer_id'] = $deal['customer_id'] !== null ? (int)$deal['customer_id'] : null;
    $deal['owner'] = $deal['owner'] !== null ? (int)$deal['owner'] : null;
    $deal['value'] = $deal['value'] !== null ? (float)$deal['value'] : null;
    $deal['customer_assigned_to'] = $deal['customer_assigned_to'] !== null ? (int)$deal['customer_assigned_to'] : null;

    echo json_encode([
        "success" => true,
        "user_role" => $role,
        "deal" => $deal
    ]);
    exit;
}

# =========================
# LIST MODE (customer_id / stage)
# =========================
$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
if ($page < 1) $page = 1;
if ($limit < 1) $limit = 20;
if ($limit > 100) $limit = 100;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];
$types = "";

if ($customer_id > 0) { $where[] = "d.customer_id=?"; $params[] = $customer_id; $types .= "i"; }
if ($stage !== '') { $where[] = "d.stage=?"; $params[] = $stage; $types .= "s"; }

# sales restriction
if ($role === 'sales') {
    $where[] = "d.owner=?";
    $params[] = $my_id;
    $types .= "i";
}

$whereSql = "WHERE " . implode(" AND ", $where);

# count
$countSql = "SELECT COUNT(*) AS total FROM deals d $whereSql";
$countStmt = $conn->prepare($countSql);
$countStmt->bind_param($types, ...$params);
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
ORDER BY d.created_at DESC
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
    "success" => true,
    "user_role" => $role,
    "page" => $page,
    "limit" => $limit,
    "total" => $total,
    "total_pages" => (int)ceil($total / $limit),
    "items" => $items
]);