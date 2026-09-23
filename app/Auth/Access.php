<?php
declare(strict_types=1);
namespace O8\Auth;

/** Recheck identities inside write transactions; tenant lock serializes deletion. */
final class Access
{
    public function __construct(private \PDO $db) {}
    public function operator(Actor $actor): void
    {
        $actor->requireOperator();
        $s=$this->db->prepare('SELECT * FROM platform_operators WHERE id=? AND active=1 AND auth_version=?'.($this->db->inTransaction()?' FOR UPDATE':'')); $s->execute([$actor->id(),$actor->row['auth_version']]);
        $row=$s->fetch(); if (!$row) throw new \RuntimeException('Berechtigung geändert. Bitte neu anmelden.');
        (new Actor('operator',$row))->requireOperator();
    }
    public function tenant(Actor $actor): array
    {
        $actor->requireReady(); $id=$actor->tenantId(); $lock=$this->db->inTransaction()?' FOR UPDATE':'';
        $s=$this->db->prepare('SELECT * FROM tenants WHERE id=? AND active=1'.$lock); $s->execute([$id]); $tenant=$s->fetch();
        if (!$tenant) throw new \RuntimeException('Mandant nicht verfügbar.');
        $s=$this->db->prepare('SELECT u.role,u.auth_version,a.auth_version AS account_version FROM users u JOIN accounts a ON a.id=u.account_id WHERE u.id=? AND u.tenant_id=? AND u.account_id=? AND u.active=1 AND a.active=1 AND a.must_change_password=0'.$lock);
        $s->execute([$actor->id(),$id,$actor->accountId()]); $row=$s->fetch();
        if (!$row || (int)$row['auth_version']!==(int)$actor->row['auth_version'] || (int)$row['account_version']!==(int)$actor->row['account_auth_version'] || $row['role']!==$actor->row['role']) throw new \RuntimeException('Berechtigung geändert. Bitte Seite neu laden.');
        $s=$this->db->prepare('SELECT 1 FROM platform_settings WHERE setting_key=?'); $s->execute(['tenant.delete.'.$id]);
        if ($s->fetchColumn()) throw new \RuntimeException('Mandant wird gelöscht.');
        return $tenant;
    }
}
