<?php
header("Content-Type: application/json");

require_once '../../vendor/autoload.php';
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

function isAllowedExt($pathOrName) {
    $ext = strtolower(pathinfo($pathOrName, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg','jpeg','png','webp'], true);
}

function ensureUploadsDir($dir) {
    if (!is_dir($dir)) mkdir($dir, 0777, true);
}

function deleteFileByUrl($url) {
    // url like: /uploads/products/xxx.jpg
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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, "POST only");
}

# =========================
# INPUT (identify product)
# =========================
$product_id = (int)($_POST['product_id'] ?? 0);
$product_name = trim($_POST['product_name'] ?? '');

if ($product_id <= 0 && $product_name === '') {
    fail(400, "product_id or product_name required");
}

# =========================
# FIND PRODUCT
# =========================
if ($product_id > 0) {
    $find = $conn->prepare("SELECT * FROM products WHERE id=? LIMIT 1");
    $find->bind_param("i", $product_id);
} else {
    $find = $conn->prepare("SELECT * FROM products WHERE name=? LIMIT 1");
    $find->bind_param("s", $product_name);
}

$find->execute();
$existing = $find->get_result()->fetch_assoc();

if (!$existing) {
    fail(404, "Product not found");
}

$current_id = (int)$existing['id'];

# =========================
# INPUT FIELDS (optional)
# =========================
$new_name        = isset($_POST['name']) ? trim($_POST['name']) : null;
$new_description = isset($_POST['description']) ? trim($_POST['description']) : null;
$new_price       = isset($_POST['price']) ? (float)$_POST['price'] : null;
$new_category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : null;
$new_type        = isset($_POST['type']) ? trim($_POST['type']) : null;
$new_is_public   = isset($_POST['is_public']) ? (int)$_POST['is_public'] : null;

$replace_images  = isset($_POST['replace_images']) ? (int)$_POST['replace_images'] : 0;

# =========================
# VALIDATE CATEGORY IF PROVIDED
# =========================
if ($new_category_id !== null && $new_category_id > 0) {
    $cat = $conn->prepare("SELECT id FROM product_categories WHERE id=?");
    $cat->bind_param("i", $new_category_id);
    $cat->execute();
    if ($cat->get_result()->num_rows === 0) {
        fail(400, "Invalid category_id");
    }
}

# =========================
# DUPLICATE CHECK (NAME) IF UPDATING NAME
# =========================
if ($new_name !== null && $new_name !== '') {
    $dup = $conn->prepare("SELECT id FROM products WHERE name=? AND id<>? LIMIT 1");
    $dup->bind_param("si", $new_name, $current_id);
    $dup->execute();
    if ($dup->get_result()->num_rows > 0) {
        fail(409, "Duplicate product name already exists");
    }
}

# =========================
# BUILD UPDATE QUERY DYNAMIC
# =========================
$fields = [];
$params = [];
$types  = "";

if ($new_name !== null)        { $fields[]="name=?";        $params[]=$new_name;        $types.="s"; }
if ($new_description !== null) { $fields[]="description=?"; $params[]=$new_description; $types.="s"; }
if ($new_price !== null)       { $fields[]="price=?";       $params[]=$new_price;       $types.="d"; }
if ($new_category_id !== null) { $fields[]="category_id=?"; $params[]=$new_category_id; $types.="i"; }
if ($new_type !== null)        { $fields[]="type=?";        $params[]=$new_type;        $types.="s"; }
if ($new_is_public !== null)   { $fields[]="is_public=?";   $params[]=$new_is_public;   $types.="i"; }

# =========================
# IMAGE UPLOAD (optional)
# =========================
$uploaded_images = [];
$main_image_url = null;

$targetDir = __DIR__ . "/../../uploads/products/";
ensureUploadsDir($targetDir);

$hasNewImages = !empty($_FILES['product_imgs']['name'][0]);

# =========================
# TRANSACTION
# =========================
$conn->begin_transaction();

try {
    # 1) If replace_images=1 and uploading new images -> delete old gallery rows (and optionally old files)
    if ($replace_images === 1 && $hasNewImages) {

        // fetch existing gallery images
        $oldImgs = $conn->prepare("SELECT image_url FROM product_images WHERE product_id=?");
        $oldImgs->bind_param("i", $current_id);
        $oldImgs->execute();
        $rs = $oldImgs->get_result();
        while ($r = $rs->fetch_assoc()) {
            deleteFileByUrl($r['image_url']); // optional file delete
        }

        // delete rows
        $del = $conn->prepare("DELETE FROM product_images WHERE product_id=?");
        $del->bind_param("i", $current_id);
        $del->execute();
    }

    # 2) Upload new images (if any)
    if ($hasNewImages) {

        foreach ($_FILES['product_imgs']['name'] as $key => $val) {
            $origName = basename($_FILES['product_imgs']['name'][$key]);
            $tmpName  = $_FILES['product_imgs']['tmp_name'][$key];

            if (!$origName || !$tmpName) continue;
            if (!isAllowedExt($origName)) continue;

            $fileName = time() . "_" . uniqid() . "_" . $origName;
            $destPath = $targetDir . $fileName;

            if (!move_uploaded_file($tmpName, $destPath)) continue;

            $url = "/uploads/products/" . $fileName;

            // first image = main image
            if ($main_image_url === null) $main_image_url = $url;

            $position = $key + 1;

            $imgIns = $conn->prepare("
                INSERT INTO product_images (product_id, image_url, position)
                VALUES (?, ?, ?)
            ");
            $imgIns->bind_param("isi", $current_id, $url, $position);
            $imgIns->execute();

            $uploaded_images[] = ["url"=>$url, "position"=>$position];
        }

        // add main image update field
        if ($main_image_url !== null) {
            $fields[] = "product_img=?";
            $params[] = $main_image_url;
            $types   .= "s";
        }
    }

    # 3) Do product update if any fields changed
    if (!empty($fields)) {
        $sql = "UPDATE products SET " . implode(", ", $fields) . " WHERE id=?";
        $params[] = $current_id;
        $types .= "i";

        $stmt = $conn->prepare($sql);

        // bind_param dynamic
        $stmt->bind_param($types, ...$params);

        if (!$stmt->execute()) {
            throw new Exception("Update failed");
        }
    }

    $conn->commit();

} catch (Exception $e) {
    $conn->rollback();
    fail(500, "Update error", ["error"=>$e->getMessage()]);
}

# =========================
# FETCH UPDATED PRODUCT
# =========================
$get = $conn->prepare("SELECT * FROM products WHERE id=? LIMIT 1");
$get->bind_param("i", $current_id);
$get->execute();
$updated = $get->get_result()->fetch_assoc();

echo json_encode([
    "success" => true,
    "msg" => "Product updated",
    "product" => $updated,
    "main_image" => $main_image_url,
    "uploaded_images" => $uploaded_images
]);