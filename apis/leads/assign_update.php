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

function normalizePhone($phone) {
    $phone = preg_replace('/\s+/', '', (string)$phone);
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    return $phone;
}

# =========================
# AUTH
# =========================
$user = get_authenticated_user();
if (!$user) fail(401, "Unauthorized");

$role = $user['role'] ?? '';
$my_id = (int)($user['id'] ?? 0);

if (!in_array($role, ['admin','manager','sales'], true)) {
    fail(403, "Access denied");
}

# =========================
# METHOD
# =========================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, "POST only");
}

# =========================
# INPUT (POST/GET/JSON)
# =========================
$input = $_POST;
if (!empty($_GET)) $input = array_merge($_GET, $input);

if (empty($input)) {
    $raw = file_get_contents("php://input");
    $json = json_decode($raw, true);
    if (is_array($json)) $input = $json;
}

$lead_id = isset($input['lead_id']) ? (int)$input['lead_id'] : 0;
if ($lead_id <= 0) fail(400, "lead_id required");

$new_status = array_key_exists('status', $input) ? clean($input['status']) : null;
$new_message = array_key_exists('message', $input) ? clean($input['message']) : null;

# assigned_to only for admin/manager
$new_assigned_to = array_key_exists('assigned_to', $input) ? (int)$input['assigned_to'] : null;
if ($role === 'sales') {
    $new_assigned_to = null; // sales cannot assign
}

# status validation (your leads enum shows: new, assigned, contacted, qualified, lost)
$allowedStatus = ['new','assigned','contacted','qualified','lost'];
if ($new_status !== null && $new_status !== '' && !in_array($new_status, $allowedStatus, true)) {
    fail(400, "Invalid status", ["allowed"=>$allowedStatus]);
}

