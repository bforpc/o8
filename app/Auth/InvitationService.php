<?php
declare(strict_types=1);
namespace O8\Auth;

final class InvitationService
{
    public function __construct(private \PDO $db) {}
    public function accept(Actor $actor, string $token): int
    {
        $actor->requireReady(); $account=$actor->accountId(); $token=strtolower(trim($token));
        if (!preg_match('/^[a-f0-9]{48}$/D',$token)) throw new \RuntimeException('Einladung ungültig, abgelaufen oder bereits verwendet.');
        $hash=hash('sha256',$token);
        $s=$this->db->prepare('SELECT tenant_id FROM tenant_invitations WHERE token_hash=?'); $s->execute([$hash]); $tenant=(int)$s->fetchColumn();
        $this->db->beginTransaction();
        try {
            $s=$this->db->prepare('SELECT active FROM tenants WHERE id=? FOR UPDATE'); $s->execute([$tenant]);
            if (!$s->fetchColumn()) throw new \RuntimeException('Einladung ungültig, abgelaufen oder bereits verwendet.');
            $s=$this->db->prepare('SELECT * FROM tenant_invitations WHERE token_hash=? AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE'); $s->execute([$hash]); $invite=$s->fetch();
            if (!$invite) throw new \RuntimeException('Einladung ungültig, abgelaufen oder bereits verwendet.');
            $s=$this->db->prepare("SELECT u.id FROM users u JOIN accounts a ON a.id=u.account_id WHERE u.id=? AND u.tenant_id=? AND u.active=1 AND u.role='admin' AND u.auth_version=? AND a.active=1 FOR UPDATE");
            $s->execute([$invite['created_by'],$tenant,$invite['creator_version']]);
            if (!$s->fetchColumn()) throw new \RuntimeException('Einladung ist nicht mehr freigegeben.');
            $s=$this->db->prepare('SELECT * FROM accounts WHERE id=? AND active=1 AND must_change_password=0 AND auth_version=? FOR UPDATE'); $s->execute([$account,$actor->row['account_auth_version']??$actor->row['auth_version']]); $row=$s->fetch();
            if (!$row) throw new \RuntimeException('Konto wurde geändert. Bitte neu anmelden.');
            $s=$this->db->prepare('SELECT id FROM users WHERE tenant_id=? AND account_id=?'); $s->execute([$tenant,$account]);
            if ($s->fetchColumn()) throw new \RuntimeException('Zuordnung bereits vorhanden. Eine Sperre kann nur der Mandanten-Admin aufheben.');
            $member=(new AccountStore($this->db))->join($tenant,$row,$invite['role']);
            $s=$this->db->prepare('UPDATE tenant_invitations SET used_at=UTC_TIMESTAMP() WHERE id=?'); $s->execute([$invite['id']]);
            $s=$this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id) VALUES (?,?,'user.invitation_accepted','user',?)"); $s->execute([$tenant,$member,(string)$member]);
            $this->db->commit(); return $tenant;
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }
}
