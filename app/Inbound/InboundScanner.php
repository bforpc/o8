<?php
declare(strict_types=1);
namespace O8\Inbound;

use O8\Auth\{Access,Actor};

/** Inventory only: no source file is moved and no document is created. */
final class InboundScanner
{
    public const MAX_FILES=1000;
    public function __construct(private \PDO $db) {}

    public function sources(Actor $actor): array
    {
        (new Access($this->db))->tenant($actor);
        $sql="SELECT s.id,s.owner_id,s.name,s.enabled,s.config_json,s.last_success_at,s.last_error_code,u.display_name AS owner_name FROM import_sources s JOIN users u ON u.tenant_id=s.tenant_id AND u.id=s.owner_id WHERE s.tenant_id=? AND s.kind='inbound'".($actor->row['role']==='admin'?'':' AND s.owner_id=?').' ORDER BY s.name,s.id';
        $stmt=$this->db->prepare($sql); $stmt->execute($actor->row['role']==='admin'?[$actor->tenantId()]:[$actor->tenantId(),$actor->id()]);
        $rows=$stmt->fetchAll();
        foreach ($rows as &$row) { $config=$this->config((string)$row['config_json']); $row['path']=$config['path']; unset($row['config_json']); }
        return $rows;
    }

    public function scan(Actor $actor,int $sourceId): array
    {
        (new Access($this->db))->tenant($actor);
        $source=$this->source($actor,$sourceId);
        if (!(bool)$source['enabled']) throw new \RuntimeException('Inbound-Quelle ist nicht aktiviert.');
        $config=$this->config((string)$source['config_json']);
        $base=$this->safeDirectory($config['path']);
        $catalogue=$this->catalogue($actor->tenantId());
        $sidecars=new AiSidecar(); $items=[];
        try {
            foreach (new \FilesystemIterator($base,\FilesystemIterator::SKIP_DOTS) as $entry) {
                if (count($items)>=self::MAX_FILES) throw new \RuntimeException('Inbound enthält mehr als 1000 Dokumentdateien. Quelle aufteilen oder stapelweise leeren.');
                if ($entry->isLink() || !$entry->isFile()) continue;
                $extension=mb_strtolower($entry->getExtension());
                if (!in_array($extension,['pdf','jpg','jpeg','png'],true)) continue;
                $path=$entry->getPathname(); $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);
                $expected=['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png'][$extension];
                if ($mime!==$expected) continue;
                $sha=hash_file('sha256',$path); if (!$sha) throw new \RuntimeException('Prüfsumme einer Inbound-Datei konnte nicht gelesen werden.');
                $relative=$entry->getFilename();
                $remote=hash('sha256',$relative); // Stable local identity; content changes update the same source item.
                $stem=pathinfo($relative,PATHINFO_FILENAME);
                $jsonPath=$base.DIRECTORY_SEPARATOR.$stem.'.json';
                $txtPath=$base.DIRECTORY_SEPARATOR.$stem.'.txt';
                $ai=$sidecars->inspect(is_file($jsonPath)?$jsonPath:null,$catalogue);
                $duplicate=$this->duplicate($actor->tenantId(),$sourceId,$remote,$sha);
                $status=$duplicate?'duplicate':($ai['present']&&!$ai['valid']?'invalid':'pending');
                $hasText=is_file($txtPath)&&!is_link($txtPath)&&is_readable($txtPath);
                $this->upsert($actor,$sourceId,(int)$source['owner_id'],$remote,$sha,$status,['name'=>$relative,'mime'=>$mime,'size'=>$entry->getSize(),'hasText'=>$hasText,'hasJson'=>$ai['present'],'jsonValid'=>$ai['valid'],'aiStatus'=>$ai['present']?($ai['valid']?'ready':'failed'):'not_requested','errorCode'=>$ai['present']&&!$ai['valid']?'invalid_json':null]);
                $items[]=['name'=>$relative,'sha256'=>$sha,'status'=>$status,'hasText'=>$hasText,'hasJson'=>$ai['present'],'jsonValid'=>$ai['valid'],'matchedTags'=>$ai['matchedTags'],'ignoredTags'=>$ai['ignoredTags'],'error'=>$ai['error']];
            }
            $stmt=$this->db->prepare("UPDATE import_sources SET last_success_at=UTC_TIMESTAMP(),last_error_code=NULL WHERE tenant_id=? AND id=? AND kind='inbound'"); $stmt->execute([$actor->tenantId(),$sourceId]);
        } catch (\Throwable $error) {
            $stmt=$this->db->prepare("UPDATE import_sources SET last_error_code='scan_failed' WHERE tenant_id=? AND id=? AND kind='inbound'"); $stmt->execute([$actor->tenantId(),$sourceId]);
            throw $error;
        }
        $counts=['pending'=>0,'duplicate'=>0,'invalid'=>0]; foreach ($items as $item) ++$counts[$item['status']];
        return ['sourceId'=>$sourceId,'count'=>count($items),'counts'=>$counts,'items'=>$items];
    }

    private function source(Actor $actor,int $id): array
    {
        $sql="SELECT * FROM import_sources WHERE tenant_id=? AND id=? AND kind='inbound'".($actor->row['role']==='admin'?'':' AND owner_id=?');
        $stmt=$this->db->prepare($sql); $stmt->execute($actor->row['role']==='admin'?[$actor->tenantId(),$id]:[$actor->tenantId(),$id,$actor->id()]);
        return $stmt->fetch()?:throw new \RuntimeException('Inbound-Quelle nicht verfügbar.');
    }
    private function config(string $json): array
    {
        try { $config=json_decode($json,true,16,JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new \RuntimeException('Inbound-Konfiguration ist ungültig.'); }
        $path=trim((string)($config['path']??''));
        if ($path==='' || $path[0]!==DIRECTORY_SEPARATOR || strlen($path)>1000 || str_contains($path,"\0")) throw new \RuntimeException('Inbound-Pfad muss absolut und gültig sein.');
        return ['path'=>rtrim($path,DIRECTORY_SEPARATOR)];
    }
    private function safeDirectory(string $configured): string
    {
        $real=realpath($configured);
        if ($real===false || !is_dir($real) || !is_readable($real) || is_link($configured) || rtrim($configured,DIRECTORY_SEPARATOR)!==$real) throw new \RuntimeException('Inbound-Verzeichnis ist nicht sicher lesbar oder der konfigurierte Pfad ist nicht kanonisch.');
        return $real;
    }
    private function catalogue(int $tenant): array
    {
        $stmt=$this->db->prepare('SELECT id,name FROM tags WHERE tenant_id=? AND active=1 ORDER BY id'); $stmt->execute([$tenant]); return $stmt->fetchAll();
    }
    private function duplicate(int $tenant,int $source,string $remote,string $sha): bool
    {
        $stmt=$this->db->prepare('SELECT 1 FROM document_files WHERE tenant_id=? AND role=\'original\' AND sha256=? LIMIT 1'); $stmt->execute([$tenant,$sha]); if ($stmt->fetchColumn()) return true;
        $stmt=$this->db->prepare('SELECT 1 FROM source_items WHERE tenant_id=? AND sha256=? AND status=\'pending\' AND NOT (source_id=? AND remote_key_hash=?) LIMIT 1'); $stmt->execute([$tenant,$sha,$source,$remote]); return (bool)$stmt->fetchColumn();
    }
    private function upsert(Actor $actor,int $source,int $owner,string $remote,string $sha,string $status,array $item): void
    {
        $this->db->beginTransaction();
        try {
            (new Access($this->db))->tenant($actor);
            $stmt=$this->db->prepare('INSERT INTO source_items (tenant_id,source_id,remote_key_hash,status,sha256) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),sha256=VALUES(sha256),updated_at=UTC_TIMESTAMP()');
            $stmt->execute([$actor->tenantId(),$source,$remote,$status,$sha]);
            $stmt=$this->db->prepare('SELECT id FROM source_items WHERE tenant_id=? AND source_id=? AND remote_key_hash=?');
            $stmt->execute([$actor->tenantId(),$source,$remote]); $sourceItem=(int)$stmt->fetchColumn();
            (new InboundWorkbench($this->db))->recordInventory($actor,$sourceItem,$owner,[...$item,'inventoryStatus'=>$status]);
            $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }
}
