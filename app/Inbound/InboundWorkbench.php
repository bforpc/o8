<?php
declare(strict_types=1);
namespace O8\Inbound;

use O8\Auth\{Access,Actor};
use O8\Storage\Storage;

/** Tenant- and owner-scoped read model for entrance items; it never creates documents. */
final class InboundWorkbench
{
    public const STATES=['pending','accepted','deleted'];
    public const INVENTORY=['pending','duplicate','invalid'];
    public const AI=['not_requested','queued','running','ready','failed'];

    public function __construct(private \PDO $db,private ?string $root=null) {}

    public function items(Actor $actor,string $query=''): array
    {
        (new Access($this->db))->tenant($actor);
        $query=trim($query); if (mb_strlen($query)>250) throw new \RuntimeException('Suchtext maximal 250 Zeichen.');
        $owner=$actor->row['role']==='admin'?'':' AND i.owner_id=?';
        $search=''; $params=$actor->row['role']==='admin'?[$actor->tenantId()]:[$actor->tenantId(),$actor->id()];
        foreach (preg_split('/\s+/u',$query,-1,PREG_SPLIT_NO_EMPTY) as $term) { $search.=" AND LOCATE(?,CONCAT_WS(' ',i.original_name,s.name,s.kind,u.display_name,i.inventory_status,i.ai_status) COLLATE utf8mb4_unicode_ci)>0"; $params[]=$term; }
        $sql="SELECT i.id,i.source_item_id,i.owner_id,i.original_name,i.mime_type,i.size_bytes,i.state,i.inventory_status,i.ai_status,i.has_text_sidecar,i.has_json_sidecar,i.json_valid,i.error_code,i.revision,i.discovered_at,i.updated_at,s.kind AS source_kind,s.name AS source_name,u.display_name AS owner_name FROM inbound_items i JOIN source_items si ON si.tenant_id=i.tenant_id AND si.id=i.source_item_id JOIN import_sources s ON s.tenant_id=si.tenant_id AND s.id=si.source_id JOIN users u ON u.tenant_id=i.tenant_id AND u.id=i.owner_id WHERE i.tenant_id=?$owner AND i.state='pending'$search ORDER BY i.updated_at DESC,i.id DESC LIMIT 500";
        $stmt=$this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function count(Actor $actor): int
    {
        (new Access($this->db))->tenant($actor); $owner=$actor->row['role']==='admin'?'':' AND owner_id=?';
        $stmt=$this->db->prepare("SELECT COUNT(*) FROM inbound_items WHERE tenant_id=? AND state='pending'$owner"); $stmt->execute($actor->row['role']==='admin'?[$actor->tenantId()]:[$actor->tenantId(),$actor->id()]); return (int)$stmt->fetchColumn();
    }

    public function get(Actor $actor,int $id): array
    {
        (new Access($this->db))->tenant($actor); $owner=$actor->row['role']==='admin'?'':' AND i.owner_id=?';
        $stmt=$this->db->prepare("SELECT i.*,si.source_id,si.remote_key_hash,si.sha256,s.kind AS source_kind,s.name AS source_name,s.config_json,u.display_name AS owner_name FROM inbound_items i JOIN source_items si ON si.tenant_id=i.tenant_id AND si.id=i.source_item_id JOIN import_sources s ON s.tenant_id=si.tenant_id AND s.id=si.source_id JOIN users u ON u.tenant_id=i.tenant_id AND u.id=i.owner_id WHERE i.tenant_id=? AND i.id=? AND i.state='pending'$owner");
        $params=[$actor->tenantId(),$id]; if ($actor->row['role']!=='admin') $params[]=$actor->id(); $stmt->execute($params); $item=$stmt->fetch(); if (!$item) throw new \RuntimeException('Eingangselement nicht verfügbar.');
        unset($item['config_json'],$item['remote_key_hash'],$item['sha256']);
        $item['json_text']=$item['has_json_sidecar']?$this->sidecarText($actor,$id,'json'):null; $item['text_content']=$item['has_text_sidecar']?$this->sidecarText($actor,$id,'txt'):null;
        $item['ai_tag_matches']=[]; $item['ai_tag_ignored']=[];
        if ($item['json_text']!==null) { $tags=$this->db->prepare('SELECT id,name FROM tags WHERE tenant_id=? AND active=1'); $tags->execute([$actor->tenantId()]); $inspection=(new AiSidecar())->inspectText((string)$item['json_text'],$tags->fetchAll()); if ($inspection['valid']) { $item['ai_tag_matches']=$inspection['matchedTags']; $item['ai_tag_ignored']=$inspection['ignoredTags']; } }
        return $item;
    }

    public function open(Actor $actor,int $id,string $role='original'): array
    {
        if (!in_array($role,['original','json','txt'],true)) throw new \RuntimeException('Unbekannte Eingangdatei.');
        $item=$this->raw($actor,$id); $stmt=$this->db->prepare('SELECT * FROM inbound_files WHERE tenant_id=? AND inbound_item_id=? AND role=?'); $stmt->execute([$actor->tenantId(),$id,$role]); $file=$stmt->fetch();
        if ($file) {
            if (!$this->root) throw new \RuntimeException('Dateizugriff nicht verfügbar.'); $relative=(string)$file['relative_path'];
            if (!preg_match('#^inbound/[a-f0-9]{48}\.(?:pdf|jpg|png|json|txt)$#D',$relative)) throw new \RuntimeException('Unsicherer Eingangspfad.');
            $storage=new Storage($this->db,$this->root); $location=$storage->location($actor); if (!$location) throw new \RuntimeException('Geprüfte Ablage fehlt.'); [, $target]=$storage->paths($location); $path=$target.'/'.$relative;
            if (is_link($path) || !is_file($path) || !is_readable($path) || !hash_equals((string)$file['sha256'],(string)hash_file('sha256',$path))) throw new \RuntimeException('Eingangsdatei fehlt oder wurde verändert.');
            $handle=@fopen($path,'rb'); if (!$handle) throw new \RuntimeException('Eingangsdatei nicht lesbar.'); return ['handle'=>$handle,'size'=>(int)$file['size_bytes'],'mime'=>$file['mime_type'],'name'=>$file['original_name']];
        }
        if ($item['source_kind']!=='inbound') throw new \RuntimeException('Eingangsdatei nicht verfügbar.');
        $config=json_decode((string)$item['config_json'],true,16,JSON_THROW_ON_ERROR); $base=realpath((string)($config['path']??'')); if (!$base || is_link((string)$config['path']) || !is_dir($base)) throw new \RuntimeException('Lokale Eingangsquelle ist nicht sicher verfügbar.');
        $name=basename(str_replace('\\','/',(string)$item['original_name'])); if ($name!==$item['original_name'] || hash('sha256',$name)!==$item['remote_key_hash']) throw new \RuntimeException('Lokale Eingangsidentität ist ungültig.');
        if ($role!=='original') $name=pathinfo($name,PATHINFO_FILENAME).'.'.$role; $path=$base.'/'.$name;
        if (is_link($path) || !is_file($path) || !is_readable($path) || realpath(dirname($path))!==$base) throw new \RuntimeException('Eingangsdatei nicht verfügbar.');
        if ($role==='original' && (!hash_file('sha256',$path) || !hash_equals((string)$item['sha256'],(string)hash_file('sha256',$path)))) throw new \RuntimeException('Lokale Eingangsdatei wurde seit der Inventur verändert. Bitte neu scannen.');
        $size=filesize($path); if ($size===false || $size<1 || $size>($role==='original'?26214400:AiSidecar::MAX_BYTES)) throw new \RuntimeException('Eingangsdatei hat eine ungültige Größe.');
        $mime=$role==='original'?$item['mime_type']:($role==='json'?'application/json':'text/plain'); $handle=@fopen($path,'rb'); if (!$handle) throw new \RuntimeException('Eingangsdatei nicht lesbar.'); return ['handle'=>$handle,'size'=>$size,'mime'=>$mime,'name'=>$name];
    }

    public function delete(Actor $actor,array $selection): int
    {
        if (!$selection || count($selection)>100) throw new \RuntimeException('Bitte 1 bis 100 Eingangselemente auswählen.'); $expected=[];
        foreach ($selection as $row) { if (!is_array($row) || !ctype_digit((string)($row['id']??'')) || !ctype_digit((string)($row['revision']??''))) throw new \RuntimeException('Ungültige Eingangsauswahl.'); $expected[(int)$row['id']]=(int)$row['revision']; }
        if (count($expected)!==count($selection)) throw new \RuntimeException('Doppelte Eingangsauswahl.');
        $renamed=[]; $commitStarted=false; $this->db->beginTransaction();
        try {
            $ids=array_keys($expected); $marks=implode(',',array_fill(0,count($ids),'?')); $owner=$actor->row['role']==='admin'?'':' AND owner_id=?';
            $params=[$actor->tenantId(),...$ids]; if ($actor->row['role']!=='admin') $params[]=$actor->id(); $stmt=$this->db->prepare("SELECT id,revision FROM inbound_items WHERE tenant_id=? AND id IN ($marks) AND state='pending'$owner FOR UPDATE"); $stmt->execute($params); $rows=$stmt->fetchAll();
            if (count($rows)!==count($ids)) throw new \RuntimeException('Mindestens ein Eingangselement ist nicht verfügbar. Nichts wurde gelöscht.'); foreach ($rows as $row) if ($expected[(int)$row['id']]!==(int)$row['revision']) throw new \RuntimeException('Eingangsauswahl wurde zwischenzeitlich geändert. Nichts wurde gelöscht.');
            $paths=$this->managedPaths($actor,$ids); $token=bin2hex(random_bytes(12)); foreach ($paths as $path) { $deleting=$path.'.delete-'.$token; if (!@rename($path,$deleting)) throw new \RuntimeException('Eingangsdatei konnte nicht für die Löschung gesichert werden.'); $renamed[$path]=$deleting; }
            $stmt=$this->db->prepare("DELETE FROM inbound_files WHERE tenant_id=? AND inbound_item_id IN ($marks)"); $stmt->execute([$actor->tenantId(),...$ids]);
            $stmt=$this->db->prepare("UPDATE inbound_items SET state='deleted',completed_at=UTC_TIMESTAMP(),revision=revision+1 WHERE tenant_id=? AND id IN ($marks)"); $stmt->execute([$actor->tenantId(),...$ids]);
            $stmt=$this->db->prepare("UPDATE source_items si JOIN inbound_items i ON i.tenant_id=si.tenant_id AND i.source_item_id=si.id SET si.status='deleted' WHERE i.tenant_id=? AND i.id IN ($marks)"); $stmt->execute([$actor->tenantId(),...$ids]);
            foreach ($ids as $id) { $stmt=$this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id) VALUES (?,?, 'inbound.deleted','inbound_item',?)"); $stmt->execute([$actor->tenantId(),$actor->id(),(string)$id]); }
            $commitStarted=true; $this->db->commit(); foreach ($renamed as $deleting) @unlink($deleting); return count($ids);
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); if (!$commitStarted) foreach ($renamed as $path=>$deleting) if (is_file($deleting)) @rename($deleting,$path); throw $error; }
    }

