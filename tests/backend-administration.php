<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use O8\Core\Runtime;
use O8\Install\Migrator;
use O8\Auth\{AuthService,BootstrapService,Actor};
use O8\Admin\Administration;
if (PHP_SAPI!=='cli' || !preg_match('#^/tmp/o8-m2-test\.[A-Za-z0-9]+/db\.sock$#D',$argv[1]??'')) exit("Isolated temporary test socket required.\n");
$db=new PDO('mysql:unix_socket='.$argv[1].';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$testDatabase='administration_test_'.bin2hex(random_bytes(4)); $db->exec('CREATE DATABASE '.$testDatabase); $db->exec('USE '.$testDatabase);
$runtime=new Runtime(dirname($argv[1]).'/admin-runtime'); $identity=$runtime->identity();
(new Migrator($db,dirname(__DIR__).'/database/migrations'))->install($identity);
$auth=new AuthService($db,$runtime,$identity['key']); $service=new Administration($db); $checks=0;
function check(bool $ok,string $label): void { global $checks; if (!$ok) throw new RuntimeException('FAIL '.$label); ++$checks; echo "PASS $label\n"; }
function rejects(callable $call,string $label): void { try { $call(); } catch (RuntimeException|InvalidArgumentException $e) { check(true,$label); return; } check(false,$label); }
function account(PDO $db,int $id): Actor { $s=$db->prepare('SELECT a.*,u.*,a.auth_version AS account_auth_version FROM users u JOIN accounts a ON a.id=u.account_id WHERE u.id=?'); $s->execute([$id]); return new Actor('tenant',$s->fetch()); }
function payload(string $login): array { return ['login'=>$login,'name'=>'Test '.$login,'email'=>$login.'@example.test','password'=>'abc123']; }
$operatorSession=$auth->login('operator','admin','owndms8','test',$runtime->issueSetupToken());
$operatorSession=$auth->changePassword($auth->actor($operatorSession),'owndms8','first-operator');
$first=(new BootstrapService($db))->complete($auth->actor($operatorSession),'Operator','op@example.test','First');
$operator=$auth->actor($operatorSession);
$adminSession=$auth->login('account','admin','first-operator','test'); $admin=$auth->actor($adminSession);
rejects(fn()=>$service->updateUser($admin,$admin->id(),1,'Admin','user',true),'last admin cannot be demoted');
rejects(fn()=>$service->updateUser($admin,$admin->id(),1,'Admin','admin',false),'last admin cannot be disabled');
check($auth->actor($adminSession)!==null,'rejected last-admin change leaves session intact');
$second=$service->createTenant($operator,'Second','',payload('secondadmin'));
$secondSession=$auth->login('account','secondadmin','abc123','test'); $pending=$auth->actor($secondSession);
rejects(fn()=>$service->users($pending),'new tenant admin must change initial password');
$secondSession=$auth->changePassword($pending,'abc123','second-admin'); $secondAdmin=$auth->actor($secondSession);
check($secondAdmin->row['email_verified_at']===null,'no fabricated email verification');
$uid=$service->createUser($admin,payload('member'),'user');
$userSession=$auth->login('account','member','abc123','test'); $pending=$auth->actor($userSession);
rejects(fn()=>$pending->documentScope(),'new user forced to change password');
$userSession=$auth->changePassword($pending,'abc123','member-password'); $user=$auth->actor($userSession);
check(count($service->users($admin))===2,'user list restricted to own tenant');
check(count($service->users($admin,'member@example'))===1,'user search finds email');
check(count($service->users($admin,'%'))===0,'search treats wildcard characters literally');
rejects(fn()=>$service->users($user),'regular user cannot list users');
rejects(fn()=>$service->users($operator),'operator cannot list tenant users');
rejects(fn()=>$service->tenants($admin),'tenant admin cannot list tenants');
rejects(fn()=>$service->createTenant($admin,'Forbidden','',payload('nope')),'tenant admin cannot create tenants');
rejects(fn()=>$service->createUser($user,payload('nope'),'admin'),'regular user cannot create users');
rejects(fn()=>$service->createUser($admin,payload('member'),'user'),'duplicate login or email rejected');
rejects(fn()=>$service->createUser($admin,payload('invalid'),'operator'),'unknown role rejected');
rejects(fn()=>$service->updateUser($admin,$secondAdmin->id(),2,'Attack','user',false),'cross-tenant user update rejected');
rejects(fn()=>$service->resetPassword($admin,$secondAdmin->id(),2,'attack-password'),'cross-tenant password reset rejected');
$service->updateUser($admin,$uid,(int)$user->row['auth_version'],'Member','user',false);
check($auth->actor($userSession)->kind==='account','user disabling revokes existing session');
check($auth->memberships($auth->actor($auth->login('account','member','member-password','test')))===[],'disabled membership is not offered');
rejects(fn()=>$service->updateUser($admin,$uid,(int)$user->row['auth_version'],'Stale','admin',true),'stale edit version rejected');
$user=account($db,$uid); $service->updateUser($admin,$uid,(int)$user->row['auth_version'],'Member','user',true);
check($auth->actor($userSession)->kind==='account','reenabling user does not revive old session');
$userSession=$auth->login('account','member','member-password','test'); $user=account($db,$uid);
rejects(fn()=>$service->resetPassword($admin,$uid,(int)$user->row['auth_version'],'reset-pass'),'tenant admin cannot reset shared password');
check($auth->actor($userSession)->kind==='tenant','rejected reset preserves shared login');
rejects(fn()=>$service->resetPassword($admin,$admin->id(),1,'reset-own'),'admin cannot use reset flow on self');
$service->updateTenant($operator,$secondAdmin->tenantId(),'Second renamed','contact@example.test',false);
check($auth->actor($secondSession)->kind==='account','tenant suspension revokes sessions');
check($auth->memberships($auth->actor($auth->login('account','secondadmin','second-admin','test')))===[],'suspended tenant is not offered');
$service->updateTenant($operator,$secondAdmin->tenantId(),'Second renamed','',true);
check($auth->actor($secondSession)->kind==='account','reactivated tenant does not revive previous sessions');
rejects(fn()=>$service->createUser($secondAdmin,payload('stale'),'user'),'stale actor rejected inside write transaction');
$other=$service->createUser($admin,payload('otheradmin'),'admin');
$otherSession=$auth->login('account','otheradmin','abc123','test');
$otherSession=$auth->changePassword($auth->actor($otherSession),'abc123','other-admin'); $otherAdmin=$auth->actor($otherSession);
$service->updateUser($admin,$other,(int)$otherAdmin->row['auth_version'],'Other','user',true);
check($auth->actor($otherSession)->kind==='account','role change revokes sessions');
rejects(fn()=>$service->createUser($otherAdmin,payload('staleadmin'),'admin'),'revoked admin cannot perform delayed writes');
$unknown=new Actor('tenant',array_replace($admin->row,['role'=>'unknown']));
rejects(fn()=>$unknown->documentScope(),'unknown roles fail closed');
$audit=(string)$db->query('SELECT GROUP_CONCAT(COALESCE(details_json,\'\')) FROM audit_events')->fetchColumn();
check(!str_contains($audit,'reset-pass') && !str_contains($audit,'abc123'),'audit does not contain credentials');
check((int)$db->query('SELECT COUNT(*) FROM audit_events')->fetchColumn()===5,'all five successful user writes audited; rejected resets add no event');
check(count($service->tenants($operator))===2,'operator sees tenant metadata');
// Two independent requests race to demote themselves. A tenant lock makes one fail.
$other=account($db,$other); $service->updateUser($admin,$other->id(),(int)$other->row['auth_version'],'Other','admin',true);
$db->beginTransaction(); $db->query('SELECT id FROM tenants WHERE id='.(int)$admin->tenantId().' FOR UPDATE');
$jobs=[];
foreach ([$admin->id(),$other->id()] as $id) {
    $pipes=[]; $process=proc_open([PHP_BINARY,__DIR__.'/admin-concurrent-worker.php',$argv[1],(string)$id,$testDatabase],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $jobs[]=[$process,$pipes];
}
$db->commit(); $outcomes=[];
foreach ($jobs as [$process,$pipes]) { $outcomes[]=trim(stream_get_contents($pipes[1])); $stderr=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); check(proc_close($process)===0 && $stderr==='','parallel worker completed cleanly'); }
sort($outcomes); check($outcomes===['blocked','changed'],'concurrent requests preserve exactly one active admin');
echo "$checks administration checks passed.\n";
