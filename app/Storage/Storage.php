<?php
declare(strict_types=1);
namespace O8\Storage;
use O8\Auth\{Actor,Access};

final class Storage
{
    public function __construct(private \PDO $db, private string $projectRoot) {}
    private function inside(string $path,string $base): bool { return $path===$base || str_starts_with($path,$base.'/'); }
    public function base(string $path): string
    {
        $path=rtrim(trim($path),'/'); clearstatcache(true);
        if (strlen($path)>700 || $path==='' || $path[0]!=='/' || realpath($path)!==$path || !is_dir($path)
            || in_array($path,['/root','/home','/tmp','/var','/mnt','/srv','/media','/usr','/etc','/opt','/var/www','/var/www/html'],true)
            || $this->inside($this->projectRoot,$path) || $this->inside($path,$this->projectRoot) || $this->inside($path,'/var/www')
            || $this->inside($path,'/etc') || $this->inside($path,'/proc') || $this->inside($path,'/sys') || $this->inside($path,'/dev')) throw new \RuntimeException('Vorhandenen kanonischen Storage-Basispfad außerhalb des Projekts/Webroots angeben. Keine System- oder Sammelverzeichnisse.');
        $web=realpath($_SERVER['DOCUMENT_ROOT']??'');
        if ($web && $web!=='/' && $this->inside($path,$web)) throw new \RuntimeException('Dokumentablage darf nicht im Webroot liegen.');
        for ($part=$path;$part!==dirname($part);$part=dirname($part)) if (is_link($part)) throw new \RuntimeException('Storage-Pfade dürfen keine Symlinks enthalten.');
        if (!is_readable($path) || !is_writable($path) || !is_executable($path)) throw new \RuntimeException('Storage nicht lesbar, schreibbar oder zugänglich. Mount und Webserver-Rechte prüfen.');
        return $path;
    }
    private function identityNames(string $owner,string $group): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]{0,99}\$?$/D',$owner) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]{0,99}\$?$/D',$group)) throw new \RuntimeException('Gültigen Linux-Besitzer und Gruppe angeben.');
    }
    private function identities(string $owner,string $group): array
    {
        $this->identityNames($owner,$group);
        if (!function_exists('posix_getpwnam')) throw new \RuntimeException('PHP-POSIX wird für die Linux-Rechteprüfung benötigt.');
        $user=posix_getpwnam($owner); $grp=posix_getgrnam($group);
        if (!$user || !$grp) throw new \RuntimeException('Linux-Besitzer oder Gruppe existiert nicht auf diesem Server.');
        return [(int)$user['uid'],(int)$grp['gid']];
    }
    public function permissions(string $path,array $location,bool $directory=false): void
    {
        clearstatcache(true,$path); $s=lstat($path);
        if (!$s || is_link($path)) throw new \RuntimeException('Unsicheres Storage-Objekt.');
        // SMB/NAS filesystems may intentionally reject chown/chgrp/chmod.  They still
        // have to pass the same safe-path and functional read/write/rename/delete probes.
        if (!(bool)($location['enforce_file_attributes']??true)) return;
        [$uid,$gid]=$this->identities($location['linux_owner'],$location['linux_group']); $mode=$directory?0750:0640;
        if (($s['uid']!==$uid && !@chown($path,$uid)) || ($s['gid']!==$gid && !@chgrp($path,$gid)) || !@chmod($path,$mode)) throw new \RuntimeException('Besitzer, Gruppe oder Dateirechte können nicht gesetzt werden. Mount-Optionen/Webserver-Rechte prüfen.');
        clearstatcache(true,$path); $s=lstat($path);
        if (!$s || $s['uid']!==$uid || $s['gid']!==$gid || ($s['mode']&0777)!==$mode) throw new \RuntimeException('Storage übernimmt die benötigten Linux-Rechte nicht.');
    }
    public function locations(Actor $actor): array
    {
        (new Access($this->db))->operator($actor);
        return $this->db->query("SELECT t.id,t.name,t.public_id,t.active,s.root_path,s.linux_owner,s.linux_group,s.enforce_file_attributes,s.identity_json FROM tenants t LEFT JOIN storage_locations s ON s.tenant_id=t.id AND s.storage_key='main' ORDER BY t.name,t.id")->fetchAll();
    }
    public function location(Actor $actor): ?array
    {
        (new Access($this->db))->tenant($actor);
        $s=$this->db->prepare("SELECT s.*,t.public_id FROM storage_locations s JOIN tenants t ON t.id=s.tenant_id WHERE s.tenant_id=? AND s.storage_key='main' AND s.active=1"); $s->execute([$actor->tenantId()]);
        return $s->fetch()?:null;
    }
    public function paths(array $location): array
    {
        $base=$this->base($location['root_path']); $uuid=$location['public_id'];
        if (!preg_match('/^[a-f0-9-]{36}$/D',$uuid)) throw new \RuntimeException('Ungültige Storage-Zuordnung.');
        $target=$base.'/'.$uuid; $saved=json_decode($location['identity_json']??'null',true);
        clearstatcache(true);
        if (!$saved || is_link($target) || realpath($target)!==$target || !is_dir($target)) throw new \RuntimeException('Storage zuerst durch den Betreiber einrichten und prüfen lassen.');
        $b=stat($base); $t=stat($target); $marker=$target.'/.o8-storage';
        if (!$b || !$t || $b['dev']!=$saved['base_dev'] || $b['ino']!=$saved['base_ino'] || $t['dev']!=$saved['tenant_dev'] || $t['ino']!=$saved['tenant_ino']
            || is_link($marker) || !is_file($marker) || !hash_equals($saved['marker'],(string)@file_get_contents($marker))) throw new \RuntimeException('Storage-Identität geändert oder Mount fehlt. Zugriff gesperrt; Betreiber muss die Ablage prüfen.');
        return [$base,$target];
    }
    public function configure(Actor $actor,int $tenant,string $path,string $owner,string $group,bool $enforceFileAttributes=true): void
    {
        $actor->requireOperator(); $this->identityNames($owner,$group); if ($enforceFileAttributes) $this->identities($owner,$group);
        $lock='o8.storage.'.substr(hash('sha256',(string)$this->db->query('SELECT DATABASE()')->fetchColumn()),0,40);
        $s=$this->db->prepare('SELECT GET_LOCK(?,0)'); $s->execute([$lock]);
        if ((int)$s->fetchColumn()!==1) throw new \RuntimeException('Storage wird gerade geändert. Bitte erneut versuchen.');
        $probe=null; $created=null; $markerCreated=false; $marker=null;
        try {
            $this->db->beginTransaction();
            $s=$this->db->prepare('SELECT * FROM tenants WHERE id=? AND active=1 FOR UPDATE'); $s->execute([$tenant]); $t=$s->fetch();
            (new Access($this->db))->operator($actor);
            if (!$t) throw new \RuntimeException('Aktiver Mandant nicht verfügbar.');
            $s=$this->db->prepare('SELECT 1 FROM platform_settings WHERE setting_key=?'); $s->execute(['tenant.delete.'.$tenant]); if ($s->fetchColumn()) throw new \RuntimeException('Mandant wird gelöscht.');
            $base=$this->base($path); $target=$base.'/'.$t['public_id'];
            $s=$this->db->prepare("SELECT * FROM storage_locations WHERE tenant_id=? AND storage_key='main'"); $s->execute([$tenant]); $old=$s->fetch();
            if ($old) {
                if ($old['root_path']!==$base || $old['linux_owner']!==$owner || $old['linux_group']!==$group) throw new \RuntimeException('Bereits zugewiesene Ablage ist fest gebunden. Ein Storage-Umzug benötigt einen gesonderten geprüften Ablauf.');
                $this->paths($old+['public_id'=>$t['public_id']]);
            } else {
                foreach ($this->db->query('SELECT s.root_path,t.public_id FROM storage_locations s JOIN tenants t ON t.id=s.tenant_id')->fetchAll() as $other) {
                    $otherTarget=rtrim($other['root_path'],'/').'/'.$other['public_id'];
                    if ($this->inside($target,$otherTarget) || $this->inside($otherTarget,$target)) throw new \RuntimeException('Ablage überschneidet sich mit einer bestehenden Mandantenablage.');
                }
                if (file_exists($target) || is_link($target)) throw new \RuntimeException('Mandantenverzeichnis existiert bereits ohne geprüfte Zuordnung. Bestehende Dateien werden nicht übernommen oder verändert.');
                if (!@mkdir($target,0750)) throw new \RuntimeException('Mandantenverzeichnis kann nicht angelegt werden.');
                $created=$target;
            }
            $location=['linux_owner'=>$owner,'linux_group'=>$group,'enforce_file_attributes'=>$enforceFileAttributes];
            if ($created) $this->permissions($target,$location,true);
            elseif ($enforceFileAttributes) {
                // Enabling strict mode on an existing SMB/NAS assignment must verify
                // the tenant root and marker before the new policy is persisted.
                $this->permissions($target,$location,true);
                $this->permissions($target.'/.o8-storage',$location);
            }
            $probe=$target.'/.probe-'.bin2hex(random_bytes(16)); $f=@fopen($probe,'x+b');
            if (!$f) throw new \RuntimeException('Storage-Schreibtest fehlgeschlagen.');
            try {
                $payload=random_bytes(64);
                if (fwrite($f,$payload)!==64 || !fflush($f) || !fsync($f)) throw new \RuntimeException('Storage-Schreibtest konnte nicht sicher abgeschlossen werden.');
                $this->permissions($probe,$location); rewind($f);
                if (fread($f,64)!==$payload) throw new \RuntimeException('Storage-Lesetest fehlgeschlagen.');
            } finally { fclose($f); }
            if (!@rename($probe,$probe.'.renamed')) throw new \RuntimeException('Storage-Umbenennen nicht möglich.');
            $probe.='.renamed';
            if (!@unlink($probe)) throw new \RuntimeException('Storage-Löschtest fehlgeschlagen.');
            $probe=null;
            $marker=$target.'/.o8-storage'; $token=$old?json_decode($old['identity_json'],true)['marker']:bin2hex(random_bytes(32));
            if (!$old) {
                $f=@fopen($marker,'x');
                if (!$f) throw new \RuntimeException('Storage-Markierung konnte nicht angelegt werden.');
                $markerCreated=true;
                try { if (fwrite($f,$token)!==strlen($token) || !fflush($f) || !fsync($f)) throw new \RuntimeException('Storage-Markierung unvollständig.'); } finally { fclose($f); }
                $this->permissions($marker,$location);
            }
            $b=stat($base); $d=stat($target); $identity=['base_dev'=>$b['dev'],'base_ino'=>$b['ino'],'tenant_dev'=>$d['dev'],'tenant_ino'=>$d['ino'],'marker'=>$token];
            $s=$this->db->prepare("INSERT INTO storage_locations (tenant_id,storage_key,name,root_path,linux_owner,linux_group,enforce_file_attributes,identity_json) VALUES (?,'main','Dokumentablage',?,?,?,?,?) ON DUPLICATE KEY UPDATE enforce_file_attributes=VALUES(enforce_file_attributes),identity_json=VALUES(identity_json),updated_at=UTC_TIMESTAMP()");
            $s->execute([$tenant,$base,$owner,$group,$enforceFileAttributes?1:0,json_encode($identity,JSON_THROW_ON_ERROR)]);
            $s=$this->db->prepare("INSERT INTO platform_audit_events (operator_id,tenant_id,action) VALUES (?,?,'storage.checked')"); $s->execute([$actor->id(),$tenant]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($probe && is_file($probe)) @unlink($probe);
            // Never remove a configured target after an ambiguous commit.
            if ($created && $this->db->getAttribute(\PDO::ATTR_CONNECTION_STATUS)) {
                $s=$this->db->prepare("SELECT 1 FROM storage_locations WHERE tenant_id=? AND storage_key='main'"); $s->execute([$tenant]);
                if (!$s->fetchColumn()) {
                    if ($markerCreated && $marker && is_file($marker)) @unlink($marker);
                    @rmdir($created);
                }
            }
            throw $e;
        } finally { $s=$this->db->prepare('SELECT RELEASE_LOCK(?)'); $s->execute([$lock]); }
    }

    /** Rebind an already moved tenant directory; never create a new identity or move files here. */
    public function relocate(Actor $actor,int $tenant,string $path): void
    {
        $actor->requireOperator();
        $lock='o8.storage.'.substr(hash('sha256',(string)$this->db->query('SELECT DATABASE()')->fetchColumn()),0,40);
        $s=$this->db->prepare('SELECT GET_LOCK(?,0)'); $s->execute([$lock]);
        if ((int)$s->fetchColumn()!==1) throw new \RuntimeException('Storage wird gerade geändert. Bitte erneut versuchen.');
        $probe=null;
        try {
            $this->db->beginTransaction();
            (new Access($this->db))->operator($actor);
            $s=$this->db->prepare('SELECT * FROM tenants WHERE id=? AND active=1 FOR UPDATE'); $s->execute([$tenant]); $t=$s->fetch();
            if (!$t) throw new \RuntimeException('Aktiver Mandant nicht verfügbar.');
            $s=$this->db->prepare('SELECT 1 FROM platform_settings WHERE setting_key=?'); $s->execute(['tenant.delete.'.$tenant]);
            if ($s->fetchColumn()) throw new \RuntimeException('Mandant wird gelöscht.');
            $s=$this->db->prepare("SELECT * FROM storage_locations WHERE tenant_id=? AND storage_key='main' FOR UPDATE"); $s->execute([$tenant]); $old=$s->fetch();
            if (!$old || !$old['identity_json']) throw new \RuntimeException('Keine geprüfte bisherige Ablage vorhanden. Zuerst regulär einrichten.');
            $saved=json_decode($old['identity_json'],true);
            if (!is_array($saved) || !is_string($saved['marker']??null) || !preg_match('/^[a-f0-9]{64}$/D',$saved['marker'])) throw new \RuntimeException('Gespeicherte Storage-Identität ist ungültig.');
            $base=$this->base($path); $target=$base.'/'.$t['public_id'];
            foreach ($this->db->query("SELECT s.root_path,t.public_id FROM storage_locations s JOIN tenants t ON t.id=s.tenant_id WHERE s.storage_key='main' AND s.tenant_id<>".(int)$tenant)->fetchAll() as $other) {
                $otherTarget=rtrim($other['root_path'],'/').'/'.$other['public_id'];
                if ($this->inside($target,$otherTarget) || $this->inside($otherTarget,$target)) throw new \RuntimeException('Ablage überschneidet sich mit einer bestehenden Mandantenablage.');
            }
            clearstatcache(true);
            $marker=$target.'/.o8-storage';
            if (is_link($target) || realpath($target)!==$target || !is_dir($target) || !is_readable($target) || !is_writable($target) || !is_executable($target)
                || is_link($marker) || !is_file($marker) || !hash_equals($saved['marker'],(string)@file_get_contents($marker)))
                throw new \RuntimeException('Ziel enthält nicht das geprüfte Mandantenverzeichnis samt unveränderter .o8-storage-Markierung. Keine Pfadänderung vorgenommen.');
            if ((bool)($old['enforce_file_attributes']??true)) {
                [$uid,$gid]=$this->identities($old['linux_owner'],$old['linux_group']);
                $dir=lstat($target); $mark=lstat($marker);
                if (!$dir || !$mark || $dir['uid']!==$uid || $dir['gid']!==$gid || $mark['uid']!==$uid || $mark['gid']!==$gid)
                    throw new \RuntimeException('Besitzer oder Gruppe am Ziel stimmen nicht mit der geprüften Ablage überein.');
            }
            if (is_link($target.'/inbound')) throw new \RuntimeException('Eingangsverzeichnis am Ziel darf kein Symlink sein.');
            foreach (['document_files','inbound_files'] as $table) {
                $s=$this->db->prepare("SELECT relative_path,size_bytes FROM $table WHERE tenant_id=? AND storage_key='main'"); $s->execute([$tenant]);
                while ($file=$s->fetch()) {
                    $relative=$file['relative_path'];
                    if (!preg_match('#^(?:inbound/)?[a-f0-9]{48}\.(?:pdf|jpg|png|odt|json|txt)$#D',$relative)
                        || is_link($target.'/'.$relative) || !is_file($target.'/'.$relative)
                        || ($file['size_bytes']!==null && filesize($target.'/'.$relative)!==(int)$file['size_bytes']))
                        throw new \RuntimeException('Mindestens eine verwaltete Datei fehlt am Ziel oder hat eine andere Größe. Keine Pfadänderung vorgenommen.');
                }
            }
            $probe=$target.'/.probe-'.bin2hex(random_bytes(16)); $f=@fopen($probe,'x+b');
            if (!$f) throw new \RuntimeException('Schreibtest am neuen Pfad fehlgeschlagen.');
            try {
                $payload=random_bytes(64);
                if (fwrite($f,$payload)!==64 || !fflush($f) || !fsync($f)) throw new \RuntimeException('Schreibtest am neuen Pfad fehlgeschlagen.');
                $this->permissions($probe,$old); rewind($f);
                if (fread($f,64)!==$payload) throw new \RuntimeException('Lesetest am neuen Pfad fehlgeschlagen.');
            } finally { fclose($f); }
            if (!@rename($probe,$probe.'.renamed')) throw new \RuntimeException('Umbenennen am neuen Pfad fehlgeschlagen.');
            $probe.='.renamed';
            if (!@unlink($probe)) throw new \RuntimeException('Löschtest am neuen Pfad fehlgeschlagen.');
            $probe=null;
            $b=stat($base); $d=stat($target);
            $identity=['base_dev'=>$b['dev'],'base_ino'=>$b['ino'],'tenant_dev'=>$d['dev'],'tenant_ino'=>$d['ino'],'marker'=>$saved['marker']];
            $s=$this->db->prepare("UPDATE storage_locations SET root_path=?,identity_json=?,updated_at=UTC_TIMESTAMP() WHERE tenant_id=? AND storage_key='main'");
            $s->execute([$base,json_encode($identity,JSON_THROW_ON_ERROR),$tenant]);
            $s=$this->db->prepare("INSERT INTO platform_audit_events (operator_id,tenant_id,action) VALUES (?,?,'storage.relocated')"); $s->execute([$actor->id(),$tenant]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($probe && is_file($probe)) @unlink($probe);
            throw $e;
        } finally { $s=$this->db->prepare('SELECT RELEASE_LOCK(?)'); $s->execute([$lock]); }
    }
}
