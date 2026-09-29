<?php
declare(strict_types=1);
namespace O8\Inbound;

use O8\Auth\{Access,Actor};
use O8\Documents\Documents;
use O8\Storage\Storage;

/** Validated file uploads enter the same pending workbench as fetched source files. */
final class InboundUploads
{
    public function __construct(private \PDO $db,private string $root) {}

    public function upload(Actor $actor,array $file): int
    {
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null) || !is_uploaded_file($file['tmp_name'])) throw new \RuntimeException('Upload nicht vollständig. Dateigröße und PHP-Uploadlimits prüfen.');
        $stored=$this->stage($actor,$file['tmp_name'],(string)($file['name']??'Dokument'));
        try {
            $this->db->beginTransaction();
            (new Access($this->db))->tenant($actor);
            $id=$this->createItem($actor,$actor->id(),$stored);
            $this->db->commit();
            return $id;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if (is_file($stored['path'])) @unlink($stored['path']);
            throw $error;
        }
    }

    /** Return an untouched direct-upload DMS draft to the pending inbox, preserving its bytes. */
    public function restoreDraft(Actor $actor,int $id,int $revision): int
    {
        $staged=null; $oldPath=null; $committed=false;
        $this->db->beginTransaction();
        try {
            (new Access($this->db))->tenant($actor);
            $stmt=$this->db->prepare('SELECT * FROM documents WHERE tenant_id=? AND id=? FOR UPDATE');
            $stmt->execute([$actor->tenantId(),$id]); $doc=$stmt->fetch();
            if (!$doc || ($actor->row['role']!=='admin' && (int)$doc['owner_id']!==$actor->id())) throw new \RuntimeException('Dokument nicht verfügbar.');
            if ((int)$doc['revision']!==$revision || (int)$doc['revision']!==1 || !(bool)$doc['in_inbox'] || $doc['deleted_at']!==null || $doc['source_type']!=='upload'
                || $doc['sender']!==null || $doc['reference']!==null || $doc['memo']!==null || $doc['ai_data']!==null || $doc['search_text']!==null
                || $doc['document_type']!=='document' || (bool)$doc['expired'] || !(bool)$doc['searchable']) {
                throw new \RuntimeException('Nur ein unbearbeiteter Datei-Upload im Eingang kann zurückgestellt werden. Bearbeitete oder bereits abgelegte Dokumente bleiben unverändert.');
            }
            foreach ([
                ['SELECT COUNT(*) FROM document_files WHERE tenant_id=? AND document_id=? AND role=\'original\'',[ $actor->tenantId(),$id ],1],
                ['SELECT COUNT(*) FROM document_files WHERE tenant_id=? AND document_id=?',[ $actor->tenantId(),$id ],1],
                ['SELECT COUNT(*) FROM document_tags WHERE tenant_id=? AND document_id=?',[ $actor->tenantId(),$id ],0],
                ['SELECT COUNT(*) FROM folder_documents WHERE tenant_id=? AND document_id=?',[ $actor->tenantId(),$id ],0],
                ['SELECT COUNT(*) FROM document_invoices WHERE tenant_id=? AND document_id=?',[ $actor->tenantId(),$id ],0],
                ['SELECT COUNT(*) FROM source_items WHERE tenant_id=? AND document_id=?',[ $actor->tenantId(),$id ],0],
            ] as [$sql,$params,$expected]) { $check=$this->db->prepare($sql); $check->execute($params); if ((int)$check->fetchColumn()!==$expected) throw new \RuntimeException('Dieses Dokument ist nicht mehr unverändert. Es wurde nicht zurückgestellt.'); }
            $file=$this->db->prepare("SELECT * FROM document_files WHERE tenant_id=? AND document_id=? AND role='original'"); $file->execute([$actor->tenantId(),$id]); $file=$file->fetch();
            if (!$file || $file['storage_key']!=='main' || !preg_match('#^[a-f0-9]{48}\.(?:pdf|jpg|png|odt|txt)$#D',(string)$file['relative_path'])) throw new \RuntimeException('Dateipfad des Upload-Entwurfs ist nicht für eine sichere Rückstellung geeignet.');
            $storage=new Storage($this->db,$this->root); $location=$storage->location($actor); if (!$location) throw new \RuntimeException('Geprüfte Ablage fehlt.'); [, $target]=$storage->paths($location);
            $oldPath=$target.'/'.$file['relative_path'];
            if (is_link($oldPath) || !is_file($oldPath) || !is_readable($oldPath) || !hash_equals((string)$file['sha256'],(string)hash_file('sha256',$oldPath)) || filesize($oldPath)!==(int)$file['size_bytes']) throw new \RuntimeException('Originaldatei fehlt oder wurde verändert. Das Dokument bleibt unverändert.');
            $staged=$this->stage($actor,$oldPath,(string)$file['original_name']);
            if (!hash_equals((string)$file['sha256'],$staged['sha256'])) throw new \RuntimeException('Dateiprüfsumme stimmt beim Zurückstellen nicht überein.');
            $inboundId=$this->createItem($actor,(int)$doc['owner_id'],$staged,(string)$doc['created_at'],$id);
            $audit=$this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id,details_json) VALUES (?,?, 'document.returned_to_inbox','document',?,?)");
            $audit->execute([$actor->tenantId(),$actor->id(),(string)$id,json_encode(['inbound_id'=>$inboundId],JSON_THROW_ON_ERROR)]);
            $delete=$this->db->prepare("DELETE FROM documents WHERE tenant_id=? AND id=? AND revision=? AND in_inbox=1 AND source_type='upload'"); $delete->execute([$actor->tenantId(),$id,$revision]);
            if ($delete->rowCount()!==1) throw new \RuntimeException('Dokument wurde zwischenzeitlich geändert. Es wurde nicht zurückgestellt.');
            $this->db->commit(); $committed=true;
            // Database now references the verified inbound copy. A failed unlink only leaves an orphaned extra copy.
            if (is_file($oldPath)) @unlink($oldPath);
            return $inboundId;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if (!$committed && $staged && is_file($staged['path'])) @unlink($staged['path']);
            throw $error;
        }
    }

    private function stage(Actor $actor,string $source,string $name): array
    {
        if (is_link($source) || !is_file($source) || !is_readable($source)) throw new \RuntimeException('Quelldatei nicht lesbar.');
        $size=filesize($source); if (!$size || $size>Documents::MAX_BYTES) throw new \RuntimeException('Datei muss zwischen 1 Byte und 1 GiB groß sein.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($source); $extension=Documents::MIME_EXTENSIONS[$mime]??null;
        if (!$extension) throw new \RuntimeException('Zugelassen sind PDF, JPEG, PNG, ODT und TXT.');
        if ($extension==='pdf' && file_get_contents($source,false,null,0,5)!=='%PDF-') throw new \RuntimeException('Ungültiger PDF-Dateianfang.');
        if (in_array($extension,['jpg','png'],true)) { $image=@getimagesize($source); if (!$image || $image[0]*$image[1]>40000000) throw new \RuntimeException('Bild ungültig oder größer als 40 Megapixel.'); }
        if ($extension==='odt' && file_get_contents($source,false,null,0,4)!=="PK\x03\x04") throw new \RuntimeException('Ungültiger ODT-Dateianfang.');
        if ($extension==='txt' && !Documents::isPlainTextFile($source)) throw new \RuntimeException('TXT-Datei enthält Binärdaten.');
        $name=mb_strcut(preg_replace('/[\x00-\x1f\x7f]/u','',basename(str_replace('\\','/',$name)))??'',0,768,'UTF-8');
        if ($name==='') $name='Dokument.'.$extension;
        $storage=new Storage($this->db,$this->root); $location=$storage->location($actor); if (!$location) throw new \RuntimeException('Noch keine geprüfte Ablage zugewiesen. Bitte den Betreiber kontaktieren.');
        [, $target]=$storage->paths($location); $directory=$target.'/inbound';
        if (!is_dir($directory) && !@mkdir($directory,0750)) throw new \RuntimeException('Eingangsablage kann nicht angelegt werden.');
        $storage->permissions($directory,$location,true);
        $relative='inbound/'.bin2hex(random_bytes(24)).'.'.$extension; $path=$target.'/'.$relative; $part=$path.'.part';
        $in=@fopen($source,'rb'); $out=@fopen($part,'x+b');
        if (!$in || !$out) { if ($in) fclose($in); if ($out) fclose($out); throw new \RuntimeException('Datei kann nicht sicher in die Eingangsablage geschrieben werden.'); }
        try { $copied=stream_copy_to_stream($in,$out,Documents::MAX_BYTES+1); if ($copied!==$size || !fflush($out) || !fsync($out)) throw new \RuntimeException('Dateikopie unvollständig.'); $storage->permissions($part,$location); }
        catch (\Throwable $error) { fclose($in); fclose($out); if (is_file($part)) @unlink($part); throw $error; }
        fclose($in); fclose($out);
        $hash=hash_file('sha256',$part); $sourceHash=hash_file('sha256',$source);
        if (!$hash || !$sourceHash || !hash_equals($sourceHash,$hash) || filesize($part)!==$size) { @unlink($part); throw new \RuntimeException('Prüfsumme der Dateikopie stimmt nicht.'); }
        if (!@rename($part,$path)) { @unlink($part); throw new \RuntimeException('Eingangsdatei konnte nicht abgeschlossen werden.'); }
        return ['path'=>$path,'relative'=>$relative,'name'=>$name,'mime'=>$mime,'size'=>(int)$size,'sha256'=>$hash];
    }

    private function createItem(Actor $actor,int $owner,array $file,?string $discoveredAt=null,?int $excludeDocument=null): int
    {
        $user=$this->db->prepare('SELECT 1 FROM users WHERE tenant_id=? AND id=? AND active=1'); $user->execute([$actor->tenantId(),$owner]); if (!$user->fetchColumn()) throw new \RuntimeException('Besitzer des Eingangselements ist nicht verfügbar.');
        $source=$this->db->prepare("SELECT id FROM import_sources WHERE tenant_id=? AND owner_id=? AND kind='upload' AND name='Datei-Upload'"); $source->execute([$actor->tenantId(),$owner]); $sourceId=(int)$source->fetchColumn();
        if (!$sourceId) { $source=$this->db->prepare("INSERT INTO import_sources (tenant_id,owner_id,kind,name,enabled,config_json,interval_minutes) VALUES (?,?,'upload','Datei-Upload',0,'{\"origin\":\"file_upload\"}',0)"); $source->execute([$actor->tenantId(),$owner]); $sourceId=(int)$this->db->lastInsertId(); }
        $duplicateSql="SELECT 1 FROM document_files WHERE tenant_id=? AND role='original' AND sha256=?".($excludeDocument===null?'':' AND document_id<>?')." UNION SELECT 1 FROM source_items WHERE tenant_id=? AND sha256=?".($excludeDocument===null?'':' AND (document_id IS NULL OR document_id<>?)')." LIMIT 1";
        $params=[$actor->tenantId(),$file['sha256']]; if ($excludeDocument!==null) $params[]=$excludeDocument; array_push($params,$actor->tenantId(),$file['sha256']); if ($excludeDocument!==null) $params[]=$excludeDocument;
        $check=$this->db->prepare($duplicateSql); $check->execute($params); $inventory=$check->fetchColumn()?'duplicate':'pending';
        $remote=hash('sha256',random_bytes(48)); $item=$this->db->prepare("INSERT INTO source_items (tenant_id,source_id,remote_key_hash,status,sha256) VALUES (?,?,?,'downloaded',?)"); $item->execute([$actor->tenantId(),$sourceId,$remote,$file['sha256']]); $sourceItem=(int)$this->db->lastInsertId();
        $insert=$discoveredAt===null
            ? $this->db->prepare("INSERT INTO inbound_items (tenant_id,source_item_id,owner_id,original_name,mime_type,size_bytes,inventory_status) VALUES (?,?,?,?,?,?,?)")
            : $this->db->prepare("INSERT INTO inbound_items (tenant_id,source_item_id,owner_id,original_name,mime_type,size_bytes,inventory_status,discovered_at) VALUES (?,?,?,?,?,?,?,?)");
        $params=[$actor->tenantId(),$sourceItem,$owner,$file['name'],$file['mime'],$file['size'],$inventory]; if ($discoveredAt!==null) $params[]=$discoveredAt; $insert->execute($params); $id=(int)$this->db->lastInsertId();
        $stored=$this->db->prepare("INSERT INTO inbound_files (tenant_id,inbound_item_id,role,storage_key,relative_path,original_name,mime_type,sha256,size_bytes) VALUES (?,?,'original','main',?,?,?,?,?)");
        $stored->execute([$actor->tenantId(),$id,$file['relative'],$file['name'],$file['mime'],$file['sha256'],$file['size']]);
        $audit=$this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id,details_json) VALUES (?,?, 'inbound.uploaded','inbound_item',?,?)");
        $audit->execute([$actor->tenantId(),$actor->id(),(string)$id,json_encode(['source'=>'upload','inventory_status'=>$inventory],JSON_THROW_ON_ERROR)]);
        return $id;
    }
}
