<?php
declare(strict_types=1);
namespace O8\Documents;

use O8\Auth\{Access,Actor};
use O8\Storage\Storage;

/** Personal retention and a bounded, recoverable server-side purge. */
final class TrashRetention
{
    private const HEARTBEAT='trash.retention.worker';
    private const PENDING='trash.retention.pending';
    public function __construct(private \PDO $db,private string $root) {}

    public function days(Actor $actor): int
    {
        (new Access($this->db))->tenant($actor);
        $s=$this->db->prepare("SELECT value_json FROM user_settings WHERE tenant_id=? AND user_id=? AND setting_key='trash.retention_days'");
        $s->execute([$actor->tenantId(),$actor->id()]); $json=$s->fetchColumn();
        if ($json===false) return 0;
        $value=json_decode((string)$json,true);
        return is_array($value) && is_int($value['days']??null) && $value['days']>=0 && $value['days']<=36500?$value['days']:0;
    }
    public function saveDays(Actor $actor,int $days,bool $confirmed): void
    {
        if ($days<0 || $days>36500) throw new \RuntimeException('Papierkorbfrist muss zwischen 0 und 36500 Tagen liegen.');
        $this->db->beginTransaction();
        try {
            (new Access($this->db))->tenant($actor);
            $s=$this->db->prepare('SELECT id FROM users WHERE tenant_id=? AND id=? FOR UPDATE'); $s->execute([$actor->tenantId(),$actor->id()]);
            if (!$s->fetchColumn()) throw new \RuntimeException('Benutzer nicht verfügbar.');
            $before=$this->days($actor);
            if ($days>0 && ($before===0 || $days<$before) && !$confirmed) throw new \RuntimeException('Bitte die mögliche endgültige Löschung bereits vorhandener Papierkorb-Dokumente ausdrücklich bestätigen.');
            $s=$this->db->prepare("INSERT INTO user_settings (tenant_id,user_id,setting_key,value_json) VALUES (?,?,'trash.retention_days',?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=UTC_TIMESTAMP()");
            $s->execute([$actor->tenantId(),$actor->id(),json_encode(['days'=>$days],JSON_THROW_ON_ERROR)]);
            $s=$this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id,details_json) VALUES (?,?,'trash.retention.changed','user',?,?)");
            $s->execute([$actor->tenantId(),$actor->id(),(string)$actor->id(),json_encode(['days'=>$days],JSON_THROW_ON_ERROR)]);
            $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }
    public function workerActive(): bool
    {
        $s=$this->db->prepare('SELECT TIMESTAMPDIFF(SECOND,updated_at,UTC_TIMESTAMP()) FROM platform_settings WHERE setting_key=?');
        $s->execute([self::HEARTBEAT]); $age=$s->fetchColumn(); return $age!==false && (int)$age<=600;
    }
    private function heartbeat(): void
    {
        $s=$this->db->prepare("INSERT INTO platform_settings (setting_key,value_json,updated_by) VALUES (?,JSON_OBJECT('status','active'),NULL) ON DUPLICATE KEY UPDATE updated_at=UTC_TIMESTAMP()");
        $s->execute([self::HEARTBEAT]);
    }
    private function marker(?array $job=null): void
    {
        if ($job===null) { $s=$this->db->prepare('DELETE FROM platform_settings WHERE setting_key=?'); $s->execute([self::PENDING]); return; }
        $s=$this->db->prepare('INSERT INTO platform_settings (setting_key,value_json,updated_by) VALUES (?,?,NULL) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=UTC_TIMESTAMP()');
        $s->execute([self::PENDING,json_encode($job,JSON_THROW_ON_ERROR)]);
    }
    private function location(int $tenant): string
    {
        $s=$this->db->prepare("SELECT s.*,t.public_id FROM storage_locations s JOIN tenants t ON t.id=s.tenant_id WHERE s.tenant_id=? AND s.storage_key='main' AND s.active=1 AND t.active=1");
        $s->execute([$tenant]); $row=$s->fetch();
        if (!$row) throw new \RuntimeException('Geprüfte Mandantenablage fehlt; Papierkorb-Löschung angehalten.');
        return (new Storage($this->db,$this->root))->paths($row)[1];
    }
    private function relative(string $path): void
    {
        if (!preg_match('/^[a-f0-9]{48}\.(?:pdf|jpg|png|json|txt)$/D',$path)) throw new \RuntimeException('Unsicherer Dokumentdateipfad; Papierkorb-Löschung angehalten.');
    }
    private function pending(): ?array
    {
        $s=$this->db->prepare('SELECT value_json FROM platform_settings WHERE setting_key=?'); $s->execute([self::PENDING]);
        $json=$s->fetchColumn(); return $json===false?null:json_decode((string)$json,true,32,JSON_THROW_ON_ERROR);
    }
    /** Resolve interrupted staging before any new deletion. Never overwrite a restored file. */
    private function recover(): void
    {
        $job=$this->pending(); if ($job===null) return;
        $tenant=$job['tenant']??null; $document=$job['document']??null; $files=$job['files']??null;
        if (!is_int($tenant) || $tenant<1 || !is_int($document) || $document<1 || !is_array($files)) throw new \RuntimeException('Papierkorb-Löschmarker ist ungültig.');
        $target=$this->location($tenant);
        $s=$this->db->prepare('SELECT 1 FROM documents WHERE tenant_id=? AND id=?'); $s->execute([$tenant,$document]); $exists=(bool)$s->fetchColumn();
        foreach ($files as $relative) {
            if (!is_string($relative)) throw new \RuntimeException('Papierkorb-Löschmarker enthält ungültige Dateipfade.');
            $this->relative($relative); $path=$target.'/'.$relative; $stage=$path.'.o8-purge-'.$document;
            if (is_link($path) || is_link($stage)) throw new \RuntimeException('Unsicherer Dateilink bei Papierkorb-Löschung.');
            if ($exists) {
                if (is_file($stage)) {
                    if (file_exists($path) || !@rename($stage,$path)) throw new \RuntimeException('Unterbrochene Papierkorb-Löschung konnte nicht zurückgesetzt werden.');
                } elseif (!is_file($path)) throw new \RuntimeException('Dokumentdatei fehlt nach unterbrochener Papierkorb-Löschung.');
            } elseif (is_file($stage) && !@unlink($stage)) throw new \RuntimeException('Bereits gelöschte Dokumentdatei konnte nicht bereinigt werden.');
        }
        $this->marker();
    }
    private function due(): ?array
    {
        $s=$this->db->query("SELECT d.tenant_id,d.id,d.owner_id,d.revision FROM documents d JOIN tenants t ON t.id=d.tenant_id AND t.active=1 JOIN user_settings us ON us.tenant_id=d.tenant_id AND us.user_id=d.owner_id AND us.setting_key='trash.retention_days' WHERE d.deleted_at IS NOT NULL AND CAST(JSON_UNQUOTE(JSON_EXTRACT(us.value_json,'$.days')) AS UNSIGNED) BETWEEN 1 AND 36500 AND TIMESTAMPDIFF(SECOND,d.deleted_at,UTC_TIMESTAMP())>=CAST(JSON_UNQUOTE(JSON_EXTRACT(us.value_json,'$.days')) AS UNSIGNED)*86400 ORDER BY d.deleted_at,d.id LIMIT 1");
        return $s->fetch()?:null;
    }
    private function purge(array $row,?Actor $manualActor=null): void
    {
        $tenant=(int)$row['tenant_id']; $id=(int)$row['id']; $target=$this->location($tenant);
        $s=$this->db->prepare('SELECT relative_path,sha256,size_bytes,storage_key FROM document_files WHERE tenant_id=? AND document_id=?');
        $s->execute([$tenant,$id]); $files=$s->fetchAll(); $paths=[];
        foreach ($files as $file) {
            if ($file['storage_key']!=='main') throw new \RuntimeException('Unbekannte Dokumentablage; Papierkorb-Löschung angehalten.');
            $relative=(string)$file['relative_path']; $this->relative($relative); $path=$target.'/'.$relative;
            if (is_link($path) || !is_file($path) || ($file['size_bytes']!==null && filesize($path)!==(int)$file['size_bytes']) || ($file['sha256']!==null && !hash_equals($file['sha256'],(string)hash_file('sha256',$path)))) throw new \RuntimeException('Dokumentdatei fehlt oder stimmt nicht mit der Datenbank überein; nichts gelöscht.');
            $s=$this->db->prepare('SELECT (SELECT COUNT(*) FROM document_files WHERE tenant_id=? AND relative_path=?) + (SELECT COUNT(*) FROM inbound_files WHERE tenant_id=? AND relative_path=?)');
            $s->execute([$tenant,$relative,$tenant,$relative]); if ((int)$s->fetchColumn()!==1) throw new \RuntimeException('Dokumentdatei wird mehrfach verwendet; nichts gelöscht.');
            $paths[]=$relative;
        }
        $this->marker(['tenant'=>$tenant,'document'=>$id,'files'=>$paths]);
        try {
            foreach ($paths as $relative) {
                $path=$target.'/'.$relative;
                if (!@rename($path,$path.'.o8-purge-'.$id)) throw new \RuntimeException('Datei konnte nicht sicher für die Papierkorb-Löschung vorbereitet werden.');
            }
            $this->db->beginTransaction();
            $s=$this->db->prepare('SELECT active FROM tenants WHERE id=? FOR UPDATE'); $s->execute([$tenant]);
            if (!(bool)$s->fetchColumn()) throw new \RuntimeException('Mandant nicht mehr aktiv; Papierkorb-Löschung abgebrochen.');
            $s=$this->db->prepare('SELECT owner_id,revision,deleted_at FROM documents WHERE tenant_id=? AND id=? FOR UPDATE'); $s->execute([$tenant,$id]); $doc=$s->fetch();
            if (!$doc || !$doc['deleted_at'] || (int)$doc['revision']!==(int)$row['revision']) throw new \RuntimeException('Dokument wurde zwischenzeitlich geändert; Löschung abgebrochen.');
            if ($manualActor!==null) {
                (new Access($this->db))->tenant($manualActor);
                if ($manualActor->tenantId()!==$tenant || ($manualActor->row['role']!=='admin' && $manualActor->id()!==(int)$doc['owner_id'])) throw new \RuntimeException('Dokument gehört nicht zu Ihrem Papierkorb.');
            }
            $s=$this->db->prepare('SELECT id FROM users WHERE tenant_id=? AND id=? FOR UPDATE'); $s->execute([$tenant,$doc['owner_id']]); if (!$s->fetchColumn()) throw new \RuntimeException('Besitzer nicht verfügbar.');
            $days=null;
            if ($manualActor===null) {
                $s=$this->db->prepare("SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(value_json,'$.days')) AS UNSIGNED) FROM user_settings WHERE tenant_id=? AND user_id=? AND setting_key='trash.retention_days'");
                $s->execute([$tenant,$doc['owner_id']]); $days=(int)$s->fetchColumn();
                $s=$this->db->prepare('SELECT TIMESTAMPDIFF(SECOND,?,UTC_TIMESTAMP())'); $s->execute([$doc['deleted_at']]);
                if ($days<1 || $days>36500 || (int)$s->fetchColumn()<$days*86400) throw new \RuntimeException('Papierkorbfrist wurde geändert; Löschung abgebrochen.');
            }
            $s=$this->db->prepare('DELETE FROM documents WHERE tenant_id=? AND id=? AND revision=?'); $s->execute([$tenant,$id,$doc['revision']]);
            if ($s->rowCount()!==1) throw new \RuntimeException('Dokument wurde zwischenzeitlich geändert.');
            $s=$this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id,details_json) VALUES (?,?,'document.purged','document',?,?)");
            $s->execute([$tenant,$manualActor?->id()??$doc['owner_id'],(string)$id,json_encode($manualActor===null?['retention_days'=>$days]:['manual'=>true,'owner_id'=>(int)$doc['owner_id']],JSON_THROW_ON_ERROR)]);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->recover();
            throw $error;
        }
        $this->recover();
    }
    /** One CLI invocation processes a bounded number of documents. */
    public function run(int $limit=10): int
    {
        if ($limit<1 || $limit>50) throw new \RuntimeException('Ungültige Löschgrenze.');
        $name='o8.trash.'.substr(hash('sha256',(string)$this->db->query('SELECT DATABASE()')->fetchColumn()),0,40);
        $s=$this->db->prepare('SELECT GET_LOCK(?,0)'); $s->execute([$name]);
        if ((int)$s->fetchColumn()!==1) return 0;
        try {
            $this->recover(); $this->heartbeat(); $done=0;
            while ($done<$limit && ($row=$this->due())) { $this->purge($row); ++$done; }
            return $done;
        } finally { $s=$this->db->prepare('SELECT RELEASE_LOCK(?)'); $s->execute([$name]); }
    }
    public function manualInfo(Actor $actor): array
    {
        (new Access($this->db))->tenant($actor);
        [$scope,$params]=$actor->documentScope();
        $s=$this->db->prepare("SELECT d.id,d.revision FROM documents d WHERE $scope AND d.deleted_at IS NOT NULL ORDER BY d.deleted_at,d.id");
        $s->execute($params); $items=$s->fetchAll();
        return ['count'=>count($items),'items'=>array_map(static fn(array $row): array=>['id'=>(int)$row['id'],'revision'=>(int)$row['revision']],$items)];
    }
    /** Delete exactly one document from the confirmed snapshot, regardless of retention days. */
    public function manualPurge(Actor $actor,int $id,int $revision): bool
    {
        if ($id<1 || $revision<1) throw new \RuntimeException('Ungültige Dokumentauswahl.');
        (new Access($this->db))->tenant($actor);
        $name='o8.trash.'.substr(hash('sha256',(string)$this->db->query('SELECT DATABASE()')->fetchColumn()),0,40);
        $s=$this->db->prepare('SELECT GET_LOCK(?,0)'); $s->execute([$name]);
        if ((int)$s->fetchColumn()!==1) throw new \RuntimeException('Eine andere Papierkorb-Löschung läuft bereits. Bitte kurz warten und erneut versuchen.');
        try {
            $this->recover();
            [$scope,$params]=$actor->documentScope();
            $s=$this->db->prepare("SELECT d.tenant_id,d.id,d.owner_id,d.revision FROM documents d WHERE $scope AND d.id=? AND d.deleted_at IS NOT NULL");
            $s->execute([...$params,$id]); $row=$s->fetch();
            if (!$row) return false;
            if ((int)$row['revision']!==$revision) throw new \RuntimeException('Dokument wurde seit der Bestätigung geändert. Löschung angehalten.');
            $this->purge($row,$actor); return true;
        } finally { $s=$this->db->prepare('SELECT RELEASE_LOCK(?)'); $s->execute([$name]); }
    }
}
