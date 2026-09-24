<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use O8\Core\Runtime;
use O8\Install\Migrator;
use O8\Auth\{AuthService,BootstrapService};
use O8\Admin\{Administration,TenantName};
if (PHP_SAPI!=='cli' || !preg_match('#^/tmp/o8-m2-test\.[A-Za-z0-9]+/db\.sock$#D',$argv[1]??'')) exit("Isolated test socket required.\n");
$db=new PDO('mysql:unix_socket='.$argv[1].';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
if (($argv[2]??'')==='worker') {
    if (!preg_match('/^names_test_[a-f0-9]{8}$/D',$argv[3]??'')) exit(2);
    $db->exec('USE '.$argv[3]);
    try { $s=$db->prepare('INSERT INTO tenants (public_id,name) VALUES (?,?)'); $s->execute([Runtime::uuid(),$argv[4]]); echo 'created'; }
    catch (PDOException $e) { if (!TenantName::isConflict($e)) throw $e; echo 'duplicate'; }
    exit;
}
$test='names_test_'.bin2hex(random_bytes(4)); $db->exec('CREATE DATABASE '.$test); $db->exec('USE '.$test);
$root=dirname(__DIR__); $tmp=dirname($argv[1]).'/'.$test; mkdir($tmp); mkdir($tmp.'/baseline');
copy($root.'/database/migrations/001_core.sql',$tmp.'/baseline/001_core.sql');
$runtime=new Runtime($tmp.'/runtime'); $identity=$runtime->identity();
(new Migrator($db,$tmp.'/baseline'))->install($identity);
$auth=new AuthService($db,$runtime,$identity['key']);
$session=$auth->login('operator','admin','owndms8','test',$runtime->issueSetupToken());
$session=$auth->changePassword($auth->actor($session),'owndms8','secret6');
$db->exec("UPDATE platform_operators SET bootstrap_pending=0 WHERE id=1");
$s=$db->prepare('INSERT INTO tenants (public_id,name) VALUES (?,?)'); $s->execute([Runtime::uuid(),'Alpha']);
$operator=$auth->actor($session); $service=new Administration($db); $migrator=new Migrator($db,$root.'/database/migrations'); $checks=0;
function check(bool $value,string $label): void { global $checks; if (!$value) throw new RuntimeException('FAIL '.$label); ++$checks; echo "PASS $label\n"; }
function rejects(callable $call,string $label): void { try { $call(); } catch (RuntimeException|InvalidArgumentException $e) { check(true,$label); return; } check(false,$label); }
$s=$db->prepare('INSERT INTO tenants (public_id,name) VALUES (?,?)'); $s->execute([Runtime::uuid(),' ALPHA ']); $duplicate=(int)$db->lastInsertId();
$before=$db->query('SELECT id,name FROM tenants ORDER BY id')->fetchAll();
rejects(fn()=>$migrator->upgrade($identity),'upgrade refuses existing duplicate names');
check($db->query('SELECT id,name FROM tenants ORDER BY id')->fetchAll()===$before,'refused upgrade preserves all names and tenants');
$s=$db->prepare('UPDATE tenants SET name=? WHERE id=?'); $s->execute(['Beta',$duplicate]);
$hash=$db->query('SELECT password_hash FROM platform_operators WHERE id=1')->fetchColumn();
$migrator->upgrade($identity); $migrator->upgrade($identity);
check($hash===$db->query('SELECT password_hash FROM platform_operators WHERE id=1')->fetchColumn(),'upgrade is repeatable and does not reset accounts');
check((int)$db->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='tenants' AND index_name='tenants_name_unique' AND non_unique=0")->fetchColumn()===1,'database enforces unique normalized names');
$input=['login'=>'admin','name'=>'Admin','email'=>'admin@example.test','password'=>'secret6'];
foreach (['Alpha','alpha',' ALPHA '] as $name) rejects(fn()=>$service->createTenant($operator,$name,'',$input),'create rejects '.$name);
rejects(fn()=>$service->updateTenant($operator,$duplicate,' alpha ','',true),'rename cannot take another tenant name');
$service->updateTenant($operator,$duplicate,'Beta','',false);
rejects(fn()=>$service->createTenant($operator,'BETA','',$input),'inactive tenant still reserves its name');
$service->updateTenant($operator,$duplicate,' beta ','',true);
check($db->query('SELECT name FROM tenants WHERE id='.$duplicate)->fetchColumn()==='beta','own name may retain normalized spelling');
rejects(function() use($db) { $s=$db->prepare('INSERT INTO tenants (public_id,name) VALUES (?,?)'); $s->execute([Runtime::uuid(),' alpha ']); },'direct SQL duplicate blocked');
$jobs=[];
foreach (['Concurrent Name',' concurrent NAME '] as $name) {
    $pipes=[]; $process=proc_open([PHP_BINARY,__FILE__,$argv[1],'worker',$test,$name],[1=>['pipe','w'],2=>['pipe','w']],$pipes); $jobs[]=[$process,$pipes];
}
$outcomes=[];
foreach ($jobs as [$process,$pipes]) { $outcomes[]=trim(stream_get_contents($pipes[1])); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); check(proc_close($process)===0 && $error==='','parallel insert completed cleanly'); }
sort($outcomes); check($outcomes===['created','duplicate'],'concurrent duplicate inserts produce exactly one tenant');
echo "$checks tenant-name checks passed.\n";
