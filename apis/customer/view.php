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

/* ================= METHOD VALIDATION ================= */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    fail(405, "Method not allowed. Use GET only.");
}

/* ================= AUTH ================= */
$user = get_authenticated_user();
if (!$user) fail(401, "Unauthorized");

$role  = $user['role'] ?? '';
$my_id = (int)($user['id'] ?? 0);

if (!in_array($role, ['admin','manager','sales'], true)) {
    fail(403, "Access denied");
}

/* ================= INPUT =================
✅ support:
- id=1
- phone=9876...
- email=a@b.com
- q=ragul / q=3 / q=9876 / q=@gmail.com  (single search)
================================================ */
$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$phone = trim((string)($_GET['phone'] ?? ''));
$email = trim((string)($_GET['email'] ?? ''));
$q     = trim((string)($_GET['q'] ?? ''));

/* small validation for q */
if ($q !== '' && mb_strlen($q) > 100) {
    fail(400, "q is too long (max 100 chars)");
}

/* if nothing provided */
if ($id <= 0 && $phone === '' && $email === '' && $q === '') {
    fail(400, "Provide id OR phone OR email OR q");
}

/* ================= BUILD WHERE ================= */
$where  = [];
$params = [];
$types  = "";

/* exact filters (old) */
if ($id > 0)    { $where[] = "c.id=?";    $params[] = $id;    $types .= "i"; }
if ($phone !== '') { $where[] = "c.phone=?"; $params[] = $phone; $types .= "s"; }
if ($email !== '') { $where[] = "c.email=?"; $params[] = $email; $types .= "s"; }

/* ✅ q search (single search key) */
if ($q !== '') {
    if (ctype_digit($q)) {
        $qid = (int)$q;
        $where[] = "(c.id = ? OR c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
        $params[] = $qid; $types .= "i";

        $like = "%".$q."%";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= "sss";
    } else {
        $where[] = "(c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
        $like = "%".$q."%";
        $params[] = $like; $params[] = $like; $params[] = $like;
        $types .= "sss";
    }
}

/* sales restriction */
if ($role === 'sales') {
    $where[] = "c.assigned_to = ?";
    $params[] = $my_id;
    $types .= "i";
}

$whereSql = "WHERE " . implode(" AND ", $where);

/* ================= QUERY ================= */
$sql = "
SELECT
  c.id, c.name, c.phone, c.email,
  c.source_id, ls.name AS source_name,
  c.created_from_lead_id,
  c.assigned_to, u.name AS assigned_user_name,
  c.created_at
FROM customers c
LEFT JOIN lead_sources ls ON ls.id = c.source_id
LEFT JOIN users u ON u.id = c.assigned_to
$whereSql
ORDER BY c.id DESC
LIMIT 1
";

$stmt = $conn->prepare($sql);
if (!$stmt) fail(500, "Prepare failed", ["error"=>$conn->error]);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

if (!$stmt->execute()) fail(500, "Query failed", ["error"=>$stmt->error]);

$data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$data) fail(404, "Customer not found");

/* ================= CASTING ================= */
$data['id'] = (int)$data['id'];
$data['source_id'] = $data['source_id'] !== null ? (int)$data['source_id'] : null;
$data['created_from_lead_id'] = $data['created_from_lead_id'] !== null ? (int)$data['created_from_lead_id'] : null;
$data['assigned_to'] = $data['assigned_to'] !== null ? (int)$data['assigned_to'] : null;

echo json_encode([
    "success" => true,
    "user_role" => $role,
    "search" => [
        "id" => $id > 0 ? $id : null,
        "phone" => $phone !== '' ? $phone : null,
        "email" => $email !== '' ? $email : null,
        "q" => $q !== '' ? $q : null
    ],
    "customer" => $data
]);