    private function raw(Actor $actor,int $id): array
    {
        (new Access($this->db))->tenant($actor); $owner=$actor->row['role']==='admin'?'':' AND i.owner_id=?'; $stmt=$this->db->prepare("SELECT i.*,si.remote_key_hash,si.sha256,s.kind AS source_kind,s.config_json FROM inbound_items i JOIN source_items si ON si.tenant_id=i.tenant_id AND si.id=i.source_item_id JOIN import_sources s ON s.tenant_id=si.tenant_id AND s.id=si.source_id WHERE i.tenant_id=? AND i.id=? AND i.state='pending'$owner"); $params=[$actor->tenantId(),$id]; if ($actor->row['role']!=='admin') $params[]=$actor->id(); $stmt->execute($params); return $stmt->fetch()?:throw new \RuntimeException('Eingangselement nicht verfügbar.');
    }
    private function sidecarText(Actor $actor,int $id,string $role): string
    {
        $file=$this->open($actor,$id,$role);
        try {
            $text=stream_get_contents($file['handle'],AiSidecar::MAX_BYTES+1);
            if (!is_string($text) || strlen($text)>AiSidecar::MAX_BYTES) throw new \RuntimeException('Nebendatei ist zu groß oder nicht lesbar.');
            return mb_scrub($text,'UTF-8');
        } finally { fclose($file['handle']); }
    }
    private function managedPaths(Actor $actor,array $ids): array { $marks=implode(',',array_fill(0,count($ids),'?')); $stmt=$this->db->prepare("SELECT relative_path FROM inbound_files WHERE tenant_id=? AND inbound_item_id IN ($marks)"); $stmt->execute([$actor->tenantId(),...$ids]); $rows=$stmt->fetchAll(); if (!$rows) return []; if (!$this->root) throw new \RuntimeException('Dateizugriff nicht verfügbar.'); $storage=new Storage($this->db,$this->root); $location=$storage->location($actor); if (!$location) throw new \RuntimeException('Geprüfte Ablage fehlt. Nichts wurde gelöscht.'); [, $target]=$storage->paths($location); $paths=[]; foreach ($rows as $row) { $relative=$row['relative_path']; if (!preg_match('#^inbound/[a-f0-9]{48}\.(?:pdf|jpg|png|json|txt)$#D',$relative)) throw new \RuntimeException('Unsicherer Eingangspfad.'); $path=$target.'/'.$relative; if (is_link($path) || !is_file($path)) throw new \RuntimeException('Eine verwaltete Eingangsdatei fehlt. Nichts wurde gelöscht.'); $paths[]=$path; } return $paths; }

