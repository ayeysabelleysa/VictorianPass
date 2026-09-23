<?php
header('Content-Type: text/plain');
$con = @new mysqli('127.0.0.1','root','','victorianpass_db');
if ($con->connect_errno) { echo 'CONNECT ERR: '.$con->connect_error.PHP_EOL; exit; }

$targets = ['ecopoint_waste_sessions','ecopoint_waste_items','ecopoint_waste_sessions_old','point_transactions'];
foreach ($targets as $t) {
    $res = $con->query("SHOW TABLES LIKE '$t'");
    $exists = $res && $res->num_rows > 0;
    echo "== $t : " . ($exists ? 'EXISTS' : 'MISSING') . " ==\n";
    if (!$exists) { echo "   (".$con->error.")\n\n"; continue; }
    $cols = $con->query("SHOW COLUMNS FROM `$t`");
    while ($c = $cols->fetch_assoc()) {
        echo "   ".str_pad($c['Field'],26)." ".$c['Type']."  comment=".($c['Comment'] ?? '')."\n";
    }
    echo "\n";
}

// rate columns anywhere
echo "=== columns containing kg / rate / weight across all eco tables ===\n";
$eco=[];
$tbls=$con->query("SHOW TABLES");
while(($r=$tbls->fetch_row()) && $r){ if(stripos($r[0],'ecopoint')===0 || stripos($r[0],'eco_')===0) $eco[]=$r[0]; }
foreach($eco as $t){
    $cols=$con->query("SHOW COLUMNS FROM `$t`");
    if(!$cols) continue;
    while($c=$cols->fetch_assoc()){
        if(preg_match('/(kg|weight|rate|pts)/i',$c['Field'])){
            echo str_pad($t,34).' :: '.str_pad($c['Field'],26).' '.$c['Type']."  comment=".($c['Comment'] ?? '')."\n";
        }
    }
}
echo "\n=== point_transactions row sample (weight_kg field) ===\n";
$r=$con->query("SELECT id, weight_kg, weight_g, created_at FROM point_transactions ORDER BY id DESC LIMIT 90");
if(!$r){ echo "(no point_transactions or query err)\n"; }
if($r) while($x=$r->fetch_assoc()){ echo "  id=".$x['id']."  weight_kg=".($x['weight_kg']??'-')."  weight_g=".($x['weight_g']??'-')."\n"; }
