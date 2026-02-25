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
function clean($v){ return trim((string)$v); }

$user = get_authenticated_user();
if (!$user) fail(401, "Unauthorized");

$role  = $user['role'] ?? '';
$my_id = (int)($user['id'] ?? 0);

if (!in_array($role, ['admin','manager','sales'], true)) fail(403, "Access denied");
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail(405, "GET only");

/*
✅ Now supports:
- view.php?id=10
- view.php?q=10        (id/original_deal_id/customer_id numeric search)
- view.php?q=ragul     (title/lost_reason/stage search)
*/

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$q  = isset($_GET['q']) ? clean($_GET['q']) : "";

/* validation */
if ($q !== '' && mb_strlen($q) > 100) fail(400, "q too long (max 100 chars)");
if ($id <= 0 && $q === '') fail(400, "Provide id OR q");

/* build where */
$where  = [];
$params = [];
$types  = "";

/* exact by id */
if ($id > 0) {
    $where[]  = "id = ?";
    $params[] = $id;
    $types   .= "i";
}

/* q search */
if ($q !== '') {
    if (ctype_digit($q)) {
        $qi = (int)$q;
        $where[] = "(id = ? OR original_deal_id = ? OR customer_id = ?)";
        $params[] = $qi; $params[] = $qi; $params[] = $qi;
        $types   .= "iii";
    } else {
        $where[] = "(title LIKE ? OR lost_reason LIKE ? OR stage LIKE ?)";
        $like = "%".$q."%";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types   .= "sss";
    }
}

/* sales restriction (IMPORTANT) */
if ($role === 'sales') {
    $where[]  = "owner = ?";
    $params[] = $my_id;
    $types   .= "i";
}

$whereSql = "WHERE " . implode(" AND ", $where);

$sql = "
    SELECT id, original_deal_id, title, customer_id, value, stage, owner,
           expected_close_date, created_at, lost_at, lost_reason
    FROM deals_lost
    $whereSql
    ORDER BY lost_at DESC, id DESC
    LIMIT 1
";

$stmt = $conn->prepare($sql);
if (!$stmt) fail(500, "Prepare failed", ["error"=>$conn->error]);

$stmt->bind_param($types, ...$params);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) fail(404, "Deal lost record not found");

/* type casting (optional) */
$row['id'] = (int)$row['id'];
$row['original_deal_id'] = $row['original_deal_id'] !== null ? (int)$row['original_deal_id'] : null;
$row['customer_id'] = $row['customer_id'] !== null ? (int)$row['customer_id'] : null;
$row['owner'] = $row['owner'] !== null ? (int)$row['owner'] : null;
$row['value'] = $row['value'] !== null ? (float)$row['value'] : null;

echo json_encode([
    "success" => true,
    "msg" => "Deal lost view",
    "search" => [
        "id" => $id > 0 ? $id : null,
        "q"  => $q !== '' ? $q : null
    ],
    "data" => $row
]);