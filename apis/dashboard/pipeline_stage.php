<?php
header("Content-Type: application/json");
require '../../config/db.php';
require '../../config/jwt.php';
require '../../middleware/auth.php';

function fail($c,$m,$e=[]){ http_response_code($c); echo json_encode(array_merge(["success"=>false,"msg"=>$m],$e)); exit; }

$user = get_authenticated_user();
if(!$user) fail(401,"Unauthorized");

$role=$user['role'] ?? '';
$my_id=(int)($user['id'] ?? 0);
if(!in_array($role,['admin','manager','sales'],true)) fail(403,"Access denied");
if($_SERVER['REQUEST_METHOD']!=='GET') fail(405,"GET only");

$where=[]; $params=[]; $types="";
if($role==='sales'){ $where[]="owner=?"; $params[]=$my_id; $types.="i"; }

$whereSql = count($where) ? "WHERE ".implode(" AND ",$where) : "";

$sql="SELECT stage,
             COUNT(*) as count,
             COALESCE(SUM(value),0) as value
      FROM deals
      $whereSql
      GROUP BY stage";

$stmt=$conn->prepare($sql);
if($types!=="") $stmt->bind_param($types,...$params);
$stmt->execute();
$res=$stmt->get_result();

$data=[];
while($r=$res->fetch_assoc()){
  $data[]=[
    "stage"=>$r["stage"],
    "count"=>(int)$r["count"],
    "value"=>(float)$r["value"]
  ];
}

echo json_encode(["success"=>true,"data"=>$data]);