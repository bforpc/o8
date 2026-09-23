<?php
declare(strict_types=1);
namespace O8\Admin;

use O8\Auth\Actor;

/** Irreversible, resumable tenant purge. Never disable foreign-key checks. */
final class TenantDeletion
{
    private const TABLES = [
        'tenant_invitations','source_fetch_items','inbound_ai_job_items','background_jobs','inbound_files','inbound_ai_runs','inbound_items','source_items','import_sources','source_credentials','ai_configurations',
        'invoice_items','invoice_tax_totals','accounting_entries','document_invoices',
        'document_tags','folder_documents','document_files','documents','folder_parents','folders',
        'user_settings','settings','audit_events','migration_map','tags','accounting_accounts',
        'accounting_vat_rates','tenant_licenses','role_permissions','users','roles',
        'storage_locations','platform_audit_events',
    ];
    public function __construct(private \PDO $db, private string $projectRoot) {}
    public static function key(int $id): string { return 'tenant.delete.'.$id; }

    public function job(Actor $actor, int $id): ?array
    {
        $actor->requireOperator();
        $s=$this->db->prepare('SELECT value_json FROM platform_settings WHERE setting_key=?'); $s->execute([self::key($id)]);
        $json=$s->fetchColumn(); return $json===false?null:json_decode($json,true,32,JSON_THROW_ON_ERROR);
    }
    public function preview(Actor $actor, int $id): array
    {
        $actor->requireOperator();
        $s=$this->db->prepare('SELECT id,public_id,name FROM tenants WHERE id=?'); $s->execute([$id]); $tenant=$s->fetch();
        if (!$tenant) throw new \RuntimeException('Mandant nicht verfügbar.');
        foreach (['documents','users','folders'] as $table) {
            $s=$this->db->prepare("SELECT COUNT(*) FROM $table WHERE tenant_id=?"); $s->execute([$id]); $tenant[$table]=(int)$s->fetchColumn();
        }
        return $tenant;
    }
    private function transaction(Actor $actor, int $id, callable $call): mixed
    {
        $actor->requireOperator(); $this->db->beginTransaction();
        try {
            $s=$this->db->prepare('SELECT * FROM tenants WHERE id=? FOR UPDATE'); $s->execute([$id]); $tenant=$s->fetch();
            if (!$tenant) throw new \RuntimeException('Mandant nicht verfügbar oder bereits gelöscht.');
            $s=$this->db->prepare('SELECT * FROM platform_operators WHERE id=? FOR UPDATE'); $s->execute([$actor->id()]); $operator=$s->fetch();
            if (!$operator || !$operator['active'] || (int)$operator['auth_version']!==(int)$actor->row['auth_version']) throw new \RuntimeException('Berechtigung geändert. Bitte neu anmelden.');
            (new Actor('operator',$operator))->requireOperator();
            $result=$call($tenant); $this->db->commit(); return $result;
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    private function save(int $id, Actor $actor, array $job): void
    {
        $s=$this->db->prepare('INSERT INTO platform_settings (setting_key,value_json,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_by=VALUES(updated_by)');
        $s->execute([self::key($id),json_encode($job,JSON_THROW_ON_ERROR),$actor->id()]);
    }
    private function checkSchema(): void
    {
        $tables=$this->db->query("SELECT table_name FROM information_schema.columns WHERE table_schema=DATABASE() AND column_name='tenant_id'")->fetchAll(\PDO::FETCH_COLUMN);
        if (array_diff($tables,self::TABLES)) throw new \RuntimeException('Das Datenbankschema enthält zusätzliche Mandantendaten. Löschplan zuerst aktualisieren.');
    }
    public function start(Actor $actor, int $id, string $uuid, string $name): array
    {
        return $this->transaction($actor,$id,function(array $tenant) use($actor,$id,$uuid,$name): array {
            if (!hash_equals($tenant['public_id'],$uuid) || !hash_equals($tenant['name'],$name)) throw new \RuntimeException('Mandant geändert oder Name falsch. Bitte beide Bestätigungen erneut durchführen.');
            if ($job=$this->job($actor,$id)) return $job;
            $this->checkSchema();
            $roots=$this->storagePlan($id,$uuid);
            $job=['uuid'=>$uuid,'phase'=>'files','root'=>0,'roots'=>$roots,'table'=>0,'files_removed'=>0,'rows_removed'=>0];
            $s=$this->db->prepare('UPDATE tenants SET active=0 WHERE id=?'); $s->execute([$id]);
            // This durable marker also prevents reactivation and administrative writes.
            $this->save($id,$actor,$job); return $job;
        });
    }
    private function storagePlan(int $id, string $uuid): array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/D',$uuid)) throw new \RuntimeException('Ungültige Mandantenkennung.');
        $s=$this->db->prepare("SELECT COUNT(*) FROM document_files WHERE tenant_id=? AND (relative_path='' OR LEFT(relative_path,1)='/' OR LOCATE(CHAR(92),relative_path)>0 OR LOCATE(':',relative_path)>0 OR LOCATE(CHAR(0),relative_path)>0 OR LOCATE('/../',CONCAT('/',relative_path,'/'))>0 OR LOCATE('/./',CONCAT('/',relative_path,'/'))>0)");
        $s->execute([$id]);
        if ($s->fetchColumn()) throw new \RuntimeException('Unsichere Dateipfade vorhanden. Storage zuerst prüfen; nichts gelöscht.');
        $s=$this->db->prepare('SELECT root_path,storage_key FROM storage_locations WHERE tenant_id=?'); $s->execute([$id]); $locations=$s->fetchAll(); $roots=[];
        foreach ($locations as $location) {
            $base=$this->base($location['root_path']); $target=$base.'/'.$uuid;
            if (is_link($target) || (file_exists($target) && !is_dir($target))) throw new \RuntimeException('Mandantenablage ist kein sicheres Verzeichnis.');
            $s=$this->db->prepare('SELECT COUNT(*) FROM document_files WHERE tenant_id=? AND storage_key=?'); $s->execute([$id,$location['storage_key']]);
            if (!is_dir($target) && $s->fetchColumn()) throw new \RuntimeException('Mandanten-Dateiablage fehlt. Mount und Pfad prüfen; nichts gelöscht.');
            $stat=stat($base); $roots[$target]=['base'=>$base,'device'=>$stat['dev'],'inode'=>$stat['ino']];
        }
        // Prevent accidentally nested tenant roots or aliases across storage assignments.
        if ($roots) {
            $targets=array_keys($roots);
            foreach ($targets as $index=>$target) foreach (array_slice($targets,$index+1) as $otherTarget) {
                if ($this->within($target,$otherTarget) || $this->within($otherTarget,$target)) throw new \RuntimeException('Storage-Ablagen dieses Mandanten überschneiden sich. Zuordnung zuerst prüfen.');
            }
            $s=$this->db->prepare('SELECT s.root_path,t.public_id FROM storage_locations s JOIN tenants t ON t.id=s.tenant_id WHERE s.tenant_id<>?'); $s->execute([$id]);
            foreach ($s->fetchAll() as $other) {
                $otherTarget=$this->base($other['root_path']).'/'.$other['public_id'];
                foreach (array_keys($roots) as $target) if ($this->within($otherTarget,$target) || $this->within($target,$otherTarget)) throw new \RuntimeException('Storage überschneidet sich mit einem anderen Mandanten.');
            }
        }
        return array_values($roots);
    }
    private function within(string $path, string $root): bool { return $path===$root || str_starts_with($path,$root.'/'); }
    private function base(string $path): string
    {
        $path=rtrim($path,'/'); $real=realpath($path);
        if ($path==='' || $path[0]!=='/' || $real!==$path || !is_dir($path)
            || in_array($path,['/root','/home','/tmp','/var','/mnt','/var/www','/var/www/html',rtrim($this->projectRoot,'/')],true)) throw new \RuntimeException('Storage-Basispfad ist nicht eindeutig oder nicht verfügbar.');
        if (!is_readable($path) || !is_writable($path) || !is_executable($path)) throw new \RuntimeException('Storage-Basis ist nicht lesbar, schreibbar oder zugänglich.');
        // No symlinks anywhere in the storage ancestry, including equivalent aliases.
        for ($part=$path; $part!==dirname($part); $part=dirname($part)) if (is_link($part)) throw new \RuntimeException('Storage-Symlinks sind für eine Löschung nicht erlaubt.');
        if ($this->within($this->projectRoot,$path) || ($this->within($path,$this->projectRoot) && !$this->within($path,$this->projectRoot.'/storage'))) throw new \RuntimeException('Projektdateien dürfen nicht als Dokumentablage gelöscht werden.');
        return $real;
    }
    /** Bounded traversal; never follow symlinks or descend into nested mounts. */
    private function removeEntries(string $path, int $device, int &$remaining, int &$removed, float $deadline): bool
    {
        clearstatcache(true,$path);
        if (!file_exists($path) && !is_link($path)) return true;
        if ($remaining<=0 || microtime(true)>$deadline) return false;
        if (is_link($path) || !is_dir($path)) {
            if (!@unlink($path)) throw new \RuntimeException('Datei konnte nicht gelöscht werden. Rechte prüfen und Löschung fortsetzen.');
        } else {
            $stat=lstat($path);
            if (!$stat || $stat['dev']!==$device) throw new \RuntimeException('Unerwarteter Mount innerhalb der Mandantenablage.');
            try { $entries=new \FilesystemIterator($path,\FilesystemIterator::SKIP_DOTS); }
            catch (\UnexpectedValueException) { throw new \RuntimeException('Mandantenverzeichnis nicht lesbar. Rechte prüfen.'); }
            foreach ($entries as $entry) if (!$this->removeEntries($entry->getPathname(),$device,$remaining,$removed,$deadline)) return false;
            if ($remaining<=0 || microtime(true)>$deadline) return false;
            if (!@rmdir($path)) throw new \RuntimeException('Verzeichnis konnte nicht gelöscht werden. Rechte und laufende Schreibvorgänge prüfen.');
        }
        --$remaining; ++$removed; return true;
    }
    public function step(Actor $actor, int $id): array
    {
        return $this->transaction($actor,$id,function(array $tenant) use($actor,$id): array {
            $job=$this->job($actor,$id);
            if (!$job || $tenant['active'] || $job['uuid']!==$tenant['public_id']) throw new \RuntimeException('Kein gültiger Löschauftrag.');
            $this->checkSchema();
            if ($job['phase']==='files') {
                if (isset($job['roots'][$job['root']])) {
                    $root=$job['roots'][$job['root']]; $base=$this->base($root['base']); clearstatcache(true,$base); $stat=stat($base);
                    if ($stat['dev']!==$root['device'] || $stat['ino']!==$root['inode']) throw new \RuntimeException('Storage-Mount wurde geändert. Vor Fortsetzung prüfen.');
                    $target=$base.'/'.$job['uuid'];
                    if (is_link($target) || (file_exists($target) && !is_dir($target))) throw new \RuntimeException('Mandantenwurzel ist kein sicheres Verzeichnis mehr.');
                    $remaining=200; $removed=0;
                    if ($this->removeEntries($target,$root['device'],$remaining,$removed,microtime(true)+2)) ++$job['root'];
                    $job['files_removed']+=$removed;
                }
                if ($job['root']>=count($job['roots'])) $job['phase']='database';
            } else {
                $budget=200;
                for ($pass=0;$pass<4 && $budget>0 && isset(self::TABLES[$job['table']]);++$pass) {
                    $table=self::TABLES[$job['table']];
                    $sql=$table==='folder_parents'?'UPDATE folders SET parent_id=NULL WHERE tenant_id=? AND parent_id IS NOT NULL':"DELETE FROM $table WHERE tenant_id=?";
                    if ($table==='users') {
                        // Preserve accounts used by ANY other membership, including inactive ones.
                        $s=$this->db->prepare('SELECT id,account_id FROM users WHERE tenant_id=? LIMIT '.$budget.' FOR UPDATE'); $s->execute([$id]); $members=$s->fetchAll();
                        $count=0;
                        if ($members) {
                            $accountIds=array_values(array_unique(array_column($members,'account_id')));
                            $marks=implode(',',array_fill(0,count($accountIds),'?'));
                            $s=$this->db->prepare('SELECT id FROM accounts WHERE id IN ('.$marks.') FOR UPDATE'); $s->execute($accountIds); $s->fetchAll();
                            $memberIds=array_column($members,'id'); $memberMarks=implode(',',array_fill(0,count($memberIds),'?'));
                            $s=$this->db->prepare('DELETE FROM users WHERE tenant_id=? AND id IN ('.$memberMarks.')'); $s->execute(array_merge([$id],$memberIds)); $count=$s->rowCount();
                            $s=$this->db->prepare('DELETE FROM accounts WHERE id IN ('.$marks.') AND NOT EXISTS (SELECT 1 FROM users WHERE users.account_id=accounts.id)'); $s->execute($accountIds);
                            $job['rows_removed']+=$s->rowCount();
                        }
                    } else { $s=$this->db->prepare($sql.' LIMIT '.$budget); $s->execute([$id]); $count=$s->rowCount(); }
                    if ($count<$budget) ++$job['table'];
                    $budget-=$count;
                    if ($table!=='folder_parents') $job['rows_removed']+=$count;
                }
                if ($job['table']>=count(self::TABLES)) {
                    $s=$this->db->prepare('DELETE FROM tenants WHERE id=?'); $s->execute([$id]);
                    $s=$this->db->prepare('DELETE FROM platform_settings WHERE setting_key=?'); $s->execute([self::key($id)]);
                    // Receipt is installation metadata only, with no tenant name or content.
                    $s=$this->db->prepare("INSERT INTO platform_audit_events (operator_id,action,details_json) VALUES (?,'tenant.deleted',?)"); $s->execute([$actor->id(),json_encode(['tenant_uuid'=>$job['uuid']],JSON_THROW_ON_ERROR)]);
                    $job['phase']='done'; return $job;
                }
            }
            $this->save($id,$actor,$job); return $job;
        });
    }
}
