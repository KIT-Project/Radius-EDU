<?php
// Disposable local DB only. Isolated PAP listener on 19120; never a production NAS.
require '/var/www/html/cake4/rd_cake/vendor/autoload.php';
require '/var/www/html/cake4/rd_cake/config/bootstrap.php';
require '/var/www/html/cake4/rd_cake/setup/scripts/radius/DeviceSessionReplacement.php';
use Cake\ORM\TableRegistry;
$table = TableRegistry::getTableLocator()->get('PermanentUsers');
$db = $table->getConnection();
$c = $db->config();
$connect = fn() => new PDO('mysql:host='.$c['host'].';dbname='.$c['database'].';charset=utf8mb4', $c['username'], $c['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo = $connect();
$source = $table->find()->first();
if (!$source) throw new RuntimeException('Need a local Realm/Profile fixture');
$data = $source->toArray();
unset($data['id'], $data['created'], $data['modified'], $data['description']);
$username = '__device_limit_' . bin2hex(random_bytes(4));
$data = array_replace($data, ['username'=>$username, 'password'=>'device-limit-fixture', 'token'=>'', 'mac_address'=>'', 'static_ip'=>'', 'session_limit'=>3]);
$nasIp = '127.0.0.99';
$entity = null; $nasId = null; $child = null; $dynamicId = null;
$modeFile = tempnam('/tmp','replacement-mock-'); file_put_contents($modeFile,'normal');
$secretFile = tempnam('/tmp','device-limit-secret-'); chmod($secretFile,0600);
$conf = file_get_contents('/etc/freeradius/3.0/clients.conf');
if (!preg_match('/client localhost\s*\{.*?secret\s*=\s*([^\s#]+)/s',$conf,$match)) throw new RuntimeException('Missing localhost client');
file_put_contents($secretFile,trim($match[1],'"')."\n");
$assert = function($value,$label) { if (!$value) throw new RuntimeException($label); echo "$label: PASS\n"; };
$active = fn() => $db->execute('SELECT framedipaddress FROM radacct WHERE username=? AND acctstoptime IS NULL ORDER BY acctstarttime,radacctid',[$username])->fetchAll('assoc');
$add = function($ip,$age=0,$closed=false) use($db,$username,$nasIp) {
    $db->insert('radacct',['username'=>$username,'acctsessionid'=>bin2hex(random_bytes(8)),'acctuniqueid'=>bin2hex(random_bytes(16)),
        'nasipaddress'=>$nasIp,'nasidentifier'=>'FortiGate-Test','framedipaddress'=>$ip,'callingstationid'=>'4C-D5-87-51-B5-40',
        'acctinputoctets'=>0,'acctoutputoctets'=>0,'acctsessiontime'=>0,'acctstarttime'=>date('Y-m-d H:i:s',time()-$age),
        'acctupdatetime'=>date('Y-m-d H:i:s'),'acctstoptime'=>$closed ? date('Y-m-d H:i:s') : null]);
};
$auth = function($label,$expected,$password='device-limit-fixture',$ip='192.0.2.4',$nas='FortiGate-Test') use($username,$secretFile,$nasIp,$assert) {
    $proc=proc_open(['radclient','-x','-r','1','-t','14','-S',$secretFile,'127.0.0.1:19120','auth'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    fwrite($pipes[0],"User-Name = \"$username\"\nUser-Password = \"$password\"\nNAS-Identifier = \"$nas\"\nNAS-IP-Address = $nasIp\n".($ip ? "Framed-IP-Address = $ip\n" : '')."Message-Authenticator = 0x00\n");
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]);proc_close($proc);
    $assert(str_contains($out,'Received '.$expected),$label);
};
try {
    $entity=$table->newEntity($data); $table->saveOrFail($entity);
    foreach([-1,21,'1.5','abc'] as $invalid) $assert($table->patchEntity($table->get($entity->id),['session_limit'=>$invalid])->hasErrors(),'Invalid limit '. $invalid);
    if ($db->execute('SELECT id FROM nas WHERE nasname=?',[$nasIp])->fetch('assoc')) throw new RuntimeException('Test NAS IP already configured');
    $db->insert('nas',['nasname'=>$nasIp,'nasidentifier'=>'FortiGate-Test','cloud_id'=>$source->cloud_id,'type'=>'FortiGate-COA','secret'=>'replacement-fixture','coa_port'=>19399]);
    $nasId=$db->execute('SELECT id FROM nas WHERE nasname=?',[$nasIp])->fetch('assoc')['id'];
    $auth('Empty account accepts','Access-Accept');
    $add('192.0.2.1',180); $add('192.0.2.2',120); $add('192.0.2.3',60);
    $auth('Bad password rejects before any eviction','Access-Reject','wrong-password');
    $assert(count($active())===3,'Bad password leaves all active rows');
    $auth('Same NAS/IP reauthentication accepts','Access-Accept','device-limit-fixture','192.0.2.1');
    $auth('Other NAS unaffected','Access-Accept','device-limit-fixture','192.0.2.4','Other-Test');
    try {(new DeviceSessionReplacement($pdo,fn()=>false))->replace($username,'192.0.2.4',$nasIp); throw new LogicException('Missing ACK allowed');}
    catch(RuntimeException $e) {$assert($e->getMessage()==='No Disconnect ACK','NAS settings lookup and missing ACK guard: '.$e->getMessage());}
    $auth('No CoA response rejects new login','Access-Reject');
    $assert(count($active())===3,'Missing ACK preserves active accounting');
    // Local mock verifies actual radclient request and returns an authenticated ACK.
    $socket=stream_socket_server("udp://$nasIp:19399",$errno,$error,STREAM_SERVER_BIND);
    if (!$socket) throw new RuntimeException($error);
    $child=pcntl_fork();
    if ($child===0) {
        $childDb=$connect();
        while(true) {
            $peer='';$packet=stream_socket_recvfrom($socket,4096,0,$peer); if (!$packet) continue;
            $u='';$clientIp='';
            for($offset=20;$offset<strlen($packet);) {
                $type=ord($packet[$offset]);$length=ord($packet[$offset+1]);if($length<2) exit(2);
                $value=substr($packet,$offset+2,$length-2);if($type===1)$u=$value;if($type===8)$clientIp=inet_ntop($value);$offset+=$length;
            }
            if(ord($packet[0])!==40 || $u!==$username || !$clientIp) exit(3);
            $mode=trim(file_get_contents($modeFile));
            $head=chr($mode==='nak' ? 42 : 41).$packet[1].pack('n',20);
            $reply=$head.md5($head.substr($packet,4,16).($mode==='forged' ? 'incorrect-fixture' : 'replacement-fixture'),true);
            stream_socket_sendto($socket,$reply,0,$peer);
            if ($mode!=='normal') continue;
            // Simulated NAS Accounting Stop, scoped strictly to this fixture.
            $q=$childDb->prepare('UPDATE radacct SET acctstoptime=NOW() WHERE username=? AND nasipaddress=? AND framedipaddress=? AND acctstoptime IS NULL');
            $q->execute([$username,$nasIp,$clientIp]);
        }
    }
    fclose($socket);
    file_put_contents($modeFile,'forged');
    $auth('Forged ACK rejected','Access-Reject');
    $assert(count($active())===3,'Forged ACK preserves active rows');
    file_put_contents($modeFile,'nak');
    $auth('Authenticated NAK rejected','Access-Reject');
    $assert(count($active())===3,'NAK preserves active rows');
    file_put_contents($modeFile,'normal');
    $auth('Fourth device accepts after authenticated Disconnect ACK and Stop','Access-Accept');
    $assert(array_column($active(),'framedipaddress')===['192.0.2.2','192.0.2.3'],'Oldest IP evicted; newer IPs preserved despite shared MAC');
    $add('192.0.2.4');
    $auth('Missing request IP still replaces oldest','Access-Accept','device-limit-fixture',null);
    $assert(!in_array('192.0.2.2',array_column($active(),'framedipaddress')),'Missing request IP cannot reuse shared MAC');
    $entity=$table->patchEntity($entity,['session_limit'=>1]);$table->saveOrFail($entity);
    $auth('Reduced limit removes enough oldest devices','Access-Accept','device-limit-fixture','192.0.2.5');
    $assert(count($active())===0,'Reduced limit releases sufficient slots');
    $add('192.0.2.5'); $add('192.0.2.6',0,true);
    $auth('Closed history ignored and existing IP accepted','Access-Accept','device-limit-fixture','192.0.2.5');
    $entity=$table->patchEntity($entity,['session_limit'=>0]);$table->saveOrFail($entity);
    $auth('Unlimited mode accepts without eviction','Access-Accept');
    $assert(count($active())===1,'Unlimited preserves existing sessions');
    $entity=$table->patchEntity($entity,['session_limit'=>1]);$table->saveOrFail($entity);
    $service=new DeviceSessionReplacement($pdo,fn()=>true);
    try {$service->replace($username,'192.0.2.7',$nasIp);throw new LogicException('Missing Stop allowed');}
    catch(RuntimeException $e) {$assert($e->getMessage()==='Missing Accounting Stop','ACK without Accounting Stop fails closed');}
    $assert(count($active())===1,'ACK alone never fabricates Accounting Stop');
    $db->insert('dynamic_clients',['nasidentifier'=>'FortiGate-Test','name'=>$username,'cloud_id'=>$source->cloud_id,'type'=>'FortiGate-COA','created'=>date('Y-m-d H:i:s'),'modified'=>date('Y-m-d H:i:s')]);
    $dynamicId=$db->execute('SELECT id FROM dynamic_clients WHERE name=?',[$username])->fetch('assoc')['id'];
    foreach(['secret'=>'replacement-fixture','coa_port'=>'19399'] as $key=>$value) $db->insert('dynamic_client_settings',['dynamic_client_id'=>$dynamicId,'name'=>$key,'value'=>$value,'created'=>date('Y-m-d H:i:s'),'modified'=>date('Y-m-d H:i:s')]);
    $db->update('nas',['secret'=>'deliberately-incorrect-fixture'],['id'=>$nasId]);
    $auth('Dynamic NAS reads its own secret and CoA port','Access-Accept');
    $assert(count($active())===0,'Dynamic NAS replacement closes old session');
    echo "All replacement tests passed\n";
} finally {
    if($child>0){posix_kill($child,SIGTERM);pcntl_waitpid($child,$status);}
    // Fresh connection: child exits can invalidate inherited ORM sockets.
    $cleanup=$connect();
    foreach(['radacct','radcheck','radreply','radusergroup','permanent_users'] as $name){$q=$cleanup->prepare("DELETE FROM $name WHERE username=?");$q->execute([$username]);}
    if($dynamicId){$q=$cleanup->prepare('DELETE FROM dynamic_client_settings WHERE dynamic_client_id=?');$q->execute([$dynamicId]);$q=$cleanup->prepare('DELETE FROM dynamic_clients WHERE id=?');$q->execute([$dynamicId]);}
    if($nasId){$q=$cleanup->prepare('DELETE FROM nas WHERE id=?');$q->execute([$nasId]);}
    unlink($secretFile); unlink($modeFile);
}
