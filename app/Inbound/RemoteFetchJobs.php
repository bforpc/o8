<?php
declare(strict_types=1);
namespace O8\Inbound;

use O8\Auth\{Access,Actor};
use O8\Storage\Storage;

/** Bounded remote fetch queue. Network errors are reduced to stable error codes. */
final class RemoteFetchJobs
{
    private const MAX_DOCUMENT_BYTES=26214400;
    private const HEARTBEAT_KEY='worker.source_fetch.heartbeat';
    public function __construct(private \PDO $db,private string $root,private array $identity) {}

    public function enqueue(Actor $actor,int $sourceId,array $keys): array
    {
        (new Access($this->db))->tenant($actor);
        $listed=null; foreach ((new SourceManager($this->db,$this->identity))->sources($actor) as $source) if ((int)$source['id']===$sourceId) $listed=$source;
        if (!$listed || !(bool)$listed['enabled']) throw new \RuntimeException('Nur eine eigene aktive Quelle kann abgerufen werden.');
        $selection=(new RemoteSourceBrowser(new SourceManager($this->db,$this->identity)))->resolveSelection($actor,$sourceId,$keys);
        $this->db->beginTransaction();
        try {
            $stmt=$this->db->prepare("SELECT interval_minutes FROM import_sources WHERE tenant_id=? AND owner_id=? AND id=? AND enabled=1 FOR UPDATE"); $stmt->execute([$actor->tenantId(),$actor->id(),$sourceId]); $interval=$stmt->fetchColumn();
            if ($interval===false) throw new \RuntimeException('Quelle nicht mehr verfügbar.');
            if ((int)$interval===0) {
                $stmt=$this->db->prepare("SELECT j.id FROM background_jobs j WHERE j.tenant_id=? AND j.owner_id=? AND j.source_id=? AND j.kind='source_fetch' AND (j.status='queued' OR (j.status='running' AND j.lease_until<UTC_TIMESTAMP())) ORDER BY j.id LIMIT 1 FOR UPDATE");
                $stmt->execute([$actor->tenantId(),$actor->id(),$sourceId]); $manualJob=$stmt->fetchColumn();
                if ($manualJob!==false) {
                    $insert=$this->db->prepare("INSERT INTO source_fetch_items (tenant_id,job_id,remote_key_hash,locator_json,original_name,expected_size,expected_mime) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE locator_json=VALUES(locator_json),original_name=VALUES(original_name),expected_size=VALUES(expected_size),expected_mime=VALUES(expected_mime),error_code=NULL");
                    foreach ($selection['items'] as $item) {
                        $stmt=$this->db->prepare('SELECT 1 FROM source_items WHERE tenant_id=? AND source_id=? AND remote_key_hash=?'); $stmt->execute([$actor->tenantId(),$sourceId,$item['remoteKey']]);
                        if ($stmt->fetchColumn()) throw new \RuntimeException('Mindestens eine ausgewählte Datei wurde bereits in den Eingang übernommen. Bitte die Quellenliste aktualisieren.');
                        $insert->execute([$actor->tenantId(),(int)$manualJob,$item['remoteKey'],json_encode(['kind'=>$selection['kind'],'document'=>$item['locator'],'sidecars'=>$item['sidecars']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$this->name($item['filename']),(int)$item['size'],mb_substr((string)$item['mime'],0,100)]);
                    }
                    $stmt=$this->db->prepare("UPDATE background_jobs SET status='queued',total_count=(SELECT COUNT(*) FROM source_fetch_items WHERE tenant_id=? AND job_id=?),lease_until=NULL,worker_token=NULL,error_code=NULL WHERE tenant_id=? AND id=?"); $stmt->execute([$actor->tenantId(),(int)$manualJob,$actor->tenantId(),(int)$manualJob]);
                    $this->audit($actor,(int)$manualJob,'source.fetch.extended'); $this->db->commit(); return $this->job($actor,(int)$manualJob);
                }
            }
            $queuedJobs=[];
            foreach ($selection['items'] as $item) {
                $stmt=$this->db->prepare('SELECT 1 FROM source_items WHERE tenant_id=? AND source_id=? AND remote_key_hash=?');
                $stmt->execute([$actor->tenantId(),$sourceId,$item['remoteKey']]);
                if ($stmt->fetchColumn()) throw new \RuntimeException('Mindestens eine ausgewählte Datei wurde bereits in den Eingang übernommen. Bitte die Quellenliste aktualisieren.');
                $stmt=$this->db->prepare("SELECT j.id,j.total_count FROM source_fetch_items fi JOIN background_jobs j ON j.tenant_id=fi.tenant_id AND j.id=fi.job_id WHERE fi.tenant_id=? AND j.source_id=? AND fi.remote_key_hash=? AND j.status IN ('queued','running')");
                $stmt->execute([$actor->tenantId(),$sourceId,$item['remoteKey']]);
                if ($queued=$stmt->fetch()) $queuedJobs[]=['id'=>(int)$queued['id'],'total'=>(int)$queued['total_count']];
            }
            if ($queuedJobs) {
                $ids=array_values(array_unique(array_column($queuedJobs,'id')));
                if (count($queuedJobs)===count($selection['items']) && count($ids)===1 && $queuedJobs[0]['total']===count($selection['items'])) { $this->db->commit(); return $this->job($actor,$ids[0]); }
                throw new \RuntimeException('Mindestens eine ausgewählte Datei befindet sich bereits in einem anderen laufenden Abruf.');
            }
            $stmt=$this->db->prepare("INSERT INTO background_jobs (tenant_id,owner_id,source_id,kind,status,payload_json,total_count) VALUES (?,?,?,'source_fetch','queued','{}',?)");
            $stmt->execute([$actor->tenantId(),$actor->id(),$sourceId,count($selection['items'])]); $job=(int)$this->db->lastInsertId();
            $stmt=$this->db->prepare('INSERT INTO source_fetch_items (tenant_id,job_id,remote_key_hash,locator_json,original_name,expected_size,expected_mime) VALUES (?,?,?,?,?,?,?)');
            foreach ($selection['items'] as $item) $stmt->execute([$actor->tenantId(),$job,$item['remoteKey'],json_encode(['kind'=>$selection['kind'],'document'=>$item['locator'],'sidecars'=>$item['sidecars']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$this->name($item['filename']),(int)$item['size'],mb_substr((string)$item['mime'],0,100)]);
            $this->audit($actor,$job,'source.fetch.queued'); $this->db->commit();
            return $this->job($actor,$job);
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }

    public function job(Actor $actor,int $jobId): array
    {
        (new Access($this->db))->tenant($actor);
        $owner=$actor->row['role']==='admin'?'':' AND j.owner_id=?';
        $stmt=$this->db->prepare("SELECT j.id,j.source_id,j.status,j.total_count,j.completed_count,j.failed_count,j.error_code,j.created_at,j.finished_at,s.name AS source_name,s.kind AS source_kind,s.interval_minutes FROM background_jobs j JOIN import_sources s ON s.tenant_id=j.tenant_id AND s.id=j.source_id WHERE j.tenant_id=? AND j.id=? AND j.kind='source_fetch'$owner");
        $params=[$actor->tenantId(),$jobId]; if ($actor->row['role']!=='admin') $params[]=$actor->id(); $stmt->execute($params); $job=$stmt->fetch();
        if (!$job) throw new \RuntimeException('Abrufauftrag nicht verfügbar.');
        $stmt=$this->db->prepare('SELECT id,original_name,status,error_code,inbound_item_id FROM source_fetch_items WHERE tenant_id=? AND job_id=? ORDER BY id'); $stmt->execute([$actor->tenantId(),$jobId]);
        $job['items']=$stmt->fetchAll(); return $job;
    }

    public function runNext(): ?array
    {
        $this->heartbeat();
        $token=bin2hex(random_bytes(24)); $this->db->beginTransaction();
        try {
            $job=$this->db->query("SELECT j.* FROM background_jobs j JOIN import_sources s ON s.tenant_id=j.tenant_id AND s.id=j.source_id WHERE j.kind='source_fetch' AND s.interval_minutes>0 AND j.available_at<=UTC_TIMESTAMP() AND (j.status='queued' OR (j.status='running' AND j.lease_until<UTC_TIMESTAMP())) ORDER BY j.id LIMIT 1 FOR UPDATE")->fetch();
            if (!$job) { $this->db->commit(); if ($this->queueDueSource()) return $this->runNext(); return null; }
            $stmt=$this->db->prepare("UPDATE background_jobs SET status='running',worker_token=?,lease_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),attempt_count=attempt_count+1,error_code=NULL WHERE tenant_id=? AND id=? AND (status='queued' OR (status='running' AND lease_until<UTC_TIMESTAMP()))");
            $stmt->execute([$token,$job['tenant_id'],$job['id']]); if ($stmt->rowCount()!==1) throw new \RuntimeException('Abrufauftrag wurde bereits übernommen.'); $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
        return $this->execute($job,$token,null,null);
    }

    /** Execute one item of a manually selected interval-0 source inside the requesting session. */
    public function runManualStep(Actor $actor,int $jobId): array
    {
        (new Access($this->db))->tenant($actor); $token=bin2hex(random_bytes(24)); $this->db->beginTransaction();
        try {
            $stmt=$this->db->prepare("SELECT j.* FROM background_jobs j JOIN import_sources s ON s.tenant_id=j.tenant_id AND s.id=j.source_id WHERE j.tenant_id=? AND j.owner_id=? AND j.id=? AND j.kind='source_fetch' AND s.owner_id=? AND s.interval_minutes=0 FOR UPDATE");
            $stmt->execute([$actor->tenantId(),$actor->id(),$jobId,$actor->id()]); $job=$stmt->fetch();
            if (!$job) throw new \RuntimeException('Manueller Abrufauftrag nicht verfügbar.');
            if (in_array($job['status'],['completed','failed'],true)) { $this->db->commit(); return $this->job($actor,$jobId); }
            if ($job['status']==='running' && $job['lease_until']!==null && strtotime((string)$job['lease_until'].' UTC')>=time()) throw new \RuntimeException('Abrufauftrag wird bereits verarbeitet.');
            $stmt=$this->db->prepare("UPDATE background_jobs SET status='running',worker_token=?,lease_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),attempt_count=attempt_count+1,error_code=NULL WHERE tenant_id=? AND id=? AND (status='queued' OR (status='running' AND lease_until<UTC_TIMESTAMP()))");
            $stmt->execute([$token,$job['tenant_id'],$job['id']]); if ($stmt->rowCount()!==1) throw new \RuntimeException('Abrufauftrag wurde bereits übernommen.'); $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
        $this->execute($job,$token,1,$actor); return $this->job($actor,$jobId);
    }

    public function workerActive(): bool
    {
        $stmt=$this->db->prepare('SELECT TIMESTAMPDIFF(SECOND,updated_at,UTC_TIMESTAMP()) FROM platform_settings WHERE setting_key=?'); $stmt->execute([self::HEARTBEAT_KEY]); $age=$stmt->fetchColumn();
        return $age!==false && (int)$age<=180;
    }

    private function heartbeat(): void
    {
        $stmt=$this->db->prepare("INSERT INTO platform_settings (setting_key,value_json,updated_by) VALUES (?,JSON_OBJECT('status','active'),NULL) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_by=NULL,updated_at=UTC_TIMESTAMP()"); $stmt->execute([self::HEARTBEAT_KEY]);
    }

    /** Reserve and inventory at most one due automatic source per worker invocation. */
    private function queueDueSource(): bool
    {
        $this->db->beginTransaction();
        try {
            $source=$this->db->query("SELECT s.* FROM import_sources s JOIN tenants t ON t.id=s.tenant_id JOIN users u ON u.tenant_id=s.tenant_id AND u.id=s.owner_id JOIN accounts a ON a.id=u.account_id WHERE s.kind IN ('imap','webdav') AND s.enabled=1 AND s.interval_minutes>0 AND s.next_run_at<=UTC_TIMESTAMP() AND t.active=1 AND u.active=1 AND a.active=1 ORDER BY s.next_run_at,s.id LIMIT 1 FOR UPDATE")->fetch();
            if (!$source) { $this->db->commit(); return false; }
            $stmt=$this->db->prepare('UPDATE import_sources SET next_run_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL interval_minutes MINUTE) WHERE tenant_id=? AND id=?'); $stmt->execute([$source['tenant_id'],$source['id']]); $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
        try {
            $actor=$this->workerActor((int)$source['tenant_id'],(int)$source['owner_id']); $inventory=(new RemoteSourceBrowser(new SourceManager($this->db,$this->identity)))->browse($actor,(int)$source['id']); $keys=[];
            if ($inventory['kind']==='imap') foreach ($inventory['rows'] as $message) foreach ($message['attachments'] as $file) if (RemoteSourceBrowser::documentName((string)$file['filename'])) $keys[]=$file['remoteKey'];
            else foreach ($inventory['rows'] as $file) if (RemoteSourceBrowser::documentName((string)$file['filename'])) $keys[]=$file['remoteKey'];
            $available=[]; $seen=[]; $used=$this->db->prepare("SELECT 1 FROM source_items WHERE tenant_id=? AND source_id=? AND remote_key_hash=? UNION SELECT 1 FROM source_fetch_items fi JOIN background_jobs j ON j.tenant_id=fi.tenant_id AND j.id=fi.job_id WHERE fi.tenant_id=? AND j.source_id=? AND fi.remote_key_hash=? AND j.status IN ('queued','running') LIMIT 1");
            foreach ($keys as $key) { if (isset($seen[$key])) continue; $seen[$key]=true; $used->execute([$source['tenant_id'],$source['id'],$key,$source['tenant_id'],$source['id'],$key]); if (!$used->fetchColumn()) $available[]=$key; if (count($available)===100) break; }
            if (!$available) { $stmt=$this->db->prepare('UPDATE import_sources SET last_success_at=UTC_TIMESTAMP(),last_error_code=NULL WHERE tenant_id=? AND id=?'); $stmt->execute([$source['tenant_id'],$source['id']]); return false; }
            $this->enqueue($actor,(int)$source['id'],$available); return true;
        } catch (\Throwable) { $stmt=$this->db->prepare("UPDATE import_sources SET last_error_code='source_poll_failed' WHERE tenant_id=? AND id=?"); $stmt->execute([$source['tenant_id'],$source['id']]); return false; }
    }

    private function execute(array $job,string $token,?int $limit,?Actor $actor): array
    {
        try { $actor??=$this->workerActor((int)$job['tenant_id'],(int)$job['owner_id']); $sourceManager=new SourceManager($this->db,$this->identity); $connection=$sourceManager->connection($actor,(int)$job['source_id']); }
        catch (\Throwable) { return $this->failJob($job,$token,'source_unavailable'); }
        $location=(new Storage($this->db,$this->root))->location($actor); if (!$location) return $this->failJob($job,$token,'storage_missing');
        try { [, $target]=(new Storage($this->db,$this->root))->paths($location); $directory=$target.'/inbound'; $this->directory($directory,$location); }
        catch (\Throwable) { return $this->failJob($job,$token,'storage_unavailable'); }
        $sql="SELECT * FROM source_fetch_items WHERE tenant_id=? AND job_id=? AND status='queued' ORDER BY id".($limit!==null?' LIMIT '.max(1,$limit):''); $stmt=$this->db->prepare($sql); $stmt->execute([$job['tenant_id'],$job['id']]); $items=$stmt->fetchAll();
        foreach ($items as $item) {
            $this->lease($job,$token);
            try { $this->process($actor,$job,$item,$connection,$location,$directory); }
            catch (\Throwable) { $this->itemFailed($job,$item,'fetch_failed'); }
        }
        if ($limit!==null) {
            $stmt=$this->db->prepare("SELECT COUNT(*) FROM source_fetch_items WHERE tenant_id=? AND job_id=? AND status='queued'"); $stmt->execute([$job['tenant_id'],$job['id']]);
            if ((int)$stmt->fetchColumn()>0) { $stmt=$this->db->prepare("UPDATE background_jobs SET status='queued',lease_until=NULL,worker_token=NULL WHERE tenant_id=? AND id=? AND worker_token=?"); $stmt->execute([$job['tenant_id'],$job['id'],$token]); return ['id'=>(int)$job['id'],'status'=>'queued','failed'=>(int)$job['failed_count'],'warning'=>null]; }
        }
        $stmt=$this->db->prepare("SELECT COUNT(*) FROM source_fetch_items WHERE tenant_id=? AND job_id=? AND status='failed'"); $stmt->execute([$job['tenant_id'],$job['id']]); $failed=(int)$stmt->fetchColumn();
        $cleanup=null; if (!$failed && !empty($connection['config']['delete_after_fetch'])) try { $this->cleanupRemote($job,$connection); } catch (\Throwable) { $cleanup='remote_cleanup_failed'; }
        $stmt=$this->db->prepare("UPDATE background_jobs SET status=?,failed_count=?,error_code=?,lease_until=NULL,worker_token=NULL,finished_at=UTC_TIMESTAMP() WHERE tenant_id=? AND id=? AND worker_token=?");
        $stmt->execute([$failed?'failed':'completed',$failed,$failed?'fetch_failed':$cleanup,$job['tenant_id'],$job['id'],$token]);
        $stmt=$this->db->prepare("UPDATE import_sources SET last_success_at=IF(?=0,UTC_TIMESTAMP(),last_success_at),last_error_code=?,next_run_at=IF(interval_minutes=0,NULL,DATE_ADD(UTC_TIMESTAMP(),INTERVAL interval_minutes MINUTE)) WHERE tenant_id=? AND id=?");
        $stmt->execute([$failed,$failed?'fetch_failed':$cleanup,$job['tenant_id'],$job['source_id']]);
        return ['id'=>(int)$job['id'],'status'=>$failed?'failed':'completed','failed'=>$failed,'warning'=>$cleanup];
    }

    private function process(Actor $actor,array $job,array $item,array $connection,array $location,string $directory): void
    {
        $locator=json_decode((string)$item['locator_json'],true,32,JSON_THROW_ON_ERROR); $created=[]; $parts=[]; $commitStarted=false;
        try {
            $document=$this->remoteBytes($connection,$locator['document'],self::MAX_DOCUMENT_BYTES);
            if ($connection['kind']==='webdav' && (int)$item['expected_size']>0 && strlen($document)!==(int)$item['expected_size']) throw new \RuntimeException('remote_changed');
            [$mime,$extension]=$this->documentType($document,(string)$item['original_name']);
            $files=[['role'=>'original','name'=>$item['original_name'],'mime'=>$mime,'bytes'=>$document,'extension'=>$extension]];
            foreach ($locator['sidecars']??[] as $sidecar) {
                $extension=mb_strtolower(pathinfo((string)$sidecar['filename'],PATHINFO_EXTENSION)); if (!in_array($extension,['json','txt'],true)) continue;
                $bytes=$this->remoteBytes($connection,$sidecar['locator'],AiSidecar::MAX_BYTES);
                if ($connection['kind']==='webdav' && (int)($sidecar['size']??0)>0 && strlen($bytes)!==(int)$sidecar['size']) throw new \RuntimeException('remote_changed');
                $files[]=['role'=>$extension,'name'=>$this->name($sidecar['filename']),'mime'=>$extension==='json'?'application/json':'text/plain','bytes'=>$bytes,'extension'=>$extension];
            }
            foreach ($files as &$file) {
                $relative='inbound/'.bin2hex(random_bytes(24)).'.'.$file['extension']; $path=$directory.'/'.basename($relative); $part=$path.'.part';
                $handle=@fopen($part,'x+b'); if (!$handle) throw new \RuntimeException('stage_write_failed');
                try { if (fwrite($handle,$file['bytes'])!==strlen($file['bytes']) || !fflush($handle) || !fsync($handle)) throw new \RuntimeException('stage_write_failed'); } finally { fclose($handle); }
                $sha=hash('sha256',$file['bytes']); $stored=hash_file('sha256',$part); if (!$stored || !hash_equals($sha,$stored) || filesize($part)!==strlen($file['bytes'])) throw new \RuntimeException('stage_verify_failed');
                (new Storage($this->db,$this->root))->permissions($part,$location); $file['relative']=$relative; $file['path']=$path; $file['sha']=$sha; $parts[]=$part;
            } unset($file);
            foreach ($files as $index=>$file) { if (!@rename($parts[$index],$file['path'])) throw new \RuntimeException('stage_finalize_failed'); $created[]=$file['path']; }
            $parts=[]; $this->db->beginTransaction();
            try {
                $duplicate=$this->duplicate($actor->tenantId(),(string)$files[0]['sha']);
                $json=null; foreach ($files as $file) if ($file['role']==='json') $json=$file['path'];
                $catalogue=$this->db->prepare('SELECT id,name FROM tags WHERE tenant_id=? AND active=1'); $catalogue->execute([$actor->tenantId()]); $ai=(new AiSidecar())->inspect($json,$catalogue->fetchAll());
                $inventory=$duplicate?'duplicate':($ai['present']&&!$ai['valid']?'invalid':'pending');
                $stmt=$this->db->prepare('INSERT INTO source_items (tenant_id,source_id,remote_key_hash,status,sha256) VALUES (?,?,?,?,?)'); $stmt->execute([$actor->tenantId(),$job['source_id'],$item['remote_key_hash'],$inventory,$files[0]['sha']]); $sourceItem=(int)$this->db->lastInsertId();
                (new InboundWorkbench($this->db))->recordInventory($actor,$sourceItem,$actor->id(),['name'=>$item['original_name'],'mime'=>$files[0]['mime'],'size'=>strlen($files[0]['bytes']),'inventoryStatus'=>$inventory,'aiStatus'=>$ai['present']?($ai['valid']?'ready':'failed'):'not_requested','hasText'=>count(array_filter($files,fn(array $f):bool=>$f['role']==='txt'))>0,'hasJson'=>$ai['present'],'jsonValid'=>$ai['valid'],'errorCode'=>$ai['present']&&!$ai['valid']?'invalid_json':null]);
                $stmt=$this->db->prepare('SELECT id FROM inbound_items WHERE tenant_id=? AND source_item_id=?'); $stmt->execute([$actor->tenantId(),$sourceItem]); $inbound=(int)$stmt->fetchColumn();
                $stmt=$this->db->prepare('INSERT INTO inbound_files (tenant_id,inbound_item_id,role,storage_key,relative_path,original_name,mime_type,sha256,size_bytes) VALUES (?,?,?,\'main\',?,?,?,?,?)');
                foreach ($files as $file) $stmt->execute([$actor->tenantId(),$inbound,$file['role'],$file['relative'],$file['name'],$file['mime'],$file['sha'],strlen($file['bytes'])]);
                $stmt=$this->db->prepare("UPDATE source_fetch_items SET status='completed',inbound_item_id=?,error_code=NULL,finished_at=UTC_TIMESTAMP() WHERE tenant_id=? AND id=? AND status='queued'"); $stmt->execute([$inbound,$actor->tenantId(),$item['id']]);
                $stmt=$this->db->prepare('UPDATE background_jobs SET completed_count=completed_count+1 WHERE tenant_id=? AND id=?'); $stmt->execute([$actor->tenantId(),$job['id']]);
                $this->audit($actor,$inbound,'source.fetch.completed'); $commitStarted=true; $this->db->commit();
                if (($connection['config']['ai_mode']??'manual')==='automatic' && !$ai['present']) {
                    try { $revision=(int)$this->db->query('SELECT revision FROM inbound_items WHERE tenant_id='.(int)$actor->tenantId().' AND id='.$inbound)->fetchColumn(); (new AiJobs($this->db,$this->root,$this->identity))->enqueue($actor,[['id'=>$inbound,'revision'=>$revision]],'automatic'); }
                    catch (\Throwable) { /* Fetch remains successful; missing/disabled AI is visible as not requested. */ }
                }
            } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
        } catch (\Throwable $error) { foreach ($parts as $part) if (is_file($part)) @unlink($part); if (!$commitStarted) foreach ($created as $path) if (is_file($path)) @unlink($path); throw $error; }
    }

    private function remoteBytes(array $connection,array $locator,int $limit): string
    {
        if ($connection['kind']==='imap') {
            if (!function_exists('imap_open')) throw new \RuntimeException('imap_unavailable');
            $config=$connection['config']; $transport=$config['security']==='tls'?'ssl':'tls'; $mailbox='{'.$config['host'].':'.(int)$config['port'].'/imap/'.$transport.'}'.$config['folder'];
            $stream=@imap_open($mailbox,$config['username'],$connection['secret'],OP_READONLY,1,['DISABLE_AUTHENTICATOR'=>'GSSAPI']);
            try {
                if ($stream===false) throw new \RuntimeException('imap_connect_failed'); $status=@imap_status($stream,$mailbox,SA_UIDVALIDITY); if (!$status || (int)$status->uidvalidity!==(int)$locator['uidValidity']) throw new \RuntimeException('imap_changed');
                $raw=@imap_fetchbody($stream,(string)(int)$locator['uid'],(string)$locator['section'],FT_UID|FT_PEEK); if (!is_string($raw)) throw new \RuntimeException('imap_fetch_failed');
                $bytes=match((int)($locator['encoding']??0)) { 3=>base64_decode($raw,true),4=>quoted_printable_decode($raw),default=>$raw }; if (!is_string($bytes)) throw new \RuntimeException('imap_decode_failed');
            } finally { if ($stream!==false) @imap_close($stream); imap_errors(); imap_alerts(); }
        } else {
            if (!function_exists('curl_init')) throw new \RuntimeException('curl_unavailable');
            $url=$this->davUrl($connection['config'],(string)$locator['href']); $target=SourceConnectionTester::webDavTarget($url); $bytes=''; $overflow=false; $handle=curl_init($target['url']);
            $headers=[]; if (($locator['etag']??'')!=='') $headers[]='If-Match: '.$locator['etag'];
            curl_setopt_array($handle,[CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERPWD=>$connection['config']['username'].':'.$connection['secret'],CURLOPT_HTTPAUTH=>CURLAUTH_BASIC|CURLAUTH_DIGEST,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>60,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[$target['resolve']],CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk) use (&$bytes,&$overflow,$limit):int { if (strlen($bytes)+strlen($chunk)>$limit) { $overflow=true; return 0; } $bytes.=$chunk; return strlen($chunk); }]);
            try { $ok=curl_exec($handle); $status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE); if ($overflow) throw new \RuntimeException('remote_too_large'); if ($status===412) throw new \RuntimeException('remote_changed'); if ($ok===false || $status!==200) throw new \RuntimeException('dav_fetch_failed'); } finally { curl_close($handle); }
        }
        if ($bytes==='' || strlen($bytes)>$limit) throw new \RuntimeException('remote_size_invalid'); return $bytes;
    }

    private function cleanupRemote(array $job,array $connection): void
    {
        $stmt=$this->db->prepare("SELECT locator_json FROM source_fetch_items WHERE tenant_id=? AND job_id=? AND status='completed'"); $stmt->execute([$job['tenant_id'],$job['id']]); $rows=$stmt->fetchAll();
        if ($connection['kind']==='webdav') {
            foreach ($rows as $row) { $locator=json_decode($row['locator_json'],true,32,JSON_THROW_ON_ERROR); foreach (array_merge([$locator['document']],array_column($locator['sidecars']??[],'locator')) as $file) $this->davDelete($connection,(string)$file['href']); }
            return;
        }
        $uids=[]; foreach ($rows as $row) { $locator=json_decode($row['locator_json'],true,32,JSON_THROW_ON_ERROR); if (!empty($locator['document']['deleteMessageEligible'])) $uids[(int)$locator['document']['uid']]=true; }
        if (!$uids) return; $config=$connection['config']; $transport=$config['security']==='tls'?'ssl':'tls'; $mailbox='{'.$config['host'].':'.(int)$config['port'].'/imap/'.$transport.'}'.$config['folder']; $stream=@imap_open($mailbox,$config['username'],$connection['secret'],0,1,['DISABLE_AUTHENTICATOR'=>'GSSAPI']);
        try { if ($stream===false) throw new \RuntimeException('imap_cleanup_failed'); foreach (array_keys($uids) as $uid) if (!@imap_delete($stream,(string)$uid,FT_UID)) throw new \RuntimeException('imap_cleanup_failed'); if (!@imap_expunge($stream)) throw new \RuntimeException('imap_cleanup_failed'); } finally { if ($stream!==false) @imap_close($stream); imap_errors(); imap_alerts(); }
    }

    private function davDelete(array $connection,string $href): void
    {
        $url=$this->davUrl($connection['config'],$href); $target=SourceConnectionTester::webDavTarget($url); $handle=curl_init($target['url']); curl_setopt_array($handle,[CURLOPT_CUSTOMREQUEST=>'DELETE',CURLOPT_USERPWD=>$connection['config']['username'].':'.$connection['secret'],CURLOPT_HTTPAUTH=>CURLAUTH_BASIC|CURLAUTH_DIGEST,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[$target['resolve']]]);
        try { $ok=curl_exec($handle); $status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE); if ($ok===false || !in_array($status,[200,204,404],true)) throw new \RuntimeException('dav_cleanup_failed'); } finally { curl_close($handle); }
    }

    private function davUrl(array $config,string $href): string
    {
        $base=parse_url($config['url']); $parts=parse_url($href); $path=(string)($parts['path']??$href); $decoded=rawurldecode($path); $basePath=rtrim(rawurldecode((string)$base['path']),'/').'/'; if (!str_starts_with($decoded,$basePath) || preg_match('#(?:^|/)\.{1,2}(?:/|$)#D',$decoded)) throw new \RuntimeException('dav_path_invalid');
        if (isset($parts['scheme']) && (($parts['scheme']??'')!=='https' || mb_strtolower((string)$parts['host'])!==mb_strtolower((string)$base['host']) || (int)($parts['port']??443)!==(int)($base['port']??443))) throw new \RuntimeException('dav_origin_invalid');
        return 'https://'.$base['host'].(isset($base['port'])?':'.$base['port']:'').$path;
    }

    private function documentType(string $bytes,string $name): array
    {
        $temp=fopen('php://temp','w+b'); fwrite($temp,$bytes); $meta=stream_get_meta_data($temp); $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes); fclose($temp);
        $extension=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'][$mime]??null; if (!$extension) throw new \RuntimeException('unsupported_type');
        $given=mb_strtolower(pathinfo($name,PATHINFO_EXTENSION)); if (($extension==='jpg' && !in_array($given,['jpg','jpeg'],true)) || ($extension!=='jpg' && $given!==$extension)) throw new \RuntimeException('extension_mismatch');
        if ($extension==='pdf' && !str_starts_with($bytes,'%PDF-')) throw new \RuntimeException('invalid_pdf');
        if ($extension!=='pdf') { $image=@getimagesizefromstring($bytes); if (!$image || $image[0]*$image[1]>40000000) throw new \RuntimeException('invalid_image'); }
        return [$mime,$extension];
    }

    private function directory(string $path,array $location): void { if (!is_dir($path) && !@mkdir($path,0750)) throw new \RuntimeException('storage_directory_failed'); if (is_link($path) || realpath($path)!==$path) throw new \RuntimeException('storage_directory_unsafe'); (new Storage($this->db,$this->root))->permissions($path,$location,true); }
    private function duplicate(int $tenant,string $sha): bool { $stmt=$this->db->prepare("SELECT 1 FROM document_files WHERE tenant_id=? AND role='original' AND sha256=? UNION SELECT 1 FROM source_items WHERE tenant_id=? AND sha256=? LIMIT 1"); $stmt->execute([$tenant,$sha,$tenant,$sha]); return (bool)$stmt->fetchColumn(); }
    private function name(string $name): string { $name=mb_strcut(preg_replace('/[\x00-\x1f\x7f]/u','',basename(str_replace('\\','/',$name)))??'',0,760,'UTF-8'); return $name!==''?$name:throw new \RuntimeException('Ungültiger Dateiname.'); }
    private function lease(array $job,string $token): void
    {
        // Ensure a real value change even when claim and first renewal happen in the same second.
        $stmt=$this->db->prepare("UPDATE background_jobs SET lease_until=GREATEST(DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),DATE_ADD(lease_until,INTERVAL 1 SECOND)) WHERE tenant_id=? AND id=? AND worker_token=? AND status='running'");
        $stmt->execute([$job['tenant_id'],$job['id'],$token]);
        if ($stmt->rowCount()===1) return;
        $stmt=$this->db->prepare("SELECT 1 FROM background_jobs WHERE tenant_id=? AND id=? AND worker_token=? AND status='running' AND lease_until>=UTC_TIMESTAMP()"); $stmt->execute([$job['tenant_id'],$job['id'],$token]);
        if (!$stmt->fetchColumn()) throw new \RuntimeException('job_lease_lost');
    }
    private function itemFailed(array $job,array $item,string $code): void { $stmt=$this->db->prepare("UPDATE source_fetch_items SET status='failed',error_code=?,finished_at=UTC_TIMESTAMP() WHERE tenant_id=? AND id=? AND status='queued'"); $stmt->execute([$code,$job['tenant_id'],$item['id']]); if ($stmt->rowCount()===1) { $stmt=$this->db->prepare('UPDATE background_jobs SET failed_count=failed_count+1 WHERE tenant_id=? AND id=?'); $stmt->execute([$job['tenant_id'],$job['id']]); } }
    private function failJob(array $job,string $token,string $code): array { $stmt=$this->db->prepare("UPDATE background_jobs SET status='failed',failed_count=total_count-completed_count,error_code=?,lease_until=NULL,worker_token=NULL,finished_at=UTC_TIMESTAMP() WHERE tenant_id=? AND id=? AND worker_token=?"); $stmt->execute([$code,$job['tenant_id'],$job['id'],$token]); return ['id'=>(int)$job['id'],'status'=>'failed','failed'=>max(0,(int)$job['total_count']-(int)$job['completed_count']),'warning'=>null]; }
    private function workerActor(int $tenant,int $owner): Actor { $stmt=$this->db->prepare("SELECT u.*,a.id AS account_id,a.must_change_password,t.name AS tenant_name,t.public_id AS tenant_uuid FROM users u JOIN accounts a ON a.id=u.account_id JOIN tenants t ON t.id=u.tenant_id WHERE u.tenant_id=? AND u.id=? AND u.active=1 AND a.active=1 AND t.active=1"); $stmt->execute([$tenant,$owner]); $row=$stmt->fetch(); return $row?new Actor('tenant',$row):throw new \RuntimeException('job_owner_unavailable'); }
    private function audit(Actor $actor,int $id,string $action): void { $stmt=$this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id) VALUES (?,?,?,'background_job',?)"); $stmt->execute([$actor->tenantId(),$actor->id(),$action,(string)$id]); }
}
