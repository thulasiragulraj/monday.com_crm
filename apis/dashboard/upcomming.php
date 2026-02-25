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

/* -------------------------
   Params
------------------------- */
$days  = (int)($_GET['days'] ?? 7);         // next N days
$limit = (int)($_GET['limit'] ?? 20);
$include_done = (int)($_GET['include_done'] ?? 0); // 1 => include done also

if ($days <= 0 || $days > 60) $days = 7;
if ($limit <= 0 || $limit > 200) $limit = 20;

/* -------------------------
   WHERE conditions
------------------------- */
$where = [];
$params = [];
$types = "";

/* next_followup_date range */
$where[] = "DATE(f.next_followup_date) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)";
$params[] = $days;
$types .= "i";

/* exclude done by default */
if ($include_done !== 1) {
    $where[] = "LOWER(f.status) <> 'done'";
}

/* role filter */
if ($role === 'sales') {
    $where[] = "f.employee_id = ?";
    $params[] = $my_id;
    $types .= "i";
}

$whereSql = "WHERE " . implode(" AND ", $where);

/*
  Joins:
  - customers table -> customer name
  - users table -> employee name (optional)
  If your users table uses different columns, change u.name accordingly.
*/
$sql = "
    SELECT
        f.id,
        f.customer_id,
        COALESCE(c.name, 'Unknown') AS customer_name,
        f.employee_id,
        COALESCE(u.name, CONCAT('User#', f.employee_id)) AS employee_name,
        f.type,
        f.notes,
        f.next_followup_date,
        f.status,
        f.created_at
    FROM followups f
    LEFT JOIN customers c ON c.id = f.customer_id
    LEFT JOIN users u ON u.id = f.employee_id
    $whereSql
    ORDER BY f.next_followup_date ASC
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
        "id" => (int)$row["id"],
        "customer_id" => (int)$row["customer_id"],
        "customer_name" => $row["customer_name"],
        "employee_id" => (int)$row["employee_id"],
        "employee_name" => $row["employee_name"],
        "type" => $row["type"],
        "notes" => $row["notes"],
        "next_followup_date" => $row["next_followup_date"],
        "status" => $row["status"],
        "created_at" => $row["created_at"]
    ];
}

echo json_encode([
    "success" => true,
    "filters" => [
        "days" => $days,
        "limit" => $limit,
        "include_done" => $include_done
    ],
    "count" => count($data),
    "data" => $data
]);