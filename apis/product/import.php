<?php
header("Content-Type: application/json");

require '../../config/db.php';
require '../../config/jwt.php';
require '../../middleware/auth.php';

# =========================
# HELPERS
# =========================
function deleteFolder($folder) {
    if (!is_dir($folder)) return;
    foreach (glob($folder . '/*') as $file) {
        is_dir($file) ? deleteFolder($file) : @unlink($file);
    }
    @rmdir($folder);
}

function normalizeName($name) {
    $name = trim($name ?? '');
    $name = trim($name, "\"'");
    $name = str_replace("\\", "/", $name);
    $name = basename($name);
    return $name;
}

function isAllowedExt($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg','jpeg','png','webp'], true);
}

/**
 * Build index of images in extracted zip:
 * key: base filename without ANY image extensions (supports double ext)
 * ex: laptop_main.jpg.png -> laptop_main
 * ex: crm_main.png.jpeg   -> crm_main
 */
function buildImageIndex($baseDir) {
    $map = []; // base(lower) => fullpath (first wins)
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
        if (!$file->isFile()) continue;

        $filename = $file->getFilename();
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) continue;

        $lower = strtolower($filename);

        // remove one or multiple image extensions from end
        $base = preg_replace('/(\.(jpg|jpeg|png|webp))+$/i', '', $lower);

        if (!isset($map[$base])) {
            $map[$base] = $file->getPathname();
        }
    }

    return $map;
}

/**
 * Find file from index using CSV value:
 * CSV: laptop_main.jpg -> key laptop_main
 * CSV: crm_main.png    -> key crm_main
 */
function findFromIndex($index, $filename) {
    $filename = normalizeName($filename);
    if (!$filename) return null;

    $key = strtolower($filename);

    // remove one or multiple image extensions
    $key = preg_replace('/(\.(jpg|jpeg|png|webp))+$/i', '', $key);

    return $index[$key] ?? null;
}

# =========================
# 🔐 ADMIN AUTH
# =========================
$user = get_authenticated_user();
if (!$user || ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(["success"=>false,"msg"=>"Admin only"]);
    exit;
}

# =========================
# METHOD + FILE CHECK
# =========================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success"=>false,"msg"=>"POST only"]);
    exit;
}

