<?php
// Run only in disposable local Docker DB. All fixture changes are rolled back.
require '/var/www/html/cake4/rd_cake/vendor/autoload.php';
require '/var/www/html/cake4/rd_cake/config/bootstrap.php';
require '/tmp/PermanentUsersBulkController.php';
use Cake\ORM\TableRegistry;
use Cake\Http\ServerRequest;
$t = TableRegistry::getTableLocator()->get('PermanentUsers');
$db = $t->getConnection();
$source = $t->find()->first();
$admin = TableRegistry::getTableLocator()->get('Users')->find()->where(['username'=>'root'])->firstOrFail();
$assert = function ($ok, $label) { if (!$ok) { throw new RuntimeException($label); } echo "$label: PASS\n"; };
$db->begin();
try {
    $ids=[];
    for ($i=0;$i<2;$i++) {
        $data=$source->toArray();
        unset($data['id'],$data['created'],$data['modified']);
        $data=array_replace($data,['username'=>'__bulk_'.bin2hex(random_bytes(6)),'password'=>'bulk-fixture','token'=>'','mac_address'=>'','static_ip'=>'','session_limit'=>1]);
        $e=$t->newEntity($data); $t->saveOrFail($e); $ids[]=$e->id;
    }
    $run=function ($patch) use ($ids,$source,$admin) {
        $data=array_replace(['cloud_id'=>$source->cloud_id,'token'=>$admin->token,'ids'=>json_encode($ids)],$patch);
        $request=(new ServerRequest(['environment'=>['REQUEST_METHOD'=>'POST']]))->withParsedBody($data)->withParam('controller','PermanentUsers')->withParam('action','editBasicInfo');
        $controller=new App\Controller\PermanentUsersController($request);
        $method=new ReflectionMethod($controller,'schoolBulkEdit');$method->setAccessible(true);$method->invoke($controller);
        return $controller->viewBuilder()->getVars();
    };
    $before=$t->get($ids[0])->toArray();
    $assert($run(['session_limit'=>'3'])['success'],'Update two users');
    foreach ($ids as $id) {$assert($t->get($id)->session_limit===3,'Limit saved '.$id);}
    $after=$t->get($ids[0])->toArray();
    foreach (['realm_id','profile_id','username','from_date','to_date','cap_type'] as $key) {
        if (array_key_exists($key,$before)) {$assert($before[$key]==$after[$key],'Preserve '.$key);}
    }
    $assert($run(['session_limit'=>'0'])['success'],'Zero means unlimited');
    foreach (['-1','21','1.5','abc'] as $v) {$assert(!$run(['session_limit'=>$v])['success'],'Reject invalid limit '.$v);}
    $assert(!$run([])['success'],'Reject empty patch');
    $assert(!$run(['realm_id'=>'999999999'])['success'],'Reject unknown realm');
    $assert(!$run(['profile_id'=>'999999999'])['success'],'Reject unknown profile');
    $assert(!$run(['cloud_id'=>999999999,'session_limit'=>'2'])['success'],'Reject wrong cloud');
    $assert(!$run(['ids'=>json_encode([$ids[0],999999999]),'session_limit'=>'2'])['success'],'Reject missing user atomically');
    $assert($t->get($ids[0])->session_limit===0,'Failed batch leaves first user unchanged');
    $assert($run(['realm_id'=>(string)$source->realm_id,'profile_id'=>(string)$source->profile_id])['success'],'Update realm and profile together');
    $targets=[];
    foreach (['Realms'=>'realm_id','Profiles'=>'profile_id'] as $model=>$field) {
        $table=TableRegistry::getTableLocator()->get($model);
        $original=$table->get($source->{$field})->toArray();
        unset($original['id'],$original['created'],$original['modified']);
        $original['name']='__bulk_target_'.bin2hex(random_bytes(5));
        $target=$table->newEntity($original);$table->saveOrFail($target);$targets[$field]=$target;
    }
    $assert($run(['realm_id'=>(string)$targets['realm_id']->id,'profile_id'=>(string)$targets['profile_id']->id,'session_limit'=>'2'])['success'],'Change all three fields together');
    foreach ($ids as $id) {
        $user=$t->get($id);
        $assert($user->realm_id==$targets['realm_id']->id && $user->profile_id==$targets['profile_id']->id && $user->session_limit===2,'All selected users updated');
        foreach (['Rd-Realm'=>'realm_id','User-Profile'=>'profile_id'] as $attribute=>$field) {
            $row=TableRegistry::getTableLocator()->get('Radchecks')->find()->where(['username'=>$user->username,'attribute'=>$attribute])->first();
            $assert($row && $row->value===$targets[$field]->name,'RADIUS attribute synchronized '.$attribute);
        }
    }
    echo "ALL BULK TESTS PASSED\n";
} finally {$db->rollback();}
