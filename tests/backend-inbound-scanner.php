<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use O8\Core\Runtime;
use O8\Auth\{AuthService,BootstrapService};
use O8\Install\Migrator;
use O8\Documents\Documents;
use O8\Inbound\InboundScanner;
use O8\Inbound\SourceManager;
use O8\Inbound\RemoteFetchJobs;
use O8\Inbound\{AiConfiguration,AiJobs};
use O8\Storage\Storage;

if (PHP_SAPI!=='cli' || !preg_match('#^/tmp/o8-m2-test\.[A-Za-z0-9]+/db\.sock$#D',$argv[1]??'')) exit("Isolated test socket required.\n");
$db=new PDO('mysql:unix_socket='.$argv[1].';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$name='inbound_test_'.bin2hex(random_bytes(4)); $db->exec('CREATE DATABASE '.$name); $db->exec('USE '.$name);
$runtime=new Runtime(dirname($argv[1]).'/'.$name); $identity=$runtime->identity();
$migrator=new Migrator($db,dirname(__DIR__).'/database/migrations'); $migrator->install($identity); $migrator->upgrade($identity);
$auth=new AuthService($db,$runtime,$identity['key']);
$session=$auth->login('operator','admin','owndms8','test',$runtime->issueSetupToken());
$session=$auth->changePassword($auth->actor($session),'owndms8','operator-secret');
$operator=$auth->actor($session);
(new BootstrapService($db))->complete($operator,'Admin','admin@example.test','Inbound test');
$operator=$auth->actor($auth->login('operator','admin','operator-secret','test'));
$actor=$auth->actor($auth->login('account','admin','operator-secret','test'));
$checks=0;
function check(bool $condition,string $message): void { global $checks; if (!$condition) throw new RuntimeException('FAIL '.$message); ++$checks; echo "PASS $message\n"; }

$directory=dirname($argv[1]).'/inbound'; mkdir($directory,0700);
$storageBase=dirname($argv[1]).'/inbound-storage-'.bin2hex(random_bytes(4)); mkdir($storageBase,0700);
$storageTarget=null;
try {
    $pdf="%PDF-1.4\n% o8 inbound fixture\n";
    file_put_contents($directory.'/Invoice.pdf',$pdf);
    file_put_contents($directory.'/Invoice.txt','OCR fixture');
    file_put_contents($directory.'/Invoice.json',json_encode(['dokument'=>['titel'=>'Invoice fixture','tags'=>['Rechnung','Rechnungen']],'betraege'=>['brutto'=>'119.00']],JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/Copy.pdf',$pdf);
    file_put_contents($directory.'/Broken.pdf',$pdf.'different');
    file_put_contents($directory.'/Broken.json','{"invalid":');
    file_put_contents($directory.'/ignored.exe','not a document');
    $documents=new Documents($db,dirname(__DIR__)); $documents->createTag($actor,'Rechnung');
    $stmt=$db->prepare("INSERT INTO import_sources (tenant_id,owner_id,kind,name,enabled,config_json) VALUES (?,?, 'inbound','Local fixture',1,?)");
    $stmt->execute([$actor->tenantId(),$actor->id(),json_encode(['path'=>realpath($directory)],JSON_THROW_ON_ERROR)]); $source=(int)$db->lastInsertId();
    $scanner=new InboundScanner($db); $result=$scanner->scan($actor,$source);
    check($result['count']===3 && $result['counts']===['pending'=>1,'duplicate'=>1,'invalid'=>1],'scan inventories supported files with stable statuses');
    $invoice=array_values(array_filter($result['items'],fn($item)=>$item['name']==='Invoice.pdf'))[0];
    check($invoice['hasText'] && $invoice['hasJson'] && $invoice['jsonValid'],'same-basename OCR and JSON sidecars are paired');
    check(array_column($invoice['matchedTags'],'id')===[(int)$documents->tags($actor)[0]['id']] && $invoice['ignoredTags']===['Rechnungen'],'AI tags match only the existing exact catalogue entry');
    check((int)$db->query('SELECT COUNT(*) FROM tags')->fetchColumn()===1,'scan never creates AI-suggested tags');
    check((int)$db->query('SELECT COUNT(*) FROM documents')->fetchColumn()===0 && is_file($directory.'/Invoice.pdf'),'inventory neither creates documents nor moves source files');
    check((int)$db->query('SELECT COUNT(*) FROM source_items')->fetchColumn()===3,'source identities are recorded for duplicate protection');
    $workbench=(new O8\Inbound\InboundWorkbench($db))->items($actor);
    check(count($workbench)===3,'scan creates separate entrance work items without DMS documents');
    $states=[]; foreach ($workbench as $row) $states[$row['original_name']]=[$row['inventory_status'],$row['ai_status'],(bool)$row['has_text_sidecar']];
    $inventoryCounts=array_count_values(array_column($workbench,'inventory_status'));
    check($states['Invoice.pdf'][1]==='ready' && $states['Invoice.pdf'][2]===true && $states['Broken.pdf']===['invalid','failed',false] && $states['Copy.pdf'][1]==='not_requested' && ($inventoryCounts['duplicate']??0)===1 && ($inventoryCounts['invalid']??0)===1 && ($inventoryCounts['pending']??0)===1,'entrance records inventory, sidecar and AI states independent of directory order');
    $aiConfiguration=new AiConfiguration($db,$identity); $aiConfiguration->save($actor,['revision'=>0,'enabled'=>true,'external_processing_confirmed'=>true,'endpoint'=>'https://ai.example.test/v1/chat/completions','primary_model'=>'fixture-model','fallback_model'=>'','instruction_text'=>AiConfiguration::defaultInstruction(),'timeout_seconds'=>30,'secret'=>'fixture-ai-token']);
    $publicAi=$aiConfiguration->get($actor); check($publicAi['enabled'] && $publicAi['secret_set'] && !array_key_exists('secret',$publicAi) && !str_contains((string)$db->query('SELECT ciphertext FROM ai_configurations')->fetchColumn(),'fixture-ai-token'),'tenant AI token is encrypted and write-only');
    $copy=array_values(array_filter($workbench,fn($item)=>$item['original_name']==='Copy.pdf'))[0]; $aiJobs=new AiJobs($db,dirname(__DIR__),$identity); $aiJob=$aiJobs->enqueue($actor,[['id'=>(int)$copy['id'],'revision'=>(int)$copy['revision']]]);
    check($aiJob['status']==='queued' && (int)$aiJob['total_count']===1 && $aiJob['items'][0]['status']==='queued','manual AI start creates an auditable bounded job without calling the external service in HTTP');
    check((string)$db->query('SELECT ai_status FROM inbound_items WHERE id='.(int)$copy['id'])->fetchColumn()==='queued' && count($aiJobs->history($actor,(int)$copy['id']))===1,'AI queue state and per-item history are visible immediately');
    $db->prepare('DELETE FROM inbound_ai_job_items WHERE tenant_id=? AND job_id=?')->execute([$actor->tenantId(),$aiJob['id']]); $db->prepare('DELETE FROM background_jobs WHERE tenant_id=? AND id=?')->execute([$actor->tenantId(),$aiJob['id']]); $db->prepare('DELETE FROM inbound_ai_runs WHERE tenant_id=? AND inbound_item_id=?')->execute([$actor->tenantId(),$copy['id']]); $db->prepare("UPDATE inbound_items SET ai_status='not_requested',revision=revision+1 WHERE tenant_id=? AND id=?")->execute([$actor->tenantId(),$copy['id']]);
    try { (new O8\Inbound\InboundWorkbench($db))->recordInventory($actor,(int)$workbench[0]['source_item_id'],$actor->id()+999,['name'=>'tampered.pdf','mime'=>'application/pdf','size'=>1,'inventoryStatus'=>'pending','aiStatus'=>'not_requested']); check(false,'source owner cannot be replaced'); }
    catch (RuntimeException) { check(true,'source owner cannot be replaced'); }
    $again=$scanner->scan($actor,$source);
    check($again['counts']===['pending'=>1,'duplicate'=>1,'invalid'=>1] && (int)$db->query('SELECT COUNT(*) FROM source_items')->fetchColumn()===3,'repeat scan is idempotent');
    check(count($scanner->sources($actor))===1 && $scanner->sources($actor)[0]['last_error_code']===null,'source status records successful scan');
    $workbenchService=new O8\Inbound\InboundWorkbench($db,dirname(__DIR__)); $fresh=$workbenchService->items($actor); $byName=[]; foreach ($fresh as $row) $byName[$row['original_name']]=$row;
    $invoiceDetail=$workbenchService->get($actor,(int)$byName['Invoice.pdf']['id']); $opened=$workbenchService->open($actor,(int)$byName['Invoice.pdf']['id']); try { $openedBytes=stream_get_contents($opened['handle']); } finally { fclose($opened['handle']); }
    check(str_contains((string)$invoiceDetail['json_text'],'Rechnungen') && $invoiceDetail['text_content']==='OCR fixture' && $openedBytes===$pdf,'entrance detail safely exposes original, JSON and OCR preview data');
    $deleted=$workbenchService->delete($actor,[['id'=>(int)$byName['Broken.pdf']['id'],'revision'=>(int)$byName['Broken.pdf']['revision']]]);
    check($deleted===1 && count($workbenchService->items($actor))===2 && is_file($directory.'/Broken.pdf'),'deleting unmanaged local entrance record is revision-safe and never deletes its external source file');
    $manager=new SourceManager($db,$identity);
    $mailId=$manager->save($actor,['id'=>0,'revision'=>0,'kind'=>'imap','name'=>'Test mailbox','interval'=>0,'host'=>'imap.example.test','port'=>993,'security'=>'tls','username'=>'jan@example.test','folder'=>'INBOX','deleteAfter'=>false,'aiMode'=>'manual','secret'=>'mail-secret','enabled'=>true]);
    $mail=$manager->sources($actor)[0];
    check((int)$mail['id']===$mailId && $mail['secret_set'] && !array_key_exists('secret',$mail),'source list exposes status but never credentials');
    check((int)$mail['interval_minutes']===0 && $mail['next_run_at']===null,'interval zero keeps an active source available only for manual polling');
    $stored=(string)$db->query('SELECT ciphertext FROM source_credentials LIMIT 1')->fetchColumn();
    check(!str_contains($stored,'mail-secret') && $manager->connection($actor,$mailId)['secret']==='mail-secret','source credential is authenticated-encrypted and decryptable only server-side');
    $manager->save($actor,['id'=>$mailId,'revision'=>$mail['revision'],'kind'=>'imap','name'=>'Test mailbox','interval'=>30,'host'=>'imap.example.test','port'=>993,'security'=>'tls','username'=>'jan@example.test','folder'=>'Archive','deleteAfter'=>true,'aiMode'=>'automatic','secret'=>'','enabled'=>true]);
    $changed=$manager->sources($actor)[0];
    check($changed['config']['folder']==='Archive' && $changed['config']['delete_after_fetch'] && $changed['config']['ai_mode']==='automatic' && $manager->connection($actor,$mailId)['secret']==='mail-secret','empty secret preserves credential while versioned configuration changes');
    $user=posix_getpwuid(posix_geteuid()); $group=posix_getgrgid(posix_getegid());
    (new Storage($db,dirname(__DIR__)))->configure($operator,$actor->tenantId(),realpath($storageBase),(string)$user['name'],(string)$group['name']);
    $location=(new Storage($db,dirname(__DIR__)))->location($actor); [, $storageTarget]=(new Storage($db,dirname(__DIR__)))->paths($location); mkdir($storageTarget.'/inbound',0750);
    $managedBytes="%PDF-1.4\n% managed inbound fixture\n"; $managedRelative='inbound/'.str_repeat('b',48).'.pdf'; file_put_contents($storageTarget.'/'.$managedRelative,$managedBytes);
    $managedHash=hash('sha256',$managedBytes); $remoteHash=hash('sha256','managed-remote-fixture');
    $stmt=$db->prepare("INSERT INTO source_items (tenant_id,source_id,remote_key_hash,status,sha256) VALUES (?,?,?,'downloaded',?)"); $stmt->execute([$actor->tenantId(),$mailId,$remoteHash,$managedHash]); $managedSource=(int)$db->lastInsertId();
    $stmt=$db->prepare("INSERT INTO inbound_items (tenant_id,source_item_id,owner_id,original_name,mime_type,size_bytes,inventory_status,ai_status) VALUES (?,?,?,'Managed.pdf','application/pdf',?,'pending','not_requested')"); $stmt->execute([$actor->tenantId(),$managedSource,$actor->id(),strlen($managedBytes)]); $managedInbound=(int)$db->lastInsertId();
    $stmt=$db->prepare("INSERT INTO inbound_files (tenant_id,inbound_item_id,role,relative_path,original_name,mime_type,sha256,size_bytes) VALUES (?,?,'original',?,'Managed.pdf','application/pdf',?,?)"); $stmt->execute([$actor->tenantId(),$managedInbound,$managedRelative,$managedHash,strlen($managedBytes)]);
    $managedWorkbench=new O8\Inbound\InboundWorkbench($db,dirname(__DIR__)); $managed=$managedWorkbench->get($actor,$managedInbound); $managedFile=$managedWorkbench->open($actor,$managedInbound); try { $managedOpened=stream_get_contents($managedFile['handle']); } finally { fclose($managedFile['handle']); }
    check($managedOpened===$managedBytes,'managed entrance file is read only through verified tenant storage');
    $managedWorkbench->delete($actor,[['id'=>$managedInbound,'revision'=>(int)$managed['revision']]]);
    check(!file_exists($storageTarget.'/'.$managedRelative) && (int)$db->query("SELECT COUNT(*) FROM inbound_files WHERE inbound_item_id=$managedInbound")->fetchColumn()===0,'managed entrance deletion removes its file only after the database transition');
    $stmt=$db->prepare('DELETE FROM inbound_items WHERE tenant_id=? AND id=?'); $stmt->execute([$actor->tenantId(),$managedInbound]);
    $stmt=$db->prepare('DELETE FROM source_items WHERE tenant_id=? AND id=?'); $stmt->execute([$actor->tenantId(),$managedSource]);
    $stmt=$db->prepare("INSERT INTO background_jobs (tenant_id,owner_id,source_id,kind,status,payload_json,total_count,completed_count,finished_at) VALUES (?,?,?,'source_fetch','completed','{}',1,1,UTC_TIMESTAMP())"); $stmt->execute([$actor->tenantId(),$actor->id(),$mailId]); $fetchJob=(int)$db->lastInsertId();
    $stmt=$db->prepare("INSERT INTO source_fetch_items (tenant_id,job_id,remote_key_hash,locator_json,original_name,expected_size,expected_mime,status,finished_at) VALUES (?,?,?,'{}','fixture.pdf',8,'application/pdf','completed',UTC_TIMESTAMP())"); $stmt->execute([$actor->tenantId(),$fetchJob,str_repeat('a',64)]);
    $visible=(new RemoteFetchJobs($db,dirname(__DIR__),$identity))->job($actor,$fetchJob);
    check((int)$visible['total_count']===1 && $visible['items'][0]['original_name']==='fixture.pdf' && !str_contains(json_encode($visible,JSON_THROW_ON_ERROR),'mail-secret'),'fetch progress is owner-scoped and never exposes credentials or worker locators');
    $stmt=$db->prepare('DELETE FROM source_fetch_items WHERE tenant_id=? AND job_id=?'); $stmt->execute([$actor->tenantId(),$fetchJob]); $stmt=$db->prepare('DELETE FROM background_jobs WHERE tenant_id=? AND id=?'); $stmt->execute([$actor->tenantId(),$fetchJob]);
    $db->prepare('UPDATE import_sources SET interval_minutes=0,next_run_at=NULL WHERE tenant_id=? AND id=?')->execute([$actor->tenantId(),$mailId]);
    $stmt=$db->prepare("INSERT INTO background_jobs (tenant_id,owner_id,source_id,kind,status,payload_json,total_count) VALUES (?,?,?,'source_fetch','queued','{}',0)"); $stmt->execute([$actor->tenantId(),$actor->id(),$mailId]); $manualJob=(int)$db->lastInsertId();
    $runner=new RemoteFetchJobs($db,dirname(__DIR__),$identity);
    check($runner->runNext()===null && (string)$db->query("SELECT status FROM background_jobs WHERE id=$manualJob")->fetchColumn()==='queued','periodic worker ignores interval-zero manual jobs');
    $leaseToken=str_repeat('c',48); $db->prepare("UPDATE background_jobs SET status='running',worker_token=?,lease_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE) WHERE id=?")->execute([$leaseToken,$manualJob]);
    $lease=(new ReflectionClass($runner))->getMethod('lease'); $lease->invoke($runner,['tenant_id'=>$actor->tenantId(),'id'=>$manualJob],$leaseToken); $lease->invoke($runner,['tenant_id'=>$actor->tenantId(),'id'=>$manualJob],$leaseToken);
    check((string)$db->query("SELECT status FROM background_jobs WHERE id=$manualJob")->fetchColumn()==='running','same-second lease renewal keeps ownership instead of reporting job_lease_lost');
    $db->prepare("UPDATE background_jobs SET status='queued',worker_token=NULL,lease_until=NULL WHERE id=?")->execute([$manualJob]);
    $manualResult=$runner->runManualStep($actor,$manualJob);
    check($manualResult['status']==='completed' && $runner->workerActive(),'manual job is executed directly while periodic worker heartbeat is tracked separately');
    $db->prepare('DELETE FROM background_jobs WHERE tenant_id=? AND id=?')->execute([$actor->tenantId(),$manualJob]);
    try { $manager->save($actor,['id'=>0,'revision'=>0,'kind'=>'imap','name'=>'Test mailbox','interval'=>15,'host'=>'other.example.test','port'=>993,'security'=>'tls','username'=>'other','folder'=>'INBOX','aiMode'=>'off','secret'=>'duplicate-secret']); check(false,'duplicate personal source name rejected'); } catch (RuntimeException) { check(true,'duplicate personal source name rejected'); }
    check((int)$db->query('SELECT COUNT(*) FROM source_credentials')->fetchColumn()===1,'failed duplicate source does not leak a credential row');
    try { $manager->save($actor,['id'=>0,'revision'=>0,'kind'=>'webdav','name'=>'Unsafe DAV','interval'=>15,'url'=>'http://dav.example.test/files','username'=>'jan','aiMode'=>'manual','secret'=>'dav-secret']); check(false,'WebDAV without HTTPS rejected'); } catch (InvalidArgumentException) { check(true,'WebDAV without HTTPS rejected'); }
    $manager->delete($actor,$mailId,(int)$changed['revision']);
    check($manager->sources($actor)===[] && (int)$db->query('SELECT COUNT(*) FROM source_credentials')->fetchColumn()===0,'unused source and credential can be deleted together');
} finally {
    foreach (glob($directory.'/*')?:[] as $file) unlink($file);
    rmdir($directory);
    if ($storageTarget) {
        foreach (glob($storageTarget.'/inbound/*')?:[] as $file) unlink($file);
        @rmdir($storageTarget.'/inbound');
        @unlink($storageTarget.'/.o8-storage');
        @rmdir($storageTarget);
    }
    @rmdir($storageBase);
}
echo "$checks inbound scanner checks passed.\n";
