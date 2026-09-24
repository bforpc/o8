<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use O8\Core\Runtime;
use O8\Auth\{AuthService,BootstrapService};
use O8\Install\Migrator;
use O8\Documents\Documents;

if (PHP_SAPI!=='cli' || !preg_match('#^/tmp/o8-m2-test\.[A-Za-z0-9]+/db\.sock$#D',$argv[1]??'')) exit("Isolated test socket required.\n");
$db=new PDO('mysql:unix_socket='.$argv[1].';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$name='unlink_test_'.bin2hex(random_bytes(4)); $db->exec('CREATE DATABASE '.$name); $db->exec('USE '.$name);
$runtime=new Runtime(dirname($argv[1]).'/'.$name); $identity=$runtime->identity();
$m=new Migrator($db,dirname(__DIR__).'/database/migrations'); $m->install($identity); $m->upgrade($identity);
$auth=new AuthService($db,$runtime,$identity['key']);
$session=$auth->login('operator','admin','owndms8','test',$runtime->issueSetupToken());
$session=$auth->changePassword($auth->actor($session),'owndms8','operator-secret');
(new BootstrapService($db))->complete($auth->actor($session),'Admin','admin@example.test','Unlink test');
$a=$auth->actor($auth->login('account','admin','operator-secret','test'));
$docs=new Documents($db,dirname(__DIR__)); $checks=0;
function check(bool $ok,string $message):void { global $checks; if (!$ok) throw new RuntimeException('FAIL '.$message); ++$checks; echo "PASS $message\n"; }
function folder(string $name,?int $parent=null):int { global $docs,$a,$db; $docs->folderWrite($a,'create',0,$name,$parent); foreach ($docs->folders($a) as $row) if ($row['name']===$name) return (int)$row['id']; throw new RuntimeException('Missing fixture folder'); }
function document(string $title):int { global $db,$a; $s=$db->prepare("INSERT INTO documents (tenant_id,owner_id,title,in_inbox,source_type) VALUES (?,?,?,0,'test')"); $s->execute([$a->tenantId(),$a->id(),$title]); return (int)$db->lastInsertId(); }
function linkDocument(int $id,int $folder):void { global $docs,$a; $docs->link($a,$id,(int)$docs->get($a,$id)['revision'],$folder,false); }
function unlinkPlan(array $ids,int $folder):array { global $docs,$a; return ['documents'=>array_map(fn($id)=>['id'=>$id,'revision'=>(int)$docs->get($a,$id)['revision']],$ids),'folderAction'=>'remove','folderId'=>$folder,'tagAction'=>'keep','tags'=>[],'ownerId'=>'','status'=>'keep']; }

$parent=folder('Parent'); $child=folder('Child',$parent); $other=folder('Other');
$direct=document('Direct and child'); $nested=document('Only child');
check($docs->allCount($a)===2,'all documents count includes visible accepted documents');
$trash=document('Searchable trash fixture');
$docs->link($a,$trash,(int)$docs->get($a,$trash)['revision'],$parent,false);
$trashRow=$docs->get($a,$trash); $docs->status($a,$trash,(int)$trashRow['revision'],'trash');
$trashSearch=$docs->listing($a,['scope'=>'all','query'=>'Searchable trash','folderCounts'=>'1']);
check($trashSearch['total']===1 && $trashSearch['trashCount']===1 && $trashSearch['folderCounts'][$parent]===0,'search counts matching trash documents only in trash');
check($docs->listing($a,['scope'=>(string)$parent,'query'=>'Searchable trash','folderCounts'=>'1'])['total']===0,'trash links never repopulate an empty folder during search');
linkDocument($direct,$parent); linkDocument($direct,$child); linkDocument($direct,$other); linkDocument($nested,$child);
check($docs->listing($a,['scope'=>(string)$parent])['total']===1,'parent displays only its direct document');
check($docs->folderCounts($a)[$parent]===1 && $docs->folderCounts($a)[$child]===2,'counts use direct links only');
$filtered=$docs->listing($a,['scope'=>'all','query'=>'Only child','folderCounts'=>'1']);
check($filtered['folderCounts'][$parent]===0 && $filtered['folderCounts'][$child]===1,'search counts do not propagate to ancestors');
$before=$docs->get($a,$nested);
$count=$docs->bulk($a,unlinkPlan([$direct,$nested],$parent));
check($count===1,'mixed selection counts only the removed direct link');
$links=$docs->get($a,$direct)['folders']; sort($links); $expected=[$child,$other]; sort($expected);
check($links===$expected,'other and child folder links remain intact');
check($docs->get($a,$nested)===$before,'child-only document including revision is unchanged');
check($docs->listing($a,['scope'=>(string)$parent])['total']===0 && $docs->folderCounts($a)[$parent]===0,'parent becomes empty despite preserved child links');
check($docs->listing($a,['scope'=>(string)$child])['total']===2,'child still displays both documents');
$beforeDirect=$docs->get($a,$direct); $rejected=false;
try { $docs->bulk($a,unlinkPlan([$direct,$nested],$parent)); } catch (RuntimeException $e) { $rejected=str_contains($e->getMessage(),'Keine direkte Verknüpfung'); }
check($rejected,'no direct links produce an explanatory error');
check($docs->get($a,$direct)===$beforeDirect && $docs->get($a,$nested)===$before,'rejected no-op does not change revisions or documents');
$stale=unlinkPlan([$direct,$nested],$child); --$stale['documents'][1]['revision']; $rejected=false;
try { $docs->bulk($a,$stale); } catch (RuntimeException $e) { $rejected=true; }
check($rejected && $docs->get($a,$direct)===$beforeDirect,'stale selection is atomic');
check($docs->bulk($a,unlinkPlan([$direct,$nested],$child))===2,'removal in actual child folder removes both direct links');
check($docs->listing($a,['scope'=>(string)$parent])['total']===0 && $docs->get($a,$direct)['folders']===[$other],'parent results clear and unrelated link survives');
echo "$checks bulk unlink checks passed.\n";
