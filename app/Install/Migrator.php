<?php
declare(strict_types=1);
namespace O8\Install;
use O8\Core\Database;

final class Migrator
{
    public function __construct(private \PDO $db, private string $migrationPath) {}

    public function installed(array $identity): bool
    {
        $exists = $this->db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='o8_installation'")->fetchColumn();
        if (!$exists) return false;
        $row = $this->db->query('SELECT * FROM o8_installation WHERE singleton=1')->fetch();
        if (!$row || !hash_equals($row['installation_id'], $identity['id']) || !hash_equals($row['key_hash'], hash('sha256',$identity['key']))) throw new \RuntimeException('Datenbank gehört nicht zu dieser Installation.');
        return $row['status'] === 'ready';
    }

    public function current(): bool
    {
        $s=$this->db->prepare('SELECT applied_at FROM o8_migrations WHERE version=?'); $s->execute(['010_inbound_acceptance.sql']);
        return (bool)$s->fetchColumn();
    }

    public function install(array $identity): void
    {
        Database::compatible($this->db);
        $lock = 'o8.install.'.substr(hash('sha256',(string)$this->db->query('SELECT DATABASE()')->fetchColumn()),0,40);
        $stmt = $this->db->prepare('SELECT GET_LOCK(?, 0)'); $stmt->execute([$lock]);
        if ((int)$stmt->fetchColumn() !== 1) throw new \RuntimeException('Datenbank wird bereits eingerichtet.');
        try {
            $tables = $this->db->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchAll(\PDO::FETCH_COLUMN);
            if ($tables && !in_array('o8_installation', $tables, true)) throw new \RuntimeException('Die Datenbank ist nicht leer. Bestehende Tabellen werden nicht verändert.');
            if (!$tables) {
                $this->db->exec("CREATE TABLE o8_installation (singleton TINYINT PRIMARY KEY, installation_id CHAR(36) NOT NULL, key_hash CHAR(64) NOT NULL, status VARCHAR(16) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $stmt = $this->db->prepare("INSERT INTO o8_installation VALUES (1, ?, ?, 'installing')");
                $stmt->execute([$identity['id'],hash('sha256',$identity['key'])]);
            }
            if ($this->installed($identity)) throw new \RuntimeException('Installation bereits abgeschlossen. Kein Zurücksetzen möglich.');
            $this->db->exec('CREATE TABLE IF NOT EXISTS o8_migrations (version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL, completed_steps INT NOT NULL DEFAULT 0, applied_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $files = glob($this->migrationPath.'/*.sql'); sort($files);
            foreach ($files as $file) $this->apply($file);
            $this->db->beginTransaction();
            try {
                $count = (int)$this->db->query('SELECT COUNT(*) FROM platform_operators')->fetchColumn();
                if ($count !== 0) throw new \RuntimeException('Unerwartetes Administratorkonto in unvollständiger Installation.');
                $stmt = $this->db->prepare("INSERT INTO platform_operators (login,display_name,password_hash,must_change_password,bootstrap_pending) VALUES ('admin','Administrator',?,1,1)");
                $stmt->execute([password_hash('owndms8', PASSWORD_DEFAULT)]);
                $this->db->exec("UPDATE o8_installation SET status='ready' WHERE singleton=1");
                $this->db->commit();
            } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
        } finally { $stmt=$this->db->prepare('SELECT RELEASE_LOCK(?)'); $stmt->execute([$lock]); }
    }

    /** Versioned upgrades; never repeat bootstrap or reset existing accounts. */
    public function upgrade(array $identity): void
    {
        if (!$this->installed($identity)) throw new \RuntimeException('Zuerst die Installation abschließen.');
        $lock='o8.install.'.substr(hash('sha256',(string)$this->db->query('SELECT DATABASE()')->fetchColumn()),0,40);
        $s=$this->db->prepare('SELECT GET_LOCK(?,0)'); $s->execute([$lock]);
        if ((int)$s->fetchColumn()!==1) throw new \RuntimeException('Eine Datenbankaktualisierung läuft bereits.');
        try {
            $files=glob($this->migrationPath.'/*.sql'); sort($files);
            foreach ($files as $file) $this->apply($file);
        } finally { $s=$this->db->prepare('SELECT RELEASE_LOCK(?)'); $s->execute([$lock]); }
    }

    private function apply(string $file): void
    {
        $version=basename($file); $sql=(string)file_get_contents($file); $checksum=hash('sha256',$sql);
        $query=$this->db->prepare('SELECT * FROM o8_migrations WHERE version=?'); $query->execute([$version]); $record=$query->fetch();
        if ($record && !hash_equals($record['checksum'],$checksum)) throw new \RuntimeException('Migrationsdatei wurde nach Beginn verändert.');
        if ($record && $record['applied_at']) return;
        if ($version==='003_shared_accounts.sql') {
            if ($this->db->query("SELECT COUNT(*) FROM platform_settings WHERE setting_key LIKE 'tenant.delete.%'")->fetchColumn()) throw new \RuntimeException('Laufende Mandantenlöschungen zuerst abschließen.');
            $legacy=$this->db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='password_hash'")->fetchColumn();
            if ($legacy) {
                $conflicts=$this->db->query("SELECT COUNT(*) FROM (SELECT identifier FROM (SELECT id,LOWER(TRIM(login)) COLLATE utf8mb4_unicode_ci identifier FROM users UNION ALL SELECT id,LOWER(TRIM(email_normalized)) COLLATE utf8mb4_unicode_ci identifier FROM users) identifiers GROUP BY identifier HAVING COUNT(DISTINCT id)>1) conflicts")->fetchColumn();
                if ($conflicts) throw new \RuntimeException('Mehrdeutige Benutzerkennungen oder E-Mail-Adressen vorhanden. Konten zuerst ausdrücklich klären; es wird nichts automatisch zusammengeführt.');
            }
        }
        if (!$record) { $query=$this->db->prepare('INSERT INTO o8_migrations (version,checksum) VALUES (?,?)'); $query->execute([$version,$checksum]); }
        $statements=array_values(array_filter(array_map('trim',explode(';',preg_replace('/--[^\r\n]*/','',$sql)))));
        foreach ($statements as $index=>$statement) {
            if ($index < (int)($record['completed_steps'] ?? 0)) continue;
            if (str_starts_with($statement,'ALTER TABLE document_files ADD CONSTRAINT files_storage')) {
                $exists=$this->db->query("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='document_files' AND constraint_name='files_storage'")->fetchColumn();
                if (!$exists) $this->db->exec($statement);
            } elseif (str_starts_with($statement,'ALTER TABLE tenants ADD COLUMN name_key')) {
                $exists=$this->db->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='tenants' AND index_name='tenants_name_unique' AND non_unique=0 AND column_name='name_key'")->fetchColumn();
                if (!$exists) {
                    $duplicates=$this->db->query("SELECT COUNT(*) FROM (SELECT LOWER(TRIM(name)) COLLATE utf8mb4_unicode_ci AS normalized FROM tenants GROUP BY normalized HAVING COUNT(*)>1) duplicates")->fetchColumn();
                    if ($duplicates) throw new \RuntimeException('Doppelte Mandantennamen vorhanden. Diese zuerst eindeutig umbenennen; es wurden keine Mandanten zusammengeführt oder gelöscht.');
                    $this->db->exec($statement);
                }
            } elseif (str_starts_with($statement,'ALTER TABLE users ADD COLUMN account_id')) {
                $exists=$this->db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='account_id'")->fetchColumn();
                if (!$exists) $this->db->exec($statement);
            } elseif (str_starts_with($statement,'ALTER TABLE users MODIFY account_id')) {
                $exists=$this->db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='password_hash'")->fetchColumn();
                if ($exists) $this->db->exec($statement);
            } elseif (preg_match('/^ALTER TABLE (storage_locations|documents|import_sources|background_jobs) ADD COLUMN (identity_json|revision|error_code) /',$statement,$match)) {
                $s=$this->db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?'); $s->execute([$match[1],$match[2]]);
                if (!$s->fetchColumn()) $this->db->exec($statement);
            } elseif (preg_match('/^ALTER TABLE ([a-z_]+) ADD (?:UNIQUE )?INDEX ([a-z_]+) /',$statement,$match)) {
                $s=$this->db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?'); $s->execute([$match[1],$match[2]]);
                if (!$s->fetchColumn()) $this->db->exec($statement);
            } else {
                // CREATE IF NOT EXISTS also handles interruption between DDL and journal write.
                $this->db->exec($statement);
            }
            $query=$this->db->prepare('UPDATE o8_migrations SET completed_steps=? WHERE version=?'); $query->execute([$index+1,$version]);
        }
        $query=$this->db->prepare('UPDATE o8_migrations SET applied_at=UTC_TIMESTAMP() WHERE version=?'); $query->execute([$version]);
    }
}
