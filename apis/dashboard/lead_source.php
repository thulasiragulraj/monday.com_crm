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

$where=[]; $params=[]; $types="";
if($role==='sales'){ $where[]="l.assigned_to=?"; $params[]=$my_id; $types.="i"; }

$whereSql = count($where) ? "WHERE ".implode(" AND ",$where) : "";

$sql="SELECT ls.name AS source, COUNT(*) AS count
      FROM leads l
      LEFT JOIN lead_sources ls ON ls.id = l.source_id
      $whereSql
      GROUP BY ls.name
      ORDER BY count DESC";

$stmt=$conn->prepare($sql);
if($types!=="") $stmt->bind_param($types,...$params);
$stmt->execute();
$res=$stmt->get_result();

$data=[];
while($r=$res->fetch_assoc()){
  $data[]=["source"=>$r["source"] ?? "Unknown","count"=>(int)$r["count"]];
}

echo json_encode(["success"=>true,"data"=>$data]);