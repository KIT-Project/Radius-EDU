<?php
// Local disposable database only. Entire fixture is rolled back.
require '/var/www/html/cake4/rd_cake/vendor/autoload.php';
require '/var/www/html/cake4/rd_cake/config/bootstrap.php';
require '/tmp/PermanentUserRename.php';
use Cake\ORM\TableRegistry;
use App\Service\PermanentUserRename;
$t=TableRegistry::getTableLocator()->get('PermanentUsers');$db=$t->getConnection();
$assert=function($ok,$label){if(!$ok)throw new RuntimeException($label);echo "$label: PASS\n";};
$db->begin();
try{
    $source=$t->find()->firstOrFail();$data=$source->toArray();unset($data['id'],$data['created'],$data['modified']);
    $old='rename_'.bin2hex(random_bytes(6));$new=$old.'_new';
    $data=array_replace($data,['username'=>$old,'password'=>'rename-fixture','token'=>'','static_ip'=>'','mac_address'=>'']);
    $u=$t->newEntity($data);$t->saveOrFail($u);$before=$t->get($u->id)->toArray();
    $rename=new PermanentUserRename();
    $reject=function($id,$cloud,$expected,$name,$realms=false)use($rename,$t,$assert){
        try{$rename->rename($t,$id,$cloud,$expected,$name,$realms);throw new LogicException('Unexpected success');}
        catch(InvalidArgumentException $e){$assert(true,'Reject '.$e->getMessage());}
    };
    $reject($u->id,$u->cloud_id,$old,$source->username);
    $reject($u->id,$u->cloud_id,$old,'bad name');
    $reject($u->id,999999999,$old,$new);
    $reject($u->id,$u->cloud_id,$old,$new,[999999999]);
    $reject($u->id,$u->cloud_id,'stale',$new);
    $db->insert('radacct',['username'=>$old,'acctsessionid'=>'rename-test','acctuniqueid'=>bin2hex(random_bytes(16)),'nasipaddress'=>'127.0.0.1','acctstarttime'=>date('Y-m-d H:i:s'),'acctstoptime'=>null,'acctinputoctets'=>0,'acctoutputoctets'=>0,'acctsessiontime'=>0]);
    $reject($u->id,$u->cloud_id,$old,$new);
    $db->execute('UPDATE radacct SET acctstoptime=NOW() WHERE username=?',[$old]);
    $result=$rename->rename($t,$u->id,$u->cloud_id,$old,$new);
    $assert($result['old']===$old && $result['new']===$new,'Rename result');
    $after=$t->get($u->id)->toArray();
    foreach(['id','password','token','realm_id','profile_id','session_limit','from_date','to_date']as$key){$assert($before[$key]==$after[$key],'Preserve '.$key);}
    $assert($after['username']===$new,'Username changed');
    $assert(!$db->execute('SELECT 1 FROM radcheck WHERE username=?',[$old])->fetch('assoc'),'Old authentication removed');
    $row=$db->execute("SELECT value FROM radcheck WHERE username=? AND attribute='Cleartext-Password'",[$new])->fetch('assoc');
    $assert($row && $row['value']==='rename-fixture','Password remains usable under new name');
    $assert((bool)$db->execute('SELECT 1 FROM radacct WHERE username=?',[$new])->fetch('assoc'),'Accounting history retained');
    $db->insert('radreply',['username'=>$old.'_reserved','attribute'=>'Reply-Message','op'=>':=','value'=>'fixture']);
    $reject($u->id,$u->cloud_id,$new,$old.'_reserved');
    $assert($t->get($u->id)->username===$new,'Failed rename leaves account unchanged');
    echo "ALL RENAME TESTS PASSED\n";
}finally{$db->rollback();}