# =========================
# LOAD LEAD (and role restriction)
# =========================
$leadStmt = $conn->prepare("
    SELECT id, name, phone, email, source_id, product_id, message, status, assigned_to
    FROM leads
    WHERE id=?
    LIMIT 1
");
$leadStmt->bind_param("i", $lead_id);
$leadStmt->execute();
$lead = $leadStmt->get_result()->fetch_assoc();

if (!$lead) fail(404, "Lead not found");

# sales can update only their assigned leads
if ($role === 'sales') {
    if ((int)($lead['assigned_to'] ?? 0) !== $my_id) {
        fail(403, "Sales can update only assigned leads");
    }
}

# If admin/manager gives assigned_to -> validate target is sales
if (($role === 'admin' || $role === 'manager') && $new_assigned_to !== null && $new_assigned_to > 0) {
    $u = $conn->prepare("SELECT id, role FROM users WHERE id=? LIMIT 1");
    $u->bind_param("i", $new_assigned_to);
    $u->execute();
    $ur = $u->get_result()->fetch_assoc();
    if (!$ur) fail(404, "assigned_to user not found");
    if (($ur['role'] ?? '') !== 'sales') fail(400, "assigned_to must be a sales user");
}

# =========================
# BUILD LEAD UPDATE
# =========================
$fields = [];
$params = [];
$types = "";

if ($new_status !== null && $new_status !== '') {
    $fields[] = "status=?";
    $params[] = $new_status;
    $types .= "s";
}

if ($new_message !== null) {
    $fields[] = "message=?";
    $params[] = $new_message;
    $types .= "s";
}

if ($new_assigned_to !== null) {
    # allow null assign? if passed 0 set NULL
    if ($new_assigned_to <= 0) {
        $fields[] = "assigned_to=NULL";
    } else {
        $fields[] = "assigned_to=?";
        $params[] = $new_assigned_to;
        $types .= "i";
    }
}

if (empty($fields)) {
    fail(400, "Nothing to update");
}

# =========================
# TRANSACTION
# =========================
$conn->begin_transaction();

try {

    # 1) update lead
    $sql = "UPDATE leads SET " . implode(", ", $fields) . " WHERE id=?";
    $params[] = $lead_id;
    $types .= "i";

    $upd = $conn->prepare($sql);
    $upd->bind_param($types, ...$params);

    if (!$upd->execute()) {
        throw new Exception("Lead update failed");
    }

    # 2) read updated lead (for customer creation)
    $lead2Stmt = $conn->prepare("
        SELECT id, name, phone, email, source_id, status, assigned_to
        FROM leads
        WHERE id=?
        LIMIT 1
    ");
    $lead2Stmt->bind_param("i", $lead_id);
    $lead2Stmt->execute();
    $lead2 = $lead2Stmt->get_result()->fetch_assoc();

    if (!$lead2) {
        throw new Exception("Lead re-fetch failed");
    }

    $created_customer = false;
    $customer_action = null;
    $customer_id = null;

    # 3) if status is contacted -> create customer
    if (($lead2['status'] ?? '') === 'contacted') {

        $c_name = clean($lead2['name'] ?? '');
        $c_phone = normalizePhone($lead2['phone'] ?? '');
        $c_email = clean($lead2['email'] ?? '');
        $c_source_id = (int)($lead2['source_id'] ?? 0);
        $c_assigned_to = $lead2['assigned_to'] !== null ? (int)$lead2['assigned_to'] : null;
        $created_from_lead_id = (int)$lead2['id'];

        # If already created from this lead -> skip
        $existsLead = $conn->prepare("SELECT id FROM customers WHERE created_from_lead_id=? LIMIT 1");
        $existsLead->bind_param("i", $created_from_lead_id);
        $existsLead->execute();
        $rowLead = $existsLead->get_result()->fetch_assoc();

        if ($rowLead) {
            $customer_id = (int)$rowLead['id'];
            $customer_action = "already_exists_for_lead";
        } else {

            # Try find existing customer by phone/email (to avoid duplicate customers)
            $found = null;

            if ($c_phone !== '') {
                $findP = $conn->prepare("SELECT id FROM customers WHERE phone=? LIMIT 1");
                $findP->bind_param("s", $c_phone);
                $findP->execute();
                $found = $findP->get_result()->fetch_assoc();
            }

            if (!$found && $c_email !== '') {
                $findE = $conn->prepare("SELECT id FROM customers WHERE email=? LIMIT 1");
                $findE->bind_param("s", $c_email);
                $findE->execute();
                $found = $findE->get_result()->fetch_assoc();
            }

            if ($found) {
                # update existing customer (attach lead reference if empty)
                $customer_id = (int)$found['id'];

                $updC = $conn->prepare("
                    UPDATE customers
                    SET name = COALESCE(NULLIF(?,''), name),
                        phone = COALESCE(NULLIF(?,''), phone),
                        email = COALESCE(NULLIF(?,''), email),
                        source_id = COALESCE(?, source_id),
                        assigned_to = ?,
                        created_from_lead_id = COALESCE(created_from_lead_id, ?)
                    WHERE id=?
                ");

                $sid = $c_source_id > 0 ? $c_source_id : null;
                $updC->bind_param(
                    "sssiiii",
                    $c_name,
                    $c_phone,
                    $c_email,
                    $sid,
                    $c_assigned_to,
                    $created_from_lead_id,
                    $customer_id
                );

                if (!$updC->execute()) {
                    throw new Exception("Customer update failed");
                }

                $customer_action = "updated_existing_customer";
            } else {
                # insert new customer
                $insC = $conn->prepare("
                    INSERT INTO customers
                    (name, phone, email, source_id, created_from_lead_id, assigned_to)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $sid = $c_source_id > 0 ? $c_source_id : null;

                # bind: name, phone, email, source_id, created_from_lead_id, assigned_to
                $insC->bind_param(
                    "sssiii",
                    $c_name,
                    $c_phone,
                    $c_email,
                    $sid,
                    $created_from_lead_id,
                    $c_assigned_to
                );

                if (!$insC->execute()) {
                    throw new Exception("Customer insert failed");
                }

                $customer_id = (int)$insC->insert_id;
                $created_customer = true;
                $customer_action = "created_new_customer";
            }
        }
    }

    $conn->commit();

} catch (Exception $e) {
    $conn->rollback();
    fail(500, "Update failed", ["error"=>$e->getMessage()]);
}

# =========================
# RESPONSE
# =========================
echo json_encode([
    "success" => true,
    "msg" => "Lead updated",
    "lead_id" => $lead_id,
    "updated_status" => $new_status,
    "updated_assigned_to" => $new_assigned_to,
    "customer_sync" => [
        "triggered" => ($new_status === 'contacted'),
        "customer_id" => $customer_id,
        "action" => $customer_action
    ]
]);