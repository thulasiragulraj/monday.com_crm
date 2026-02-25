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

/* ================= METHOD VALIDATION ================= */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    fail(405, "Method not allowed. Use GET only.");
}

/* ================= AUTH ================= */
$user = get_authenticated_user();
if (!$user) fail(401, "Unauthorized");

$role  = $user['role'] ?? '';
$my_id = (int)($user['id'] ?? 0);

if (!in_array($role, ['admin','manager','sales'], true)) fail(403, "Access denied");

/*
✅ Supports:
- view.php?id=5
- view.php?q=5
- view.php?q=pending
- view.php?q=call
*/

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$q  = isset($_GET['q']) ? clean($_GET['q']) : '';

/* q validation */
if ($q !== '' && mb_strlen($q) > 100) fail(400, "q too long (max 100 chars)");

if ($id <= 0 && $q === '') {
    fail(400, "Provide id OR q");
}

/* ================= BUILD WHERE ================= */
$where  = [];
$params = [];
$types  = "";

/* exact id */
if ($id > 0) {
    $where[]  = "id = ?";
    $params[] = $id;
    $types   .= "i";
}

/* q search */
if ($q !== '') {
    if (ctype_digit($q)) {
        $qi = (int)$q;
        $where[] = "(
            id = ? OR customer_id = ? OR employee_id = ?
            OR type LIKE ? OR notes LIKE ? OR status LIKE ?
        )";
        $params[] = $qi; $params[] = $qi; $params[] = $qi;
        $types   .= "iii";

        $like = "%".$q."%";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types   .= "sss";
    } else {
        $where[] = "(type LIKE ? OR notes LIKE ? OR status LIKE ?)";
        $like = "%".$q."%";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types   .= "sss";
    }
}

/* sales restriction */
if ($role === 'sales') {
    $where[]  = "employee_id = ?";
    $params[] = $my_id;
    $types   .= "i";
}

$whereSql = "WHERE " . implode(" AND ", $where);

/* ================= QUERY ================= */
$sql = "
    SELECT id, customer_id, employee_id, type, notes, next_followup_date, status, created_at
    FROM followups
    $whereSql
    ORDER BY next_followup_date DESC, id DESC
    LIMIT 1
";

$stmt = $conn->prepare($sql);
if (!$stmt) fail(500, "Prepare failed", ["error"=>$conn->error]);

$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) fail(500, "Query failed", ["error"=>$stmt->error]);

$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) fail(404, "Followup not found");

/* optional casting */
$row['id'] = (int)$row['id'];
$row['customer_id'] = $row['customer_id'] !== null ? (int)$row['customer_id'] : null;
$row['employee_id'] = $row['employee_id'] !== null ? (int)$row['employee_id'] : null;

echo json_encode([
    "success" => true,
    "msg" => "Followup view",
    "search" => [
        "id" => $id > 0 ? $id : null,
        "q"  => $q !== '' ? $q : null
    ],
    "data" => $row
]);