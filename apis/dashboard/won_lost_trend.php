<?php
header("Content-Type: application/json");
require '../../config/db.php';
require '../../config/jwt.php';
require '../../middleware/auth.php';

function fail($c,$m,$e=[]){ http_response_code($c); echo json_encode(array_merge(["success"=>false,"msg"=>$m],$e)); exit; }

$user=get_authenticated_user();
if(!$user) fail(401,"Unauthorized");

$role=$user['role'] ?? '';
$my_id=(int)($user['id'] ?? 0);
if(!in_array($role,['admin','manager','sales'],true)) fail(403,"Access denied");
if($_SERVER['REQUEST_METHOD']!=='GET') fail(405,"GET only");

$group_by = strtolower(trim($_GET['group_by'] ?? 'day'));
if(!in_array($group_by,['day','month'],true)) fail(400,"group_by must be day or month");

$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');

$fmt = ($group_by==='month') ? "%Y-%m" : "%Y-%m-%d";

/* won query */
$w=[]; $wp=[]; $wt="";
if($role==='sales'){ $w[]="owner=?"; $wp[]=$my_id; $wt.="i"; }
if($date_from!==''){ $w[]="DATE(won_at) >= ?"; $wp[]=$date_from; $wt.="s"; }
if($date_to!==''){ $w[]="DATE(won_at) <= ?"; $wp[]=$date_to; $wt.="s"; }
$wSql = count($w) ? "WHERE ".implode(" AND ",$w) : "";

$sql = "SELECT DATE_FORMAT(won_at,'$fmt') AS p, COUNT(*) c, COALESCE(SUM(value),0) v
        FROM deals_won
        $wSql
        GROUP BY p
        ORDER BY p";
$stmt=$conn->prepare($sql);
if($wt!=="") $stmt->bind_param($wt,...$wp);
$stmt->execute();
$wonRes=$stmt->get_result();

$wonMap=[];
while($r=$wonRes->fetch_assoc()){
  $wonMap[$r['p']] = ["count"=>(int)$r['c'], "value"=>(float)$r['v']];
}

/* lost query */
$l=[]; $lp=[]; $lt="";
if($role==='sales'){ $l[]="owner=?"; $lp[]=$my_id; $lt.="i"; }
if($date_from!==''){ $l[]="DATE(lost_at) >= ?"; $lp[]=$date_from; $lt.="s"; }
if($date_to!==''){ $l[]="DATE(lost_at) <= ?"; $lp[]=$date_to; $lt.="s"; }
$lSql = count($l) ? "WHERE ".implode(" AND ",$l) : "";

$sql = "SELECT DATE_FORMAT(lost_at,'$fmt') AS p, COUNT(*) c, COALESCE(SUM(value),0) v
        FROM deals_lost
        $lSql
        GROUP BY p
        ORDER BY p";
$stmt=$conn->prepare($sql);
if($lt!=="") $stmt->bind_param($lt,...$lp);
$stmt->execute();
$lostRes=$stmt->get_result();

$lostMap=[];
while($r=$lostRes->fetch_assoc()){
  $lostMap[$r['p']] = ["count"=>(int)$r['c'], "value"=>(float)$r['v']];
}

/* merge */
$allPeriods = array_unique(array_merge(array_keys($wonMap), array_keys($lostMap)));
sort($allPeriods);

$out=[];
foreach($allPeriods as $p){
  $out[]=[
    "period"=>$p,
    "won_count"=>$wonMap[$p]["count"] ?? 0,
    "won_value"=>$wonMap[$p]["value"] ?? 0,
    "lost_count"=>$lostMap[$p]["count"] ?? 0,
    "lost_value"=>$lostMap[$p]["value"] ?? 0
  ];
}

echo json_encode(["success"=>true,"group_by"=>$group_by,"data"=>$out]);