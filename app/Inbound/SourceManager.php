<?php
declare(strict_types=1);
namespace O8\Inbound;

use O8\Auth\{Access,Actor};

final class SourceManager
{
    private SourceCredentialVault $vault;
    public function __construct(private \PDO $db,array $identity) { $this->vault=new SourceCredentialVault($identity); }

    public function sources(Actor $actor): array
    {
        (new Access($this->db))->tenant($actor);
        $stmt=$this->db->prepare("SELECT s.id,s.kind,s.name,s.enabled,s.config_json,s.interval_minutes,s.last_success_at,s.next_run_at,s.last_error_code,s.revision,(s.credential_id IS NOT NULL) AS secret_set,(SELECT COUNT(*) FROM source_items si WHERE si.tenant_id=s.tenant_id AND si.source_id=s.id) AS item_count FROM import_sources s WHERE s.tenant_id=? AND s.owner_id=? AND s.kind IN ('imap','webdav') ORDER BY s.kind,s.name,s.id");
        $stmt->execute([$actor->tenantId(),$actor->id()]); $rows=$stmt->fetchAll();
        foreach ($rows as &$row) { $row['config']=$this->decode((string)$row['config_json']); unset($row['config_json']); }
        return $rows;
    }

    public function save(Actor $actor,array $input): int
    {
        $kind=(string)($input['kind']??''); if (!in_array($kind,['imap','webdav'],true)) throw new \InvalidArgumentException('Unbekannte Quellenart.');
        $id=filter_var($input['id']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])?:0;
        $revision=filter_var($input['revision']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]])?:0;
        $name=$this->text((string)($input['name']??''),'Quellenname',190);
        $interval=filter_var($input['interval']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>1440]]);
        if ($interval===false || ($interval>0 && $interval<5)) throw new \InvalidArgumentException('Intervall muss 0 (nur manuell) oder 5 bis 1440 Minuten betragen.');
        $config=$kind==='imap'?$this->imap($input):$this->webdav($input);
        $secret=(string)($input['secret']??''); $enabled=!empty($input['enabled']);
        return $this->transaction($actor,function() use($actor,$kind,$id,$revision,$name,$interval,$config,$secret,$enabled,$input): int {
            $config['acceptance']=$this->acceptance($actor,$input);
            $current=null;
            if ($id) {
                $stmt=$this->db->prepare('SELECT * FROM import_sources WHERE tenant_id=? AND owner_id=? AND id=? FOR UPDATE'); $stmt->execute([$actor->tenantId(),$actor->id(),$id]); $current=$stmt->fetch();
                if (!$current || $current['kind']!==$kind) throw new \RuntimeException('Quelle nicht verfügbar.');
                if ((int)$current['revision']!==$revision) throw new \RuntimeException('Quelle wurde zwischenzeitlich geändert. Bitte neu laden.');
            }
            $credential=$current?(int)($current['credential_id']??0):0;
            if ($secret!=='') {
                $cipher=$this->vault->encrypt($secret,$actor->tenantId(),$actor->id(),$kind);
                if ($credential) { $stmt=$this->db->prepare('UPDATE source_credentials SET ciphertext=?,key_id=?,crypto_version=1 WHERE tenant_id=? AND id=?'); $stmt->execute([$cipher,$this->vault->keyId(),$actor->tenantId(),$credential]); }
                else { $stmt=$this->db->prepare('INSERT INTO source_credentials (tenant_id,ciphertext,key_id,crypto_version) VALUES (?,?,?,1)'); $stmt->execute([$actor->tenantId(),$cipher,$this->vault->keyId()]); $credential=(int)$this->db->lastInsertId(); }
            }
            if (!$credential) throw new \RuntimeException('Für eine neue Quelle ist ein Passwort oder Token erforderlich.');
            $json=json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            if ($current) {
                $stmt=$this->db->prepare('UPDATE import_sources SET name=?,enabled=?,config_json=?,credential_id=?,interval_minutes=?,next_run_at=IF(?,UTC_TIMESTAMP(),NULL),last_error_code=NULL,revision=revision+1 WHERE tenant_id=? AND owner_id=? AND id=? AND revision=?');
                $stmt->execute([$name,(int)$enabled,$json,$credential,$interval,(int)($enabled && $interval>0),$actor->tenantId(),$actor->id(),$id,$revision]);
            } else {
                $stmt=$this->db->prepare('INSERT INTO import_sources (tenant_id,owner_id,kind,name,enabled,config_json,credential_id,interval_minutes,next_run_at) VALUES (?,?,?,?,?,?,?,?,IF(?,UTC_TIMESTAMP(),NULL))');
                $stmt->execute([$actor->tenantId(),$actor->id(),$kind,$name,(int)$enabled,$json,$credential,$interval,(int)($enabled && $interval>0)]); $id=(int)$this->db->lastInsertId();
            }
            $this->audit($actor,$id,'source.saved'); return $id;
        });
    }

    public function delete(Actor $actor,int $id,int $revision): void
    {
        $this->transaction($actor,function() use($actor,$id,$revision): void {
            $stmt=$this->db->prepare("SELECT * FROM import_sources WHERE tenant_id=? AND owner_id=? AND id=? AND kind IN ('imap','webdav') FOR UPDATE"); $stmt->execute([$actor->tenantId(),$actor->id(),$id]); $source=$stmt->fetch();
            if (!$source) throw new \RuntimeException('Quelle nicht verfügbar.');
            if ((int)$source['revision']!==$revision) throw new \RuntimeException('Quelle wurde zwischenzeitlich geändert. Bitte neu laden.');
            $stmt=$this->db->prepare('SELECT (SELECT COUNT(*) FROM source_items WHERE tenant_id=? AND source_id=?)+(SELECT COUNT(*) FROM background_jobs WHERE tenant_id=? AND source_id=?)'); $stmt->execute([$actor->tenantId(),$id,$actor->tenantId(),$id]);
            if ((int)$stmt->fetchColumn()>0) throw new \RuntimeException('Eine bereits verwendete Quelle wird deaktiviert statt gelöscht.');
            $credential=(int)$source['credential_id']; $stmt=$this->db->prepare('DELETE FROM import_sources WHERE tenant_id=? AND owner_id=? AND id=?'); $stmt->execute([$actor->tenantId(),$actor->id(),$id]);
            $stmt=$this->db->prepare('DELETE FROM source_credentials WHERE tenant_id=? AND id=?'); $stmt->execute([$actor->tenantId(),$credential]); $this->audit($actor,$id,'source.deleted');
        });
    }

    /** Worker-only accessor; callers must never expose the returned secret. */
    public function connection(Actor $actor,int $id): array
    {
        (new Access($this->db))->tenant($actor);
        $stmt=$this->db->prepare("SELECT s.*,c.ciphertext,c.key_id,c.crypto_version FROM import_sources s JOIN source_credentials c ON c.tenant_id=s.tenant_id AND c.id=s.credential_id WHERE s.tenant_id=? AND s.owner_id=? AND s.id=? AND s.kind IN ('imap','webdav')"); $stmt->execute([$actor->tenantId(),$actor->id(),$id]); $row=$stmt->fetch();
        if (!$row) throw new \RuntimeException('Quelle nicht verfügbar.');
        return ['kind'=>$row['kind'],'config'=>$this->decode((string)$row['config_json']),'secret'=>$this->vault->decrypt($row['ciphertext'],$row['key_id'],(int)$row['crypto_version'],$actor->tenantId(),$actor->id(),$row['kind'])];
    }

    private function imap(array $input): array
    {
        $host=mb_strtolower(trim((string)($input['host']??''))); if (!$this->host($host)) throw new \InvalidArgumentException('Ungültiger IMAP-Server.');
        $port=filter_var($input['port']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]); if ($port===false) throw new \InvalidArgumentException('Ungültiger IMAP-Port.');
        $security=(string)($input['security']??''); if (!in_array($security,['tls','starttls'],true)) throw new \InvalidArgumentException('IMAP muss TLS oder STARTTLS verwenden.');
        return ['schema'=>1,'host'=>$host,'port'=>$port,'security'=>$security,'username'=>$this->text((string)($input['username']??''),'IMAP-Benutzername',255),'folder'=>$this->text((string)($input['folder']??''),'Postfachordner',768),'delete_after_fetch'=>!empty($input['deleteAfter']),'ai_mode'=>$this->aiMode($input)];
    }
    private function webdav(array $input): array
    {
        $url=trim((string)($input['url']??'')); $parts=parse_url($url);
        if ($parts===false || ($parts['scheme']??'')!=='https' || !$this->host(mb_strtolower((string)($parts['host']??''))) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || strlen($url)>768) throw new \InvalidArgumentException('WebDAV benötigt eine gültige HTTPS-Adresse ohne eingebettete Zugangsdaten.');
        return ['schema'=>1,'url'=>rtrim($url,'/').'/', 'username'=>$this->text((string)($input['username']??''),'WebDAV-Benutzername',255),'recursive'=>!empty($input['recursive']),'delete_after_fetch'=>!empty($input['deleteAfter']),'ai_mode'=>$this->aiMode($input)];
    }
    private function acceptance(Actor $actor,array $input): array
    {
        $mode=$input['acceptanceTagMode']??'add';
        if (!in_array($mode,['add','replace'],true)) throw new \InvalidArgumentException('Ungültige Übernahme-Tagregel.');
        $selected=[];
        foreach (['folders'=>'folders','tags'=>'tags'] as $key=>$table) {
            $values=$input['acceptance'.ucfirst($key)]??[];
            if (!is_array($values) || count($values)>100) throw new \InvalidArgumentException('Ungültige Übernahmeauswahl.');
            $selected[$key]=[];
            foreach ($values as $value) {
                if (!is_scalar($value) || !ctype_digit((string)$value) || (int)$value<1) throw new \InvalidArgumentException('Ungültige Übernahmeauswahl.');
                $s=$this->db->prepare("SELECT id FROM $table WHERE tenant_id=? AND id=?".($key==='tags'?' AND active=1':($actor->row['role']==='admin'?'':' AND owner_id=?')));
                $s->execute($key==='folders' && $actor->row['role']!=='admin'?[$actor->tenantId(),$value,$actor->id()]:[$actor->tenantId(),$value]);
                if (!$s->fetchColumn()) throw new \RuntimeException('Zielordner oder Tag ist nicht verfügbar. Bitte die Übernahmeauswahl prüfen.');
                $selected[$key][]=(int)$value;
            }
            $selected[$key]=array_values(array_unique($selected[$key]));
        }
        return ['enabled'=>!empty($input['acceptanceEnabled']),'tagMode'=>$mode,...$selected];
    }
    private function aiMode(array $input): string { $mode=(string)($input['aiMode']??'manual'); if (!in_array($mode,['off','manual','automatic'],true)) throw new \InvalidArgumentException('Ungültige KI-Einstellung.'); return $mode; }
    private function host(string $host): bool { return filter_var($host,FILTER_VALIDATE_IP)!==false || (strlen($host)<=253 && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$host)===1); }
    private function text(string $value,string $field,int $max): string { $value=trim($value); if ($value==='' || mb_strlen($value)>$max || preg_match('/[\x00-\x1f]/',$value)) throw new \InvalidArgumentException("$field fehlt oder ist ungültig."); return $value; }
    private function decode(string $json): array { try { $value=json_decode($json,true,16,JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new \RuntimeException('Quellenkonfiguration ist beschädigt.'); } return is_array($value)?$value:throw new \RuntimeException('Quellenkonfiguration ist beschädigt.'); }
    private function transaction(Actor $actor,callable $callback): mixed { $this->db->beginTransaction(); try { (new Access($this->db))->tenant($actor); $result=$callback(); $this->db->commit(); return $result; } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); if ($error instanceof \PDOException && ($error->errorInfo[1]??null)===1062) throw new \RuntimeException('Dieser Quellenname ist für diese Quellenart bereits vergeben.'); throw $error; } }
    private function audit(Actor $actor,int $id,string $action): void { $stmt=$this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id) VALUES (?,?,?,'import_source',?)"); $stmt->execute([$actor->tenantId(),$actor->id(),$action,(string)$id]); }
}