if (empty($_FILES['file']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(["success"=>false,"msg"=>"ZIP required (field name: file)"]);
    exit;
}

# =========================
# ZIP EXTRACT
# =========================
$zip = new ZipArchive;
$tmpZip = $_FILES['file']['tmp_name'];

$rootExtractPath = "../../uploads/temp_" . time() . "_" . uniqid();
if (!is_dir($rootExtractPath)) mkdir($rootExtractPath, 0777, true);

if ($zip->open($tmpZip) === TRUE) {
    $zip->extractTo($rootExtractPath);
    $zip->close();
} else {
    deleteFolder($rootExtractPath);
    http_response_code(400);
    echo json_encode(["success"=>false,"msg"=>"Invalid ZIP"]);
    exit;
}

# =========================
# AUTO FIND CSV (supports nested folder)
# =========================
$csv = null;

$csvFiles = glob($rootExtractPath . "/*.csv");
if (!empty($csvFiles)) {
    $csv = $csvFiles[0];
} else {
    $folders = glob($rootExtractPath . "/*", GLOB_ONLYDIR);
    foreach ($folders as $f) {
        $innerCsv = glob($f . "/*.csv");
        if (!empty($innerCsv)) {
            $csv = $innerCsv[0];
            break;
        }
    }
}

if (!$csv || !file_exists($csv)) {
    deleteFolder($rootExtractPath);
    http_response_code(400);
    echo json_encode(["success"=>false,"msg"=>"products.csv missing"]);
    exit;
}

# =========================
# BUILD IMAGE INDEX ✅ (smart)
# =========================
$imageIndex = buildImageIndex($rootExtractPath);

# =========================
# DEST DIR
# =========================
$destDir = "../../uploads/products/";
if (!is_dir($destDir)) mkdir($destDir, 0777, true);

# =========================
# OPEN CSV
# =========================
$handle = fopen($csv, "r");
if (!$handle) {
    deleteFolder($rootExtractPath);
    http_response_code(500);
    echo json_encode(["success"=>false,"msg"=>"Unable to read CSV"]);
    exit;
}

fgetcsv($handle); // skip header

$inserted = 0;
$skipped  = 0;
$missing_images = [];

# =========================
# PREPARED STATEMENTS
# =========================
$catCheck = $conn->prepare("SELECT id FROM product_categories WHERE id=?");

$insertProduct = $conn->prepare("
    INSERT INTO products (name, description, price, category_id, type, is_public)
    VALUES (?, ?, ?, ?, ?, ?)
");

$updateMain = $conn->prepare("UPDATE products SET product_img = ? WHERE id = ?");

$insertImage = $conn->prepare("
    INSERT INTO product_images (product_id, image_url, position)
    VALUES (?, ?, ?)
");

# =========================
# LOOP CSV
# =========================
while (($row = fgetcsv($handle)) !== false) {

    if (count($row) < 6) { $skipped++; continue; }

    $name        = trim($row[0] ?? '');
    $description = trim($row[1] ?? '');
    $price       = (float)($row[2] ?? 0);
    $category_id = (int)($row[3] ?? 0);
    $type        = trim($row[4] ?? 'physical');
    $is_public   = isset($row[5]) ? (int)$row[5] : 1;

    $main_raw    = $row[6] ?? '';
    $gallery_raw = $row[7] ?? '';

    if (!$name || !$category_id) { $skipped++; continue; }

    # category validate
    $catCheck->bind_param("i", $category_id);
    $catCheck->execute();
    if ($catCheck->get_result()->num_rows === 0) { $skipped++; continue; }

    $conn->begin_transaction();

    try {
        # 1) insert product WITHOUT image
        $insertProduct->bind_param("ssdiss", $name, $description, $price, $category_id, $type, $is_public);
        if (!$insertProduct->execute()) throw new Exception("Product insert failed");
        $product_id = $insertProduct->insert_id;

        # 2) image list (main + gallery)
        $imgList = [];

        $mainName = normalizeName($main_raw);
        if ($mainName) $imgList[] = $mainName;

        if (!empty($gallery_raw)) {
            $parts = array_filter(array_map('trim', explode('|', $gallery_raw)));
            foreach ($parts as $p) {
                $n = normalizeName($p);
                if ($n) $imgList[] = $n;
            }
        }

        # unique keep order
        $seen = [];
        $ordered = [];
        foreach ($imgList as $n) {
            $k = strtolower($n);
            if (!isset($seen[$k])) { $seen[$k] = true; $ordered[] = $n; }
        }
        $imgList = $ordered;

        $main_url = null;
        $position = 1;

        foreach ($imgList as $imgName) {
            if (!isAllowedExt($imgName)) continue;

            $src = findFromIndex($imageIndex, $imgName);

            if (!$src || !file_exists($src)) {
                $missing_images[] = ["product"=>$name, "image"=>$imgName];
                continue;
            }

            $safe = basename($src); // keep actual stored name
            $newName = time() . "_" . uniqid() . "_" . $safe;
            $dest = $destDir . $newName;

            if (!@copy($src, $dest)) {
                $missing_images[] = ["product"=>$name, "image"=>$imgName, "reason"=>"copy_failed"];
                continue;
            }

            $url = "/uploads/products/" . $newName;

            if ($main_url === null) $main_url = $url;

            $insertImage->bind_param("isi", $product_id, $url, $position);
            $insertImage->execute();

            $position++;
        }

        # 3) update main image
        if ($main_url !== null) {
            $updateMain->bind_param("si", $main_url, $product_id);
            $updateMain->execute();
        }

        $conn->commit();
        $inserted++;

    } catch (Exception $e) {
        $conn->rollback();
        $skipped++;
    }
}

fclose($handle);

deleteFolder($rootExtractPath);

echo json_encode([
    "success" => true,
    "msg" => "Imported successfully. Images stored like create.php (main+gallery).",
    "inserted" => $inserted,
    "skipped" => $skipped,
    "missing_images_count" => count($missing_images),
    "missing_images" => $missing_images
]);