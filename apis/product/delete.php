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

function deleteFileByUrl($url) {
    if (!$url) return;
    $url = str_replace("\\", "/", $url);
    $base = basename($url);
    $path = __DIR__ . "/../../uploads/products/" . $base;
    if (file_exists($path)) @unlink($path);
}

# =========================
# 🔐 ADMIN AUTH
# =========================
$user = get_authenticated_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    fail(403, "Admin only");
}

# =========================
# METHOD CHECK
# =========================
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    fail(405, "DELETE only");
}

# =========================
# INPUT (form-data OR raw JSON)
# =========================
$input = $_POST;

if (empty($input)) {
    $raw = file_get_contents("php://input");
    $json = json_decode($raw, true);
    if (is_array($json)) $input = $json;
}

$product_id = isset($input['product_id']) ? (int)$input['product_id'] : 0;
$product_name = trim($input['product_name'] ?? '');

if ($product_id <= 0 && $product_name === '') {
    fail(400, "product_id or product_name required");
}

# =========================
# FIND PRODUCT (and prevent ambiguous name)
# =========================
if ($product_id > 0) {
    $stmt = $conn->prepare("SELECT id, name, product_img FROM products WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();

    if (!$product) fail(404, "Product not found");
    $pid = (int)$product['id'];

} else {
    # check duplicates by name
    $chk = $conn->prepare("SELECT COUNT(*) AS c FROM products WHERE name=?");
    $chk->bind_param("s", $product_name);
    $chk->execute();
    $c = (int)($chk->get_result()->fetch_assoc()['c'] ?? 0);

    if ($c === 0) fail(404, "Product not found");
    if ($c > 1) fail(409, "Duplicate product_name found. Use product_id instead.");

    $stmt = $conn->prepare("SELECT id, name, product_img FROM products WHERE name=? LIMIT 1");
    $stmt->bind_param("s", $product_name);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();

    if (!$product) fail(404, "Product not found");
    $pid = (int)$product['id'];
}

# =========================
# FETCH GALLERY IMAGES
# =========================
$imgs = [];
$imgStmt = $conn->prepare("SELECT image_url FROM product_images WHERE product_id=?");
$imgStmt->bind_param("i", $pid);
$imgStmt->execute();
$imgRes = $imgStmt->get_result();
while ($r = $imgRes->fetch_assoc()) {
    $imgs[] = $r['image_url'];
}

# =========================
# TRANSACTION DELETE
# =========================
$conn->begin_transaction();

try {
    # delete product_images rows
    $delImgs = $conn->prepare("DELETE FROM product_images WHERE product_id=?");
    $delImgs->bind_param("i", $pid);
    $delImgs->execute();

    # delete product row
    $delP = $conn->prepare("DELETE FROM products WHERE id=?");
    $delP->bind_param("i", $pid);
    $delP->execute();

    if ($delP->affected_rows <= 0) {
        throw new Exception("Delete failed");
    }

    $conn->commit();

} catch (Exception $e) {
    $conn->rollback();
    fail(500, "Delete error", ["error"=>$e->getMessage()]);
}

# =========================
# DELETE FILES (after DB commit)
# =========================
deleteFileByUrl($product['product_img'] ?? null);
foreach ($imgs as $u) deleteFileByUrl($u);

# =========================
# RESPONSE
# =========================
echo json_encode([
    "success" => true,
    "msg" => "Product deleted",
    "deleted_product_id" => $pid,
    "deleted_name" => $product['name'] ?? null,
    "deleted_files_count" => (int)(($product['product_img'] ? 1 : 0) + count($imgs))
]);