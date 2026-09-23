<?php
declare(strict_types=1);
namespace O8\Inbound;

use O8\Auth\{Access,Actor};

/** Tenant/owner/revision-safe AI queue with one bounded item per manual step. */
final class AiJobs
{
    private const HEARTBEAT_KEY='worker.inbound_ai.heartbeat';
    public function __construct(private \PDO $db,private string $root,private array $identity) {}

    public function enqueue(Actor $actor,array $selection,string $origin='manual'): array
    {
        if (!$selection || count($selection)>50) throw new \RuntimeException('Bitte 1 bis 50 Eingangselemente auswählen.'); $expected=[];
        foreach ($selection as $row) { if (!is_array($row) || !ctype_digit((string)($row['id']??'')) || !ctype_digit((string)($row['revision']??''))) throw new \RuntimeException('Ungültige Eingangsauswahl.'); $expected[(int)$row['id']]=(int)$row['revision']; }
        if (count($expected)!==count($selection)) throw new \RuntimeException('Doppelte Eingangsauswahl.');
        $config=(new AiConfiguration($this->db,$this->identity))->get($actor); if (!$config['enabled'] || !$config['external_processing_confirmed'] || !$config['secret_set']) throw new \RuntimeException('Externe KI ist für diesen Mandanten nicht vollständig eingerichtet.');
        $this->db->beginTransaction();
        try {
            (new Access($this->db))->tenant($actor); $ids=array_keys($expected); $marks=implode(',',array_fill(0,count($ids),'?')); $owner=$actor->row['role']==='admin'?'':' AND i.owner_id=?'; $params=[$actor->tenantId(),...$ids]; if ($actor->row['role']!=='admin') $params[]=$actor->id();
            $stmt=$this->db->prepare("SELECT i.id,i.revision,s.config_json FROM inbound_items i JOIN source_items si ON si.tenant_id=i.tenant_id AND si.id=i.source_item_id JOIN import_sources s ON s.tenant_id=si.tenant_id AND s.id=si.source_id WHERE i.tenant_id=? AND i.id IN ($marks) AND i.state='pending'$owner FOR UPDATE"); $stmt->execute($params); $rows=$stmt->fetchAll();
            if (count($rows)!==count($ids)) throw new \RuntimeException('Mindestens ein Eingangselement ist nicht verfügbar. Nichts wurde gestartet.');
            foreach ($rows as $row) { if ($expected[(int)$row['id']]!==(int)$row['revision']) throw new \RuntimeException('Eingangsauswahl wurde zwischenzeitlich geändert. Bitte neu laden.'); $source=json_decode((string)$row['config_json'],true,16,JSON_THROW_ON_ERROR); if (($source['ai_mode']??'manual')==='off') throw new \RuntimeException('Für mindestens eine Quelle ist KI-Verarbeitung deaktiviert. Nichts wurde gestartet.'); }
            $stmt=$this->db->prepare("SELECT COUNT(*) FROM inbound_ai_job_items ji JOIN background_jobs j ON j.tenant_id=ji.tenant_id AND j.id=ji.job_id WHERE ji.tenant_id=? AND ji.inbound_item_id IN ($marks) AND j.status IN ('queued','running')"); $stmt->execute([$actor->tenantId(),...$ids]); if ((int)$stmt->fetchColumn()>0) throw new \RuntimeException('Mindestens ein Eingangselement befindet sich bereits in einem laufenden KI-Auftrag.');
            $stmt=$this->db->prepare("INSERT INTO background_jobs (tenant_id,owner_id,kind,status,payload_json,total_count) VALUES (?,?,'inbound_ai','queued','{}',?)"); $stmt->execute([$actor->tenantId(),$actor->id(),count($ids)]); $job=(int)$this->db->lastInsertId();
            $run=$this->db->prepare("INSERT INTO inbound_ai_runs (tenant_id,inbound_item_id,requested_by,origin,status) VALUES (?,?,?,?,'queued')"); $jobItem=$this->db->prepare('INSERT INTO inbound_ai_job_items (tenant_id,job_id,ai_run_id,inbound_item_id,expected_revision) VALUES (?,?,?,?,?)');
            foreach ($ids as $id) { $run->execute([$actor->tenantId(),$id,$actor->id(),$origin]); $runId=(int)$this->db->lastInsertId(); $jobItem->execute([$actor->tenantId(),$job,$runId,$id,$expected[$id]+1]); }
            $stmt=$this->db->prepare("UPDATE inbound_items SET ai_status='queued',error_code=NULL,revision=revision+1 WHERE tenant_id=? AND id IN ($marks)"); $stmt->execute([$actor->tenantId(),...$ids]);
            $this->audit($actor,$job,'inbound.ai.queued'); $this->db->commit(); return $this->job($actor,$job);
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }

    public function job(Actor $actor,int $jobId): array
    {
        (new Access($this->db))->tenant($actor); $owner=$actor->row['role']==='admin'?'':' AND j.owner_id=?'; $stmt=$this->db->prepare("SELECT j.id,j.status,j.total_count,j.completed_count,j.failed_count,j.error_code,j.created_at,j.finished_at FROM background_jobs j WHERE j.tenant_id=? AND j.id=? AND j.kind='inbound_ai'$owner"); $params=[$actor->tenantId(),$jobId]; if ($actor->row['role']!=='admin') $params[]=$actor->id(); $stmt->execute($params); $job=$stmt->fetch(); if (!$job) throw new \RuntimeException('KI-Auftrag nicht verfügbar.');
        $stmt=$this->db->prepare('SELECT ji.inbound_item_id,ji.status,ji.error_code,i.original_name FROM inbound_ai_job_items ji JOIN inbound_items i ON i.tenant_id=ji.tenant_id AND i.id=ji.inbound_item_id WHERE ji.tenant_id=? AND ji.job_id=? ORDER BY ji.id'); $stmt->execute([$actor->tenantId(),$jobId]); $job['items']=$stmt->fetchAll(); return $job;
    }

    public function runManualStep(Actor $actor,int $jobId): array
    {
        (new Access($this->db))->tenant($actor); $token=bin2hex(random_bytes(24)); $this->db->beginTransaction();
        try {
            $owner=$actor->row['role']==='admin'?'':' AND owner_id=?'; $stmt=$this->db->prepare("SELECT * FROM background_jobs WHERE tenant_id=? AND id=? AND kind='inbound_ai'$owner FOR UPDATE"); $params=[$actor->tenantId(),$jobId]; if ($actor->row['role']!=='admin') $params[]=$actor->id(); $stmt->execute($params); $job=$stmt->fetch(); if (!$job) throw new \RuntimeException('KI-Auftrag nicht verfügbar.'); if (in_array($job['status'],['completed','failed'],true)) { $this->db->commit(); return $this->job($actor,$jobId); }
            if ($job['status']==='running' && $job['lease_until']!==null && strtotime((string)$job['lease_until'].' UTC')>=time()) throw new \RuntimeException('KI-Auftrag wird bereits verarbeitet.');
            $stmt=$this->db->prepare("UPDATE inbound_ai_job_items SET status='queued' WHERE tenant_id=? AND job_id=? AND status='running'"); $stmt->execute([$actor->tenantId(),$jobId]);
            $stmt=$this->db->prepare("UPDATE background_jobs SET status='running',worker_token=?,lease_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),attempt_count=attempt_count+1,error_code=NULL WHERE tenant_id=? AND id=?"); $stmt->execute([$token,$actor->tenantId(),$jobId]); $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
        $this->executeOne($actor,$job,$token); return $this->job($actor,$jobId);
    }

    /** Process one item from the oldest queued/stale AI job; intended for cron. */
    public function runNext(): ?array
    {
        $this->db->prepare("INSERT INTO platform_settings (setting_key,value_json,updated_by) VALUES (?,JSON_OBJECT('status','active'),NULL) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_by=NULL,updated_at=UTC_TIMESTAMP()")->execute([self::HEARTBEAT_KEY]);
        $stmt=$this->db->query("SELECT tenant_id,id,owner_id FROM background_jobs WHERE kind='inbound_ai' AND (status='queued' OR (status='running' AND lease_until<UTC_TIMESTAMP())) ORDER BY id LIMIT 1"); $row=$stmt->fetch(); if (!$row) return null;
        return $this->runManualStep($this->workerActor((int)$row['tenant_id'],(int)$row['owner_id']),(int)$row['id']);
    }

    public function workerActive(): bool
    {
        $stmt=$this->db->prepare('SELECT TIMESTAMPDIFF(SECOND,updated_at,UTC_TIMESTAMP()) FROM platform_settings WHERE setting_key=?'); $stmt->execute([self::HEARTBEAT_KEY]); $age=$stmt->fetchColumn(); return $age!==false && (int)$age<=180;
    }

    public function history(Actor $actor,int $inboundId): array
    {
        (new Access($this->db))->tenant($actor); $owner=$actor->row['role']==='admin'?'':' AND i.owner_id=?'; $stmt=$this->db->prepare("SELECT r.id,r.origin,r.status,r.error_code,r.created_at,r.started_at,r.finished_at,u.display_name AS requested_by_name FROM inbound_ai_runs r JOIN inbound_items i ON i.tenant_id=r.tenant_id AND i.id=r.inbound_item_id JOIN users u ON u.tenant_id=r.tenant_id AND u.id=r.requested_by WHERE r.tenant_id=? AND r.inbound_item_id=?$owner ORDER BY r.id DESC LIMIT 20"); $params=[$actor->tenantId(),$inboundId]; if ($actor->row['role']!=='admin') $params[]=$actor->id(); $stmt->execute($params); return $stmt->fetchAll();
    }

    public function activeJob(Actor $actor,int $inboundId): ?int
    {
        (new Access($this->db))->tenant($actor); $owner=$actor->row['role']==='admin'?'':' AND i.owner_id=?'; $stmt=$this->db->prepare("SELECT j.id FROM inbound_ai_job_items ji JOIN background_jobs j ON j.tenant_id=ji.tenant_id AND j.id=ji.job_id JOIN inbound_items i ON i.tenant_id=ji.tenant_id AND i.id=ji.inbound_item_id WHERE ji.tenant_id=? AND ji.inbound_item_id=? AND j.status IN ('queued','running')$owner ORDER BY j.id DESC LIMIT 1"); $params=[$actor->tenantId(),$inboundId]; if ($actor->row['role']!=='admin') $params[]=$actor->id(); $stmt->execute($params); $id=$stmt->fetchColumn(); return $id===false?null:(int)$id;
    }

    private function executeOne(Actor $actor,array $job,string $token): void
    {
        $stmt=$this->db->prepare("SELECT * FROM inbound_ai_job_items WHERE tenant_id=? AND job_id=? AND status='queued' ORDER BY id LIMIT 1"); $stmt->execute([$actor->tenantId(),$job['id']]); $item=$stmt->fetch();
        if (!$item) { $this->finish($job,$token); return; }
        $this->db->beginTransaction();
        try {
            $stmt=$this->db->prepare("SELECT revision,state FROM inbound_items WHERE tenant_id=? AND id=? FOR UPDATE"); $stmt->execute([$actor->tenantId(),$item['inbound_item_id']]); $inbound=$stmt->fetch();
            if (!$inbound || $inbound['state']!=='pending' || (int)$inbound['revision']!==(int)$item['expected_revision']) throw new \RuntimeException('inbound_changed');
            $this->db->prepare("UPDATE inbound_ai_job_items SET status='running' WHERE tenant_id=? AND id=?")->execute([$actor->tenantId(),$item['id']]);
            $this->db->prepare("UPDATE inbound_ai_runs SET status='running',started_at=UTC_TIMESTAMP() WHERE tenant_id=? AND id=?")->execute([$actor->tenantId(),$item['ai_run_id']]);
            $this->db->prepare("UPDATE inbound_items SET ai_status='running',revision=revision+1 WHERE tenant_id=? AND id=?")->execute([$actor->tenantId(),$item['inbound_item_id']]); $this->db->commit();
            $result=(new AiProcessor($this->db,$this->root,$this->identity))->process($actor,(int)$item['inbound_item_id']); (new AiProcessor($this->db,$this->root,$this->identity))->store($actor,(int)$item['inbound_item_id'],$result['json']);
            $this->db->beginTransaction(); $this->db->prepare("UPDATE inbound_ai_job_items SET status='completed',error_code=NULL,finished_at=UTC_TIMESTAMP() WHERE tenant_id=? AND id=?")->execute([$actor->tenantId(),$item['id']]); $this->db->prepare("UPDATE inbound_ai_runs SET status='ready',result_json=?,error_code=NULL,finished_at=UTC_TIMESTAMP() WHERE tenant_id=? AND id=?")->execute([json_encode($result['data'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$actor->tenantId(),$item['ai_run_id']]); $this->db->prepare('UPDATE background_jobs SET completed_count=completed_count+1 WHERE tenant_id=? AND id=?')->execute([$actor->tenantId(),$job['id']]); $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack(); $code=$this->errorCode($error); $this->db->beginTransaction(); try { $this->db->prepare("UPDATE inbound_ai_job_items SET status='failed',error_code=?,finished_at=UTC_TIMESTAMP() WHERE tenant_id=? AND id=?")->execute([$code,$actor->tenantId(),$item['id']]); $this->db->prepare("UPDATE inbound_ai_runs SET status='failed',error_code=?,finished_at=UTC_TIMESTAMP() WHERE tenant_id=? AND id=?")->execute([$code,$actor->tenantId(),$item['ai_run_id']]); $this->db->prepare("UPDATE inbound_items SET ai_status='failed',error_code=?,revision=revision+1 WHERE tenant_id=? AND id=? AND state='pending'")->execute([$code,$actor->tenantId(),$item['inbound_item_id']]); $this->db->prepare('UPDATE background_jobs SET failed_count=failed_count+1 WHERE tenant_id=? AND id=?')->execute([$actor->tenantId(),$job['id']]); $this->db->commit(); } catch (\Throwable $ignored) { if ($this->db->inTransaction()) $this->db->rollBack(); }
        }
        $this->finish($job,$token);
    }

    private function finish(array $job,string $token): void
    {
        $stmt=$this->db->prepare("SELECT SUM(status='queued'),SUM(status='failed') FROM inbound_ai_job_items WHERE tenant_id=? AND job_id=?"); $stmt->execute([$job['tenant_id'],$job['id']]); [$queued,$failed]=array_map('intval',$stmt->fetch(\PDO::FETCH_NUM));
        $status=$queued>0?'queued':($failed>0?'failed':'completed'); $stmt=$this->db->prepare('UPDATE background_jobs SET status=?,error_code=?,lease_until=NULL,worker_token=NULL,finished_at=IF(? IN (\'completed\',\'failed\'),UTC_TIMESTAMP(),NULL) WHERE tenant_id=? AND id=? AND worker_token=?'); $stmt->execute([$status,$failed?'ai_item_failed':null,$status,$job['tenant_id'],$job['id'],$token]);
    }
    private function errorCode(\Throwable $error): string { $code=$error instanceof \RuntimeException?$error->getMessage():'ai_failed'; return preg_match('/^[a-z0-9_]{1,64}$/D',$code)?$code:'ai_failed'; }
    private function workerActor(int $tenant,int $owner): Actor
    {
        $stmt=$this->db->prepare('SELECT u.*,a.auth_version AS account_auth_version,a.must_change_password FROM users u JOIN accounts a ON a.id=u.account_id JOIN tenants t ON t.id=u.tenant_id WHERE u.tenant_id=? AND u.id=? AND u.active=1 AND a.active=1 AND t.active=1'); $stmt->execute([$tenant,$owner]); $row=$stmt->fetch(); if (!$row) throw new \RuntimeException('job_owner_unavailable'); $row['bootstrap_pending']=false; return new Actor('tenant',$row);
    }
    private function audit(Actor $actor,int $id,string $action): void { $stmt=$this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id) VALUES (?,?,?,'background_job',?)"); $stmt->execute([$actor->tenantId(),$actor->id(),$action,(string)$id]); }
}
