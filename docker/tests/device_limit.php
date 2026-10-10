<?php
// Run only against a disposable local DB and the isolated test listener on 19120.
require '/var/www/html/cake4/rd_cake/vendor/autoload.php';
require '/var/www/html/cake4/rd_cake/config/bootstrap.php';
use Cake\ORM\TableRegistry;
$table = TableRegistry::getTableLocator()->get('PermanentUsers');
$db = $table->getConnection();
$source = $table->find()->first();
if (!$source) throw new RuntimeException('Need a local Realm/Profile fixture');
$data = $source->toArray();
unset($data['id'], $data['created'], $data['modified'], $data['description']);
$username = '__device_limit_' . bin2hex(random_bytes(4));
$data = array_replace($data, ['username'=>$username, 'password'=>'device-limit-fixture',
    'token'=>'', 'mac_address'=>'', 'static_ip'=>'', 'session_limit'=>3]);
$entity = null;
$conf = file_get_contents('/etc/freeradius/3.0/clients.conf');
if (!preg_match('/client localhost\s*\{.*?secret\s*=\s*([^\s#]+)/s', $conf, $match)) throw new RuntimeException('Missing localhost client');
$secretFile = tempnam('/tmp', 'device-limit-secret-');
chmod($secretFile, 0600);
file_put_contents($secretFile, trim($match[1], '"') . "\n");
$authenticate = function ($label, $expected, $mac='AA:BB:CC:00:00:04', $nas='FortiGate-Test') use ($username, $secretFile) {
    $pipes = [];
    $process = proc_open(['radclient','-x','-r','1','-t','2','-S',$secretFile,'127.0.0.1:19120','auth'],
        [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
    fwrite($pipes[0], 'User-Name = "'.$username.'"' . "\nUser-Password = \"device-limit-fixture\"\nNAS-Identifier = \"$nas\"\nMessage-Authenticator = 0x00\n" . ($mac === null ? '' : "Calling-Station-Id = \"$mac\"\n"));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    if (!str_contains($output, 'Received '.$expected)) throw new RuntimeException($label.' failed: '.$output);
    echo $label . ": PASS\n";
};
$add = function ($mac, $closed=false) use ($db, $username) {
    $db->insert('radacct', ['username'=>$username, 'acctsessionid'=>bin2hex(random_bytes(8)),
        'acctuniqueid'=>bin2hex(random_bytes(16)), 'nasipaddress'=>'127.0.0.1', 'nasidentifier'=>'FortiGate-Test',
        'acctinputoctets'=>0, 'acctoutputoctets'=>0, 'acctsessiontime'=>0, 'callingstationid'=>$mac, 'acctstarttime'=>date('Y-m-d H:i:s'), 'acctupdatetime'=>date('Y-m-d H:i:s'),
        'acctstoptime'=>$closed ? date('Y-m-d H:i:s') : null, 'framedipaddress'=>'192.0.2.10']);
};
try {
    $entity = $table->newEntity($data); $table->saveOrFail($entity);
    if ($table->get($entity->id)->session_limit !== 3) throw new RuntimeException('Limit not saved');
    foreach ([-1, 21, '1.5', 'abc'] as $invalid) {
        $bad = $table->patchEntity($table->get($entity->id), ['session_limit'=>$invalid]);
        if (!$bad->hasErrors()) throw new RuntimeException('Invalid limit accepted');
    }
    echo "DB persistence and invalid limits: PASS\n";
    $authenticate('No active devices', 'Access-Accept');
    $add('aa-bb-cc-00-00-01'); $add('aa-bb-cc-00-00-02');
    $authenticate('Third device allowed', 'Access-Accept');
    $add('aa-bb-cc-00-00-03');
    $authenticate('Fourth device rejected', 'Access-Reject');
    $authenticate('Existing device reauthentication', 'Access-Accept', 'AA:BB:CC:00:00:01');
    $authenticate('Missing MAC still enforces limit', 'Access-Reject', null);
    $authenticate('Other NAS unaffected', 'Access-Accept', 'AA:BB:CC:00:00:04', 'Other-Test');
    $db->update('radacct', ['acctstoptime'=>date('Y-m-d H:i:s')], ['username'=>$username,'callingstationid'=>'aa-bb-cc-00-00-03']);
    $authenticate('Slot released after Stop', 'Access-Accept');
    $add('AA:BB:CC:00:00:01');
    $authenticate('Duplicate rows for same MAC count once', 'Access-Accept');
    $add('aa-bb-cc-00-00-05', true);
    $authenticate('Closed history ignored', 'Access-Accept');
    $entity = $table->patchEntity($entity, ['session_limit'=>1]); $table->saveOrFail($entity);
    $authenticate('Edited limit enforced', 'Access-Reject');
    $entity = $table->patchEntity($entity, ['session_limit'=>0]); $table->saveOrFail($entity);
    $authenticate('Zero means unlimited', 'Access-Accept');
} finally {
    $db->delete('radacct', ['username'=>$username]);
    if ($entity && $entity->id) $table->delete($entity);
    unlink($secretFile);
}
