<?php
header("Content-Type: application/json");

require_once '../../vendor/autoload.php';
require '../../config/db.php';
require '../../config/jwt.php';
require '../../middleware/auth.php';

# =========================
# 🔐 ADMIN AUTH
# =========================
$user = get_authenticated_user();

if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(["success"=>false,"msg"=>"Admin only"]);
    exit;
}

# =========================
# METHOD CHECK
# =========================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success"=>false,"msg"=>"POST only"]);
    exit;
}

# =========================
# PRODUCT DATA
# =========================
$name        = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
$price       = (float)($_POST['price'] ?? 0);
$category_id = (int)($_POST['category_id'] ?? 0);
$type        = trim($_POST['type'] ?? 'physical');
$is_public   = isset($_POST['is_public']) ? (int)$_POST['is_public'] : 1;

if (!$name || !$category_id) {
    echo json_encode(["success"=>false,"msg"=>"Name & category required"]);
    exit;
}

# =========================
# INSERT PRODUCT (WITHOUT IMAGE FIRST)
# =========================
$stmt = $conn->prepare("
    INSERT INTO products
    (name, description, price, category_id, type, is_public)
    VALUES (?, ?, ?, ?, ?, ?)
");

$stmt->bind_param("ssdiss",
    $name,
    $description,
    $price,
    $category_id,
    $type,
    $is_public
);

if (!$stmt->execute()) {
    echo json_encode(["success"=>false,"msg"=>"Product insert failed"]);
    exit;
}

$product_id = $stmt->insert_id;

# =========================
# MULTIPLE IMAGE UPLOAD
# =========================
$uploaded_images = [];
$main_image = null;

if (!empty($_FILES['product_imgs']['name'][0])) {

    $targetDir = "../../uploads/products/";

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    foreach ($_FILES['product_imgs']['name'] as $key => $val) {

        $fileName = time() . "_" . basename($_FILES["product_imgs"]["name"][$key]);
        $targetFile = $targetDir . $fileName;

        $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','webp'];

        if (!in_array($fileType, $allowed)) continue;

        if (move_uploaded_file($_FILES["product_imgs"]["tmp_name"][$key], $targetFile)) {

            $image_url = "/uploads/products/" . $fileName;

            # FIRST IMAGE → MAIN IMAGE
            if ($key === 0) {
                $main_image = $image_url;
            }

            # Save to product_images table
            $position = $key + 1;

            $imgStmt = $conn->prepare("
                INSERT INTO product_images (product_id, image_url, position)
                VALUES (?, ?, ?)
            ");

            $imgStmt->bind_param("isi",
                $product_id,
                $image_url,
                $position
            );

            $imgStmt->execute();

            $uploaded_images[] = [
                "url"=>$image_url,
                "position"=>$position
            ];
        }
    }
}

# =========================
# UPDATE PRODUCT TABLE WITH MAIN IMAGE
# =========================
if ($main_image) {
    $updateStmt = $conn->prepare("
        UPDATE products SET product_img = ? WHERE id = ?
    ");

    $updateStmt->bind_param("si", $main_image, $product_id);
    $updateStmt->execute();
}

# =========================
# RESPONSE
# =========================
echo json_encode([
    "success"=>true,
    "msg"=>"Product created with gallery",
    "product_id"=>$product_id,
    "main_image"=>$main_image,
    "images"=>$uploaded_images
]);