    public function recordInventory(Actor $actor,int $sourceItem,int $owner,array $item): void
    {
        (new Access($this->db))->tenant($actor);
        $source=$this->db->prepare('SELECT s.owner_id FROM source_items si JOIN import_sources s ON s.tenant_id=si.tenant_id AND s.id=si.source_id WHERE si.tenant_id=? AND si.id=?');
        $source->execute([$actor->tenantId(),$sourceItem]); $sourceOwner=$source->fetchColumn();
        if ($sourceOwner===false || (int)$sourceOwner!==$owner) throw new \RuntimeException('Eingangselement und Quelle haben keinen gültigen gemeinsamen Besitzer.');
        if ($actor->row['role']!=='admin' && $owner!==$actor->id()) throw new \RuntimeException('Eingangselement gehört einem anderen Benutzer.');
        $inventory=(string)($item['inventoryStatus']??'');
        $ai=(string)($item['aiStatus']??'');
        if (!in_array($inventory,self::INVENTORY,true) || !in_array($ai,self::AI,true)) throw new \InvalidArgumentException('Ungültiger Eingangszustand.');
        $name=trim((string)($item['name']??'')); $mime=trim((string)($item['mime']??'')); $size=(int)($item['size']??-1);
        if ($name==='' || mb_strlen($name)>768 || str_contains($name,"\0") || $mime==='' || strlen($mime)>100 || $size<0) throw new \InvalidArgumentException('Ungültige Eingangsdaten.');
        $error=$item['errorCode']??null;
        if ($error!==null && (!is_string($error) || !preg_match('/^[a-z0-9_]{1,64}$/D',$error))) throw new \InvalidArgumentException('Ungültiger Fehlercode.');
        $stmt=$this->db->prepare("INSERT INTO inbound_items (tenant_id,source_item_id,owner_id,original_name,mime_type,size_bytes,inventory_status,ai_status,has_text_sidecar,has_json_sidecar,json_valid,error_code) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE owner_id=VALUES(owner_id),original_name=VALUES(original_name),mime_type=VALUES(mime_type),size_bytes=VALUES(size_bytes),inventory_status=VALUES(inventory_status),ai_status=IF(ai_status IN ('queued','running'),ai_status,VALUES(ai_status)),has_text_sidecar=VALUES(has_text_sidecar),has_json_sidecar=VALUES(has_json_sidecar),json_valid=VALUES(json_valid),error_code=VALUES(error_code),revision=revision+1,updated_at=UTC_TIMESTAMP()");
        $stmt->execute([$actor->tenantId(),$sourceItem,$owner,$name,$mime,$size,$inventory,$ai,(int)!empty($item['hasText']),(int)!empty($item['hasJson']),(int)!empty($item['jsonValid']),$error]);
    }
}
