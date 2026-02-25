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

$user = get_authenticated_user();
if (!$user) fail(401, "Unauthorized");

$role  = $user['role'] ?? '';
$my_id = (int)($user['id'] ?? 0);

if (!in_array($role, ['admin','manager','sales'], true)) fail(403, "Access denied");
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail(405, "GET only");

/* optional filters */
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');
$limit     = (int)($_GET['limit'] ?? 10);
if ($limit <= 0 || $limit > 50) $limit = 10;

function validDate($d){
    if ($d === '') return true;
    $t = DateTime::createFromFormat('Y-m-d', $d);
    return $t && $t->format('Y-m-d') === $d;
}
if (!validDate($date_from) || !validDate($date_to)) fail(400, "Invalid date format. Use YYYY-MM-DD");

/* role-based where */
$where = [];
$params = [];
$types = "";

/* sales -> only own results */
if ($role === 'sales') {
    $where[] = "dw.owner = ?";
    $params[] = $my_id;
    $types .= "i";
}

if ($date_from !== '') {
    $where[] = "DATE(dw.won_at) >= ?";
    $params[] = $date_from;
    $types .= "s";
}
if ($date_to !== '') {
    $where[] = "DATE(dw.won_at) <= ?";
    $params[] = $date_to;
    $types .= "s";
}

$whereSql = count($where) ? ("WHERE " . implode(" AND ", $where)) : "";

/*
Assumptions:
- deals_won has: owner, value, won_at
- users table has: id, name
If your users column names differ, change u.id/u.name accordingly.
*/
$sql = "
    SELECT 
        dw.owner AS user_id,
        COALESCE(u.name, CONCAT('User#', dw.owner)) AS name,
        COUNT(*) AS won_count,
        COALESCE(SUM(dw.value),0) AS won_value
    FROM deals_won dw
    LEFT JOIN users u ON u.id = dw.owner
    $whereSql
    GROUP BY dw.owner, u.name
    ORDER BY won_value DESC
    LIMIT $limit
";

$stmt = $conn->prepare($sql);
if (!$stmt) fail(500, "Prepare failed", ["error"=>$conn->error]);

if ($types !== "") $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while ($row = $res->fetch_assoc()) {
    $data[] = [
        "user_id"   => (int)$row["user_id"],
        "name"      => $row["name"],
        "won_count" => (int)$row["won_count"],
        "won_value" => (float)$row["won_value"]
    ];
}

echo json_encode([
    "success" => true,
    "filters" => [
        "date_from" => $date_from,
        "date_to"   => $date_to,
        "limit"     => $limit
    ],
    "data" => $data
]);