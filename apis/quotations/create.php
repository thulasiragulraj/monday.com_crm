<?php
header("Content-Type: application/json");

require '../../config/db.php';
require '../../config/jwt.php';
require '../../middleware/auth.php';

function fail($code,$msg,$extra=[]){
    http_response_code($code);
    echo json_encode(array_merge(["success"=>false,"msg"=>$msg],$extra));
    exit;
}
function clean($v){ return trim((string)$v); }

# ================= AUTH =================
$user = get_authenticated_user();
if(!$user) fail(401,"Unauthorized");

$role = $user['role'] ?? '';
$my_id = (int)($user['id'] ?? 0);

if(!in_array($role,['admin','manager','sales'],true))
    fail(403,"Access denied");

if($_SERVER['REQUEST_METHOD']!=='POST')
    fail(405,"POST only");

# ================= INPUT =================
$input=$_POST;
if(empty($input)){
    $raw=file_get_contents("php://input");
    $json=json_decode($raw,true);
    if(is_array($json)) $input=$json;
}

$customer_id = (int)($input['customer_id'] ?? 0);
$quote_date = clean($input['quote_date'] ?? date("Y-m-d"));
$valid_until = clean($input['valid_until'] ?? '');
$tax_percent = (float)($input['tax_percent'] ?? 0);
$discount_type = clean($input['discount_type'] ?? 'none');
$discount_value = (float)($input['discount_value'] ?? 0);
$notes = clean($input['notes'] ?? '');
$items = $input['items'] ?? [];

if($customer_id<=0) fail(400,"customer_id required");
if(empty($items) || !is_array($items))
    fail(400,"items required");

$allowedDiscount=['none','percent','flat'];
if(!in_array($discount_type,$allowedDiscount,true))
    fail(400,"Invalid discount_type");

# ================= CUSTOMER CHECK =================
$c=$conn->prepare("SELECT id,assigned_to FROM customers WHERE id=? LIMIT 1");
$c->bind_param("i",$customer_id);
$c->execute();
$customer=$c->get_result()->fetch_assoc();

if(!$customer) fail(404,"Customer not found");

$assigned_to = $customer['assigned_to']!==null ? (int)$customer['assigned_to'] : null;

# sales restriction
if($role==='sales'){
    if($assigned_to===null || $assigned_to!==$my_id){
        fail(403,"Sales can create quotation only for assigned customers");
    }
}

# ================= START TRANSACTION =================
$conn->begin_transaction();

try{

    # ------- quotation number generation -------
    $prefix = "QT-".date("Y")."-";
    $r=$conn->query("SELECT id FROM quotations ORDER BY id DESC LIMIT 1");
    $next=1;
    if($row=$r->fetch_assoc()){
        $next=((int)$row['id'])+1;
    }
    $quotation_no = $prefix.str_pad($next,4,"0",STR_PAD_LEFT);

    # insert quotation first (totals later update)
    $insQ=$conn->prepare("
        INSERT INTO quotations
        (quotation_no,customer_id,quote_date,valid_until,
         discount_type,discount_value,tax_percent,
         subtotal,tax_amount,grand_total,
         notes,status,created_by)
        VALUES (?,?,?,?,?,?,?,0,0,0,?,'draft',?)
    ");

    $vu = $valid_until !== '' ? $valid_until : null;

    $insQ->bind_param(
        "sisssddsi",
        $quotation_no,
        $customer_id,
        $quote_date,
        $vu,
        $discount_type,
        $discount_value,
        $tax_percent,
        $notes,
        $my_id
    );

    if(!$insQ->execute())
        throw new Exception("Quotation insert failed");

    $quotation_id=(int)$insQ->insert_id;

    # ================= ITEMS INSERT =================
    $subtotal = 0;

    $insItem=$conn->prepare("
        INSERT INTO quotation_items
        (quotation_id,product_id,product_name,unit_price,qty,discount_percent,line_total)
        VALUES (?,?,?,?,?,?,?)
    ");

    foreach($items as $it){

        $product_id = (int)($it['product_id'] ?? 0);
        $qty = (float)($it['qty'] ?? 1);
        $discount_percent = (float)($it['discount_percent'] ?? 0);

        if($product_id<=0) throw new Exception("Invalid product_id");

        # get product
        $p=$conn->prepare("SELECT id,name,price FROM products WHERE id=? LIMIT 1");
        $p->bind_param("i",$product_id);
        $p->execute();
        $prod=$p->get_result()->fetch_assoc();

        if(!$prod) throw new Exception("Product not found id=".$product_id);

        $product_name = $prod['name'];
        $unit_price = (float)($prod['price'] ?? 0);

        if(isset($it['unit_price']))
            $unit_price=(float)$it['unit_price'];

        $line = $unit_price * $qty;

        if($discount_percent>0){
            $line -= ($line * $discount_percent / 100);
        }

        $subtotal += $line;

        $insItem->bind_param(
            "iisdddd",
            $quotation_id,
            $product_id,
            $product_name,
            $unit_price,
            $qty,
            $discount_percent,
            $line
        );

        if(!$insItem->execute())
            throw new Exception("Item insert failed");
    }

    # ================= TOTAL CALC =================
    $after_discount = $subtotal;

    if($discount_type==='percent'){
        $after_discount -= ($subtotal * $discount_value / 100);
    }elseif($discount_type==='flat'){
        $after_discount -= $discount_value;
    }

    if($after_discount < 0) $after_discount = 0;

    $tax_amount = ($after_discount * $tax_percent) / 100;
    $grand_total = $after_discount + $tax_amount;

    # update totals
    $up=$conn->prepare("
        UPDATE quotations
        SET subtotal=?, tax_amount=?, grand_total=?
        WHERE id=?
    ");
    $up->bind_param("dddi",$subtotal,$tax_amount,$grand_total,$quotation_id);

    if(!$up->execute())
        throw new Exception("Total update failed");

    $conn->commit();

}catch(Exception $e){
    $conn->rollback();
    fail(500,"Quotation create failed",["error"=>$e->getMessage()]);
}

echo json_encode([
    "success"=>true,
    "msg"=>"Quotation created",
    "quotation_id"=>$quotation_id,
    "quotation_no"=>$quotation_no,
    "subtotal"=>$subtotal,
    "tax_amount"=>$tax_amount,
    "grand_total"=>$grand_total
]);