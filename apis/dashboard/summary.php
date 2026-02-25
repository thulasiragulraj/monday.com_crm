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
if (!$user) fail(401,"Unauthorized");

$role  = $user['role'] ?? '';
$my_id = (int)($user['id'] ?? 0);

if (!in_array($role, ['admin','manager','sales'], true)) fail(403,"Access denied");
if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail(405,"GET only");

/* -----------------------------
  Date filter (optional)
  format: YYYY-MM-DD
----------------------------- */
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');

function validDate($d){
  if ($d === '') return true;
  $t = DateTime::createFromFormat('Y-m-d', $d);
  return $t && $t->format('Y-m-d') === $d;
}
if (!validDate($date_from) || !validDate($date_to)) fail(400,"Invalid date format. Use YYYY-MM-DD");

$rangeWhere = "";
$rangeParams = [];
$rangeTypes  = "";

// We will apply range to created_at / won_at / lost_at depending on query.
// For summary, we apply range separately inside each query.

$response = [
  "success" => true,
  "filters" => [
    "date_from" => $date_from,
    "date_to"   => $date_to
  ],
  "data" => []
];

/* -----------------------------
  1) Leads totals + by status
  sales => only leads.assigned_to = me
----------------------------- */
$leadsWhere = [];
$leadsParams = [];
$leadsTypes = "";

if ($role === 'sales') {
  $leadsWhere[] = "assigned_to = ?";
  $leadsParams[] = $my_id;
  $leadsTypes .= "i";
}

if ($date_from !== '') {
  $leadsWhere[] = "DATE(created_at) >= ?";
  $leadsParams[] = $date_from;
  $leadsTypes .= "s";
}
if ($date_to !== '') {
  $leadsWhere[] = "DATE(created_at) <= ?";
  $leadsParams[] = $date_to;
  $leadsTypes .= "s";
}

$leadsWhereSql = count($leadsWhere) ? ("WHERE ".implode(" AND ", $leadsWhere)) : "";

$sql = "SELECT status, COUNT(*) AS c
        FROM leads
        $leadsWhereSql
        GROUP BY status";
$stmt = $conn->prepare($sql);
if ($leadsTypes !== "") $stmt->bind_param($leadsTypes, ...$leadsParams);
$stmt->execute();
$res = $stmt->get_result();

$byStatus = [];
$totalLeads = 0;
while($row = $res->fetch_assoc()){
  $byStatus[$row['status']] = (int)$row['c'];
  $totalLeads += (int)$row['c'];
}

$response["data"]["leads"] = [
  "total" => $totalLeads,
  "by_status" => $byStatus
];

/* -----------------------------
  2) Active Deals: stage distribution + pipeline value
  sales => deals.owner = me
  NOTE: Active deals table = deals (stage prospect/negotiation)
----------------------------- */
$dealsWhere = [];
$dealsParams = [];
$dealsTypes = "";

if ($role === 'sales') {
  $dealsWhere[] = "owner = ?";
  $dealsParams[] = $my_id;
  $dealsTypes .= "i";
}

if ($date_from !== '') {
  $dealsWhere[] = "DATE(created_at) >= ?";
  $dealsParams[] = $date_from;
  $dealsTypes .= "s";
}
if ($date_to !== '') {
  $dealsWhere[] = "DATE(created_at) <= ?";
  $dealsParams[] = $date_to;
  $dealsTypes .= "s";
}

$dealsWhereSql = count($dealsWhere) ? ("WHERE ".implode(" AND ", $dealsWhere)) : "";

$sql = "SELECT stage, COUNT(*) AS c, COALESCE(SUM(value),0) AS v
        FROM deals
        $dealsWhereSql
        GROUP BY stage";
$stmt = $conn->prepare($sql);
if ($dealsTypes !== "") $stmt->bind_param($dealsTypes, ...$dealsParams);
$stmt->execute();
$res = $stmt->get_result();

$dealByStage = [];
$pipelineValue = 0.0;
$totalDeals = 0;

while($row = $res->fetch_assoc()){
  $stage = $row['stage'] ?? 'unknown';
  $dealByStage[$stage] = [
    "count" => (int)$row['c'],
    "value" => (float)$row['v']
  ];
  $totalDeals += (int)$row['c'];

  if (in_array($stage, ['prospect','negotiation'], true)) {
    $pipelineValue += (float)$row['v'];
  }
}

$response["data"]["deals"] = [
  "total" => $totalDeals,
  "by_stage" => $dealByStage,
  "pipeline_value" => $pipelineValue
];

/* -----------------------------
  3) Won totals (deals_won.won_at)
----------------------------- */
$wonWhere = [];
$wonParams = [];
$wonTypes = "";

if ($role === 'sales') {
  $wonWhere[] = "owner = ?";
  $wonParams[] = $my_id;
  $wonTypes .= "i";
}
if ($date_from !== '') {
  $wonWhere[] = "DATE(won_at) >= ?";
  $wonParams[] = $date_from;
  $wonTypes .= "s";
}
if ($date_to !== '') {
  $wonWhere[] = "DATE(won_at) <= ?";
  $wonParams[] = $date_to;
  $wonTypes .= "s";
}
$wonWhereSql = count($wonWhere) ? ("WHERE ".implode(" AND ", $wonWhere)) : "";

$sql = "SELECT COUNT(*) AS c, COALESCE(SUM(value),0) AS v, COALESCE(AVG(value),0) AS av
        FROM deals_won
        $wonWhereSql";
$stmt = $conn->prepare($sql);
if ($wonTypes !== "") $stmt->bind_param($wonTypes, ...$wonParams);
$stmt->execute();
$won = $stmt->get_result()->fetch_assoc();

$response["data"]["won"] = [
  "count" => (int)$won['c'],
  "value" => (float)$won['v'],
  "avg_deal_size" => (float)$won['av']
];

/* -----------------------------
  4) Lost totals (deals_lost.lost_at)
----------------------------- */
$lostWhere = [];
$lostParams = [];
$lostTypes = "";

if ($role === 'sales') {
  $lostWhere[] = "owner = ?";
  $lostParams[] = $my_id;
  $lostTypes .= "i";
}
if ($date_from !== '') {
  $lostWhere[] = "DATE(lost_at) >= ?";
  $lostParams[] = $date_from;
  $lostTypes .= "s";
}
if ($date_to !== '') {
  $lostWhere[] = "DATE(lost_at) <= ?";
  $lostParams[] = $date_to;
  $lostTypes .= "s";
}
$lostWhereSql = count($lostWhere) ? ("WHERE ".implode(" AND ", $lostWhere)) : "";

$sql = "SELECT COUNT(*) AS c, COALESCE(SUM(value),0) AS v
        FROM deals_lost
        $lostWhereSql";
$stmt = $conn->prepare($sql);
if ($lostTypes !== "") $stmt->bind_param($lostTypes, ...$lostParams);
$stmt->execute();
$lost = $stmt->get_result()->fetch_assoc();

$response["data"]["lost"] = [
  "count" => (int)$lost['c'],
  "value" => (float)$lost['v']
];

/* -----------------------------
  5) Conversion rate
----------------------------- */
$wonCount = $response["data"]["won"]["count"];
$lostCount = $response["data"]["lost"]["count"];
$den = ($wonCount + $lostCount);
$conversion = $den > 0 ? round(($wonCount / $den) * 100, 2) : 0;

$response["data"]["conversion_rate"] = $conversion;

echo json_encode($response);