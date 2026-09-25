<?php
declare(strict_types=1);
// Included only after the common installation/session bootstrap.
use O8\Documents\Documents;
use O8\Documents\TrashRetention;
use O8\Storage\Storage;
use O8\Inbound\RemoteSourceBrowser;
use O8\Inbound\RemoteFetchJobs;
use O8\Inbound\InboundWorkbench;
use O8\Inbound\AiJobs;
use O8\Inbound\AiConfiguration;
use O8\Inbound\InboundAcceptance;
try {
    foreach (array_merge($_GET,$_POST) as $value) if (!is_string($value)) throw new RuntimeException('Ungültiges Eingabeformat.');
    if (!$installed || !$actor || $actor->kind!=='tenant') { http_response_code(401); throw new RuntimeException('Bitte anmelden und einen Mandanten öffnen.'); }
    $context=$_SERVER['REQUEST_METHOD']==='POST'?field('context_token'):($_GET['context']??'');
    if (!is_string($context) || $context==='' || !hash_equals($_SESSION['actor']['context_token']??'',$context)) { http_response_code(409); throw new RuntimeException('Mandantenkontext geändert. Bitte die Seite neu laden.'); }
    $actor->requireReady(); $documents=new Documents($db,$root); $api=$_GET['api'];
    if (!is_string($api)) throw new RuntimeException('Ungültiger Aufruf.');
    $write=in_array($api,['upload','save','status','folder','link','tag','bulk','trashPurge','invoice','preferences','sourceFetch','sourceRun','inboundDelete','inboundAiStart','inboundAiRun','inboundAccept','inboundBatchPreview','inboundBatchAccept'],true);
    if ($_SERVER['REQUEST_METHOD']!==($write?'POST':'GET')) { http_response_code(405); throw new RuntimeException('HTTP-Methode nicht erlaubt.'); }
    if ($write && !hash_equals($_SESSION['csrf'],field('csrf'))) { http_response_code(403); throw new RuntimeException('Sitzung abgelaufen. Bitte Seite neu laden.'); }
    if ($api==='file') {
        $file=$documents->open($actor,(int)($_GET['id']??0)); $length=$file['size']; $start=0; $end=$length-1;
        header('Content-Type: '.$file['mime']);
        // Only verified supported originals reach this endpoint. CSP sandbox blocks active content.
        header('Content-Security-Policy: default-src \'none\'; script-src \'none\'; base-uri \'none\'; form-action \'none\'; frame-ancestors \'self\'');
        header('X-Frame-Options: SAMEORIGIN');
        $disposition=($_GET['download']??'')==='1'?'attachment':'inline';
        header('Content-Disposition: '.$disposition.'; filename="Dokument-'.(int)($_GET['id']??0).'.'.(Documents::MIME_EXTENSIONS[$file['mime']]??'bin').'"; filename*=UTF-8\'\''.rawurlencode($file['name']));
        header('Accept-Ranges: bytes');
        if (isset($_SERVER['HTTP_RANGE'])) {
            if (!preg_match('/^bytes=(\d*)-(\d*)$/D',$_SERVER['HTTP_RANGE'],$m) || ($m[1]==='' && $m[2]==='')) {
                fclose($file['handle']); http_response_code(416); header("Content-Range: bytes */$length"); exit;
            }
            if ($m[1]==='') $start=max(0,$length-(int)$m[2]);
            else { $start=(int)$m[1]; if ($m[2]!=='') $end=min($end,(int)$m[2]); }
            if ($start>$end || $start>=$length) { fclose($file['handle']); http_response_code(416); header("Content-Range: bytes */$length"); exit; }
            http_response_code(206); header("Content-Range: bytes $start-$end/$length");
        }
        header('Content-Length: '.($end-$start+1)); session_write_close();
        try { fseek($file['handle'],$start); $remaining=$end-$start+1; while ($remaining>0 && !feof($file['handle'])) { $data=fread($file['handle'],min(65536,$remaining)); if ($data===false || $data==='') break; echo $data; $remaining-=strlen($data); } }
        finally { fclose($file['handle']); }
        exit;
    }
    if ($api==='inboundFile') {
        $file=(new InboundWorkbench($db,$root))->open($actor,(int)($_GET['id']??0),'original');
        header('Content-Type: '.$file['mime']); header('Content-Security-Policy: default-src \'none\'; script-src \'none\'; base-uri \'none\'; form-action \'none\'; frame-ancestors \'self\''); header('X-Frame-Options: SAMEORIGIN');
        $disposition=($_GET['download']??'')==='1'?'attachment':'inline'; header('Content-Disposition: '.$disposition.'; filename*=UTF-8\'\''.rawurlencode($file['name'])); header('Content-Length: '.$file['size']); session_write_close();
        try { fpassthru($file['handle']); } finally { fclose($file['handle']); } exit;
    }
    $result=null;
    if ($api==='meta') {
        $storage=new Storage($db,$root); $ready=false; $storageMessage='Der Betreiber muss zuerst eine geprüfte Dokumentablage zuweisen.';
        if ($location=$storage->location($actor)) { try { $storage->paths($location); $ready=true; $storageMessage='Geprüfte Dokumentablage verfügbar.'; } catch (RuntimeException $e) { $storageMessage=$e->getMessage(); } }
        $users=[];
        if ($actor->row['role']==='admin') { $s=$db->prepare('SELECT id,display_name FROM users WHERE tenant_id=? AND active=1 ORDER BY display_name'); $s->execute([$actor->tenantId()]); $users=$s->fetchAll(); }
        $workbench=new InboundWorkbench($db,$root);
        $ai=(new AiConfiguration($db,$identity))->get($actor);
        $result=['folders'=>$documents->folders($actor),'folderCounts'=>$documents->folderCounts($actor),'inboxCount'=>$documents->inboxCount($actor)+$workbench->count($actor),'unfiledCount'=>$documents->unfiledCount($actor),'allCount'=>$documents->allCount($actor),'trashCount'=>$documents->trashCount($actor),'tags'=>$documents->tags($actor),'accounting'=>$documents->accounting($actor),'preferences'=>$documents->preferences($actor),'storageReady'=>$ready,'storageMessage'=>$storageMessage,'users'=>$users,'sources'=>$sourceManager->sources($actor),'aiReady'=>$ai['enabled']&&$ai['external_processing_confirmed']&&$ai['secret_set']];
    } elseif ($api==='list') $result=$documents->listing($actor,$_GET);
    elseif ($api==='sources') $result=$sourceManager->sources($actor);
    elseif ($api==='sourceBrowse') $result=(new RemoteSourceBrowser($sourceManager))->browse($actor,(int)($_GET['id']??0));
    elseif ($api==='sourceJob') $result=(new RemoteFetchJobs($db,$root,$identity))->job($actor,(int)($_GET['id']??0));
    elseif ($api==='inboundItems') $result=(new InboundWorkbench($db,$root))->items($actor,(string)($_GET['query']??''));
    elseif ($api==='inboundProposal') $result=(new InboundAcceptance($db,$root))->proposal($actor,(int)($_GET['id']??0));
    elseif ($api==='inboundAccept') {
        $input=json_decode(field('input'),true,64,JSON_THROW_ON_ERROR);
        if (!is_array($input)) throw new RuntimeException('Ungültige Übernahmeangaben.');
        $input['tagMode']='replace';
        $result=['id'=>(new InboundAcceptance($db,$root))->accept($actor,(int)field('id'),(int)field('revision'),$input)];
    }
    elseif ($api==='inboundBatchPreview') $result=(new InboundAcceptance($db,$root))->batchPreview($actor,json_decode(field('items'),true,16,JSON_THROW_ON_ERROR));
    elseif ($api==='inboundBatchAccept') $result=['id'=>(new InboundAcceptance($db,$root))->acceptBatchItem($actor,(int)field('id'),(int)field('revision'),field('proposalToken'),json_decode(field('shared'),true,16,JSON_THROW_ON_ERROR))];
    elseif ($api==='inboundGet') { $inboundId=(int)($_GET['id']??0); $result=(new InboundWorkbench($db,$root))->get($actor,$inboundId); $aiJobs=new AiJobs($db,$root,$identity); $result['ai_history']=$aiJobs->history($actor,$inboundId); $result['active_ai_job_id']=$aiJobs->activeJob($actor,$inboundId); }
    elseif ($api==='inboundAiJob') $result=(new AiJobs($db,$root,$identity))->job($actor,(int)($_GET['id']??0));
    elseif ($api==='get') $result=$documents->get($actor,(int)($_GET['id']??0));
    elseif ($api==='folderDeletePreview') { $preview=$documents->folderDeletePreview($actor,(int)($_GET['id']??0)); $result=['name'=>$preview['name'],'folders'=>$preview['folders'],'documents'=>$preview['documents'],'fingerprint'=>$preview['fingerprint']]; }
    elseif ($api==='upload') $result=['id'=>$documents->upload($actor,is_array($_FILES['document']??null)?$_FILES['document']:[])];
    elseif ($api==='save') { $input=$_POST; $input['tags']=json_decode(field('tags'),true,16,JSON_THROW_ON_ERROR); $input['newTags']=json_decode(field('newTags')?:'[]',true,16,JSON_THROW_ON_ERROR); $documents->save($actor,(int)field('id'),(int)field('revision'),$input); }
    elseif ($api==='status') $documents->status($actor,(int)field('id'),(int)field('revision'),field('operation'));
    elseif ($api==='folder') {
        if (field('operation')==='delete' && (field('confirm')!=='yes' || !preg_match('/^[a-f0-9]{64}$/D',field('fingerprint')))) throw new RuntimeException('Bitte die Ordnerlöschung ausdrücklich bestätigen.');
        $parent=field('parent_id');
        $parentId=$parent===''?null:(ctype_digit($parent)?(int)$parent:null);
        if ($parent!=='' && $parentId===null) throw new RuntimeException('Ungültiger übergeordneter Ordner.');
        $documents->folderWrite($actor,field('operation'),(int)field('id'),field('name'),$parentId,field('operation')==='delete'?field('fingerprint'):null);
    }
    elseif ($api==='link') $documents->link($actor,(int)field('id'),(int)field('revision'),(int)field('folder'),field('remove')==='1');
    elseif ($api==='tag') $documents->createTag($actor,field('name'));
    elseif ($api==='bulk') $result=['count'=>$documents->bulk($actor,['documents'=>json_decode(field('documents'),true,512,JSON_THROW_ON_ERROR),'folderAction'=>field('folderAction'),'folderId'=>field('folderId'),'sourceFolderId'=>field('sourceFolderId'),'tagAction'=>field('tagAction'),'tags'=>json_decode(field('tags'),true,512,JSON_THROW_ON_ERROR),'ownerId'=>field('ownerId'),'status'=>field('status')])];
    elseif ($api==='trashPurgeInfo') $result=(new TrashRetention($db,$root))->manualInfo($actor);
    elseif ($api==='trashPurge') { if (field('confirm')!=='yes') throw new RuntimeException('Bitte die endgültige Löschung ausdrücklich bestätigen.'); $result=['removed'=>(new TrashRetention($db,$root))->manualPurge($actor,(int)field('id'),(int)field('revision'))?1:0]; }
    elseif ($api==='invoice') { $invoice=json_decode(field('invoice'),true,512,JSON_THROW_ON_ERROR); if (!is_array($invoice)) throw new RuntimeException('Ungültige Buchungsdaten.'); ($invoice['mode']??'')==='partial'?$documents->savePartialInvoice($actor,(int)field('id'),(int)field('revision'),$invoice):$documents->saveInvoice($actor,(int)field('id'),(int)field('revision'),$invoice); }
    elseif ($api==='preferences') $result=$documents->preferences($actor,json_decode(field('preferences'),true,16,JSON_THROW_ON_ERROR));
    elseif ($api==='sourceFetch') $result=(new RemoteFetchJobs($db,$root,$identity))->enqueue($actor,(int)field('source'),json_decode(field('keys'),true,16,JSON_THROW_ON_ERROR));
    elseif ($api==='sourceRun') $result=(new RemoteFetchJobs($db,$root,$identity))->runManualStep($actor,(int)field('id'));
    elseif ($api==='inboundDelete') $result=['count'=>(new InboundWorkbench($db,$root))->delete($actor,json_decode(field('items'),true,16,JSON_THROW_ON_ERROR))];
    elseif ($api==='inboundAiStart') $result=(new AiJobs($db,$root,$identity))->enqueue($actor,json_decode(field('items'),true,16,JSON_THROW_ON_ERROR));
    elseif ($api==='inboundAiRun') { @set_time_limit(600); $result=(new AiJobs($db,$root,$identity))->runManualStep($actor,(int)field('id')); }
    else { http_response_code(404); throw new RuntimeException('Unbekannter Aufruf.'); }
    header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>true,'data'=>$result],JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    if (!http_response_code() || http_response_code()===200) http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    $message=$e instanceof PDOException?'Datenbankaktion fehlgeschlagen. Eventuell existiert dieser Name bereits.':($e instanceof RuntimeException?$e->getMessage():'Ungültige Eingabe oder interner Fehler. Bitte neu laden.');
    $message=O8\Core\Languages::display($languageCatalog,$language,$message);
    echo json_encode(['success'=>false,'error'=>$message],JSON_INVALID_UTF8_SUBSTITUTE);
}
exit;
