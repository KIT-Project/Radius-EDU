<?php
// Disposable UI fixtures only. No radcheck credentials; these accounts cannot authenticate.
// Run: php /tmp/seed-dashboard.php [--remove]
$p = new PDO('mysql:host=rdmariadb;dbname=rd', 'rd', 'rd', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function addRow(PDO $p, string $table, array $data): int {
    $keys=array_keys($data);
    $q=$p->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`',$keys).'`) VALUES ('.implode(',',array_fill(0,count($keys),'?')).')');
    $q->execute(array_values($data)); return (int)$p->lastInsertId();
}
$p->beginTransaction();
try {
    foreach (['radacct','radacct_history'] as $table) $p->exec("DELETE FROM $table WHERE acctsessionid LIKE 'demo-ui-%' AND username LIKE 'demo-ui-%'");
    $p->exec("DELETE FROM radpostauth WHERE username LIKE 'demo-ui-%' AND nasname IN ('192.0.2.10','192.0.2.11')");
    $p->exec("DELETE FROM radusergroup WHERE username LIKE 'demo-ui-%' AND groupname LIKE 'demo-ui-%'");
    $p->exec("DELETE FROM radgroupreply WHERE groupname LIKE 'demo-ui-%' AND comment='DEMO UI fixture'");
    $p->exec("DELETE FROM permanent_users WHERE cloud_id=23 AND username LIKE 'demo-ui-%'");
    $p->exec("DELETE FROM profile_components WHERE cloud_id=23 AND name LIKE 'demo-ui-%'");
    $p->exec("DELETE FROM profiles WHERE cloud_id=23 AND name LIKE 'demo-ui-%'");
    foreach (['na_realms','na_settings','na_states'] as $table) $p->exec("DELETE FROM $table WHERE na_id IN (SELECT id FROM nas WHERE cloud_id=23 AND shortname LIKE 'demo-ui-%')");
    $p->exec("DELETE FROM nas WHERE cloud_id=23 AND shortname LIKE 'demo-ui-%'");
    if (($argv[1]??'')!=='--remove') {
        $realm=$p->query('SELECT id,name FROM realms WHERE cloud_id=23 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!$realm) throw new RuntimeException('Cloud 23 realm missing');
        $now=gmdate('Y-m-d H:i:s');
        $stamp=['cloud_id'=>23,'created'=>$now,'modified'=>$now];
        $profiles=[];
        foreach (['student'=>28800,'teacher'=>28800,'staff'=>14400] as $group=>$timeout) {
            $name='demo-ui-'.$group; $component=$name.'-policy';
            $profiles[$group]=addRow($p,'profiles',['name'=>$name]+$stamp);
            addRow($p,'profile_components',['name'=>$component]+$stamp);
            addRow($p,'radusergroup',['username'=>$name,'groupname'=>$component,'priority'=>5]);
            foreach (['Session-Timeout'=>(string)$timeout,'Fortinet-Group-Name'=>$name] as $attribute=>$value) {
                addRow($p,'radgroupreply',['groupname'=>$component,'attribute'=>$attribute,'op'=>':=','value'=>$value,'comment'=>'DEMO UI fixture','created'=>$now,'modified'=>$now]);
            }
        }
        for ($n=0;$n<2;$n++) {
            $id=addRow($p,'nas',['nasname'=>'192.0.2.'.(10+$n),'shortname'=>'demo-ui-fgt-'.($n+1),'nasidentifier'=>'DEMO-FGT-'.($n+1),'type'=>'other','secret'=>bin2hex(random_bytes(20)),'description'=>'DEMO UI fixture - not a real FortiGate','timezone'=>'Asia/Bangkok','monitor'=>'off','record_auth'=>0]+$stamp);
            addRow($p,'na_realms',['na_id'=>$id,'realm_id'=>$realm['id'],'created'=>$now,'modified'=>$now]);
        }
        for ($i=1;$i<=12;$i++) {
            $group=$i<=6?'student':($i<=9?'teacher':'staff');
            $name='demo-ui-'.sprintf('%02d',$i).'@dev';
            $state=$i===11?'suspended':($i===12?'expired':'active');
            addRow($p,'permanent_users',['username'=>$name,'password'=>null,'name'=>'DEMO','surname'=>ucfirst($group).' '.sprintf('%02d',$i),'address'=>'','phone'=>'','email'=>$name.'.invalid','active'=>0,'admin_state'=>$state,'realm'=>$realm['name'],'realm_id'=>$realm['id'],'profile'=>'demo-ui-'.$group,'profile_id'=>$profiles[$group],'to_date'=>$i===12?gmdate('Y-m-d H:i:s',time()-86400):null]+$stamp);
            addRow($p,'radpostauth',['username'=>$name,'realm'=>$realm['name'],'pass'=>'','reply'=>$i<=10?'Access-Accept':'Access-Reject','nasname'=>'192.0.2.'.(10+$i%2),'authdate'=>$now]);
            if ($i===12) continue;
            $seconds=[420,1860,3900,8100,14400,27000,28800,1800,3600,7200,600][$i-1];
            $closed=$i>6;
            $end=time()-($closed?3600:0);
            $row=['acctsessionid'=>'demo-ui-session-'.sprintf('%02d',$i),'acctuniqueid'=>md5($name.'demo-ui'),'username'=>$name,'realm'=>$realm['name'],'groupname'=>'demo-ui-'.$group,'nasipaddress'=>'192.0.2.'.(10+$i%2),'nasidentifier'=>'DEMO-FGT-'.(1+$i%2),'nasporttype'=>'Wireless-802.11','acctstarttime'=>gmdate('Y-m-d H:i:s',$end-$seconds),'acctupdatetime'=>gmdate('Y-m-d H:i:s',$end),'acctstoptime'=>$closed?gmdate('Y-m-d H:i:s',$end):null,'acctsessiontime'=>$seconds,'acctinterval'=>300,'acctinputoctets'=>$i*5242880,'acctoutputoctets'=>$i*20971520,'callingstationid'=>sprintf('02-00-00-00-00-%02d',$i),'calledstationid'=>'DEMO-WIFI','framedipaddress'=>'192.0.2.'.(100+$i),'acctauthentic'=>'RADIUS','acctterminatecause'=>$closed?['Session-Timeout','User-Request','Idle-Timeout','Admin-Reset','User-Request'][$i-7]:''];
            addRow($p,'radacct',$row);
            if ($closed) addRow($p,'radacct_history',$row);
        }
    }
    $p->commit();
    foreach (['users'=>"SELECT COUNT(*) FROM permanent_users WHERE cloud_id=23 AND username LIKE 'demo-ui-%'",'profiles'=>"SELECT COUNT(*) FROM profiles WHERE cloud_id=23 AND name LIKE 'demo-ui-%'",'nas'=>"SELECT COUNT(*) FROM nas WHERE cloud_id=23 AND shortname LIKE 'demo-ui-%'",'online'=>"SELECT COUNT(*) FROM radacct WHERE acctsessionid LIKE 'demo-ui-%' AND acctstoptime IS NULL",'history'=>"SELECT COUNT(*) FROM radacct_history WHERE acctsessionid LIKE 'demo-ui-%'",'auth_logs'=>"SELECT COUNT(*) FROM radpostauth WHERE username LIKE 'demo-ui-%'"] as $label=>$q) echo $label.': '.$p->query($q)->fetchColumn().PHP_EOL;
} catch (Throwable $e) { if ($p->inTransaction()) $p->rollBack(); throw $e; }
