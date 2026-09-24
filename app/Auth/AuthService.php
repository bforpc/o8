<?php
declare(strict_types=1);
namespace O8\Auth;
use O8\Core\Runtime;

final class AuthService
{
    public function __construct(private \PDO $db, private Runtime $runtime, private string $key) {}
    public static function password(string $value): void
    {
        if (mb_strlen($value,'UTF-8')<6 || strlen($value)>72 || $value==='owndms8' || preg_match('/[\x00-\x1f]/',$value)) throw new \InvalidArgumentException('Neues Passwort: mindestens 6 Zeichen, maximal 72 Bytes, keine Steuerzeichen oder bekanntes Startpasswort.');
    }
    public function login(string $kind, string $login, string $password, string $ip, string $setupToken=''): array
    {
        if (!in_array($kind,['operator','account'],true) || strlen($login)>254 || strlen($password)>4096) throw new \RuntimeException('Anmeldung nicht möglich.');
        $login=mb_strtolower(trim($login)); $this->reserve('ip:'.$ip,100); $this->reserve('account:'.$kind.':'.$login,10);
        if ($kind==='operator') { $s=$this->db->prepare('SELECT * FROM platform_operators WHERE login=? AND active=1'); }
        else { $s=$this->db->prepare('SELECT a.* FROM accounts a JOIN account_identifiers i ON i.account_id=a.id WHERE i.identifier=? AND a.active=1'); }
        $s->execute([$login]); $row=$s->fetch();
        $valid=password_verify($password,$row['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if (!$row || !$valid || ($kind==='operator' && $row['bootstrap_pending'] && !$this->runtime->verifySetupToken($setupToken))) throw new \RuntimeException('Anmeldung nicht möglich. Zugangsdaten prüfen.');
        if (password_needs_rehash($row['password_hash'],PASSWORD_DEFAULT)) {
            $table=$kind==='operator'?'platform_operators':'accounts'; $s=$this->db->prepare("UPDATE $table SET password_hash=? WHERE id=? AND password_hash=?"); $s->execute([password_hash($password,PASSWORD_DEFAULT),$row['id'],$row['password_hash']]);
        }
        $session=['kind'=>$kind,'id'=>(int)$row['id'],'auth_version'=>(int)$row['auth_version'],'since'=>time()];
        if ($kind==='account' && !$row['must_change_password']) $session=$this->autoSelect($session,new Actor('account',$row));
        return $session;
    }
    private function reserve(string $value, int $limit): void
    {
        $bucket=hash_hmac('sha256',$value,$this->key);
        $s=$this->db->prepare('INSERT INTO auth_attempts (bucket,attempts,window_started) VALUES (?,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE attempts=IF(window_started < UTC_TIMESTAMP()-INTERVAL 15 MINUTE,1,attempts+1),window_started=IF(window_started < UTC_TIMESTAMP()-INTERVAL 15 MINUTE,UTC_TIMESTAMP(),window_started)'); $s->execute([$bucket]);
        $s=$this->db->prepare('SELECT attempts FROM auth_attempts WHERE bucket=?'); $s->execute([$bucket]);
        if ((int)$s->fetchColumn()>$limit) throw new \RuntimeException('Zu viele Anmeldeversuche. Bitte nach 15 Minuten erneut versuchen.');
    }
    public function actor(?array $session): ?Actor
    {
        if (!$session || !in_array($session['kind']??'',['operator','account'],true)) return null;
        if (isset($session['tenant_id'])) {
            $last=(int)($session['last_activity']??$session['since']??0);
            if ($last<1 || time()-$last>$this->sessionMinutes((int)$session['tenant_id'])*60) return null;
        } elseif (time()-(int)($session['since']??0)>28800) return null;
        $table=$session['kind']==='operator'?'platform_operators':'accounts';
        $s=$this->db->prepare("SELECT * FROM $table WHERE id=? AND active=1 AND auth_version=?"); $s->execute([$session['id'],$session['auth_version']]); $account=$s->fetch();
        if (!$account) return null;
        if ($session['kind']==='operator') return new Actor('operator',$account);
        if (!$account['must_change_password'] && isset($session['membership_id'],$session['tenant_id'],$session['membership_version'])) {
            $s=$this->db->prepare('SELECT u.*,t.name AS tenant_name,t.public_id AS tenant_uuid FROM users u JOIN tenants t ON t.id=u.tenant_id WHERE u.id=? AND u.account_id=? AND u.tenant_id=? AND u.auth_version=? AND u.active=1 AND t.active=1');
            $s->execute([$session['membership_id'],$account['id'],$session['tenant_id'],$session['membership_version']]); $member=$s->fetch();
            if ($member && in_array($member['role'],['admin','user'],true)) {
                return new Actor('tenant',array_merge($account,$member,['account_auth_version'=>(int)$account['auth_version']]));
            }
        }
        return new Actor('account',$account);
    }
    public function memberships(Actor $actor): array
    {
        $actor->requireReady(); $account=$actor->accountId();
        $s=$this->db->prepare("SELECT u.id,u.tenant_id,u.auth_version,u.role,t.name,t.public_id FROM users u JOIN tenants t ON t.id=u.tenant_id JOIN accounts a ON a.id=u.account_id WHERE u.account_id=? AND a.active=1 AND u.active=1 AND t.active=1 AND u.role IN ('admin','user') ORDER BY t.name,t.id"); $s->execute([$account]); return $s->fetchAll();
    }
    private function context(array $session, array $member): array
    {
        return array_merge($session,['tenant_id'=>(int)$member['tenant_id'],'membership_id'=>(int)$member['id'],'membership_version'=>(int)$member['auth_version'],'context_token'=>bin2hex(random_bytes(16)),'last_activity'=>time()]);
    }
    public function sessionMinutes(int $tenantId): int
    {
        $s=$this->db->prepare("SELECT value_json FROM settings WHERE tenant_id=? AND setting_key='session.timeout_minutes'");
        $s->execute([$tenantId]); $value=$s->fetchColumn();
        if ($value===false) return 480;
        $data=json_decode((string)$value,true);
        $minutes=$data['minutes']??null;
        return is_int($minutes) && $minutes>=5 && $minutes<=10080 ? $minutes : 480;
    }
    public function saveSessionMinutes(Actor $actor, string $value): void
    {
        $actor->requireAdmin();
        if (!ctype_digit($value) || (int)$value<5 || (int)$value>10080) throw new \InvalidArgumentException('Sitzungsdauer muss zwischen 5 und 10080 Minuten liegen.');
        $this->db->beginTransaction();
        try {
            (new Access($this->db))->tenant($actor);
            $s=$this->db->prepare("INSERT INTO settings (tenant_id,setting_key,value_json,updated_by) VALUES (?,'session.timeout_minutes',?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()");
            $s->execute([$actor->tenantId(),json_encode(['minutes'=>(int)$value],JSON_THROW_ON_ERROR),$actor->id()]);
            $s=$this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id) VALUES (?,?,'session.settings.updated','settings','session.timeout_minutes')");
            $s->execute([$actor->tenantId(),$actor->id()]);
            $this->db->commit();
        } catch (\Throwable $error) { $this->db->rollBack(); throw $error; }
    }
    private function autoSelect(array $session, Actor $actor): array
    {
        $choices=$this->memberships($actor); return count($choices)===1?$this->context($session,$choices[0]):$session;
    }
    public function switchTenant(Actor $actor, int $tenant, array $session): array
    {
        $current=$this->actor($session);
        if (!$current || $current->kind==='operator' || $current->accountId()!==$actor->accountId()) throw new \RuntimeException('Sitzung nicht mehr gültig.');
        foreach ($this->memberships($current) as $member) if ((int)$member['tenant_id']===$tenant) return $this->context($session,$member);
        throw new \RuntimeException('Mandant nicht verfügbar oder keine Berechtigung.');
    }
    public function changePassword(Actor $actor, string $old, string $new): array
    {
        self::password($new);
        if (!password_verify($old,$actor->row['password_hash']) || password_verify($new,$actor->row['password_hash'])) throw new \RuntimeException('Aktuelles Passwort stimmt nicht oder neues Passwort ist unverändert.');
        $operator=$actor->kind==='operator'; $id=$operator?$actor->id():$actor->accountId(); $version=(int)($actor->row['account_auth_version']??$actor->row['auth_version']);
        $table=$operator?'platform_operators':'accounts';
        $s=$this->db->prepare("UPDATE $table SET password_hash=?,must_change_password=0,auth_version=auth_version+1 WHERE id=? AND auth_version=? AND active=1"); $s->execute([password_hash($new,PASSWORD_DEFAULT),$id,$version]);
        if ($s->rowCount()!==1) throw new \RuntimeException('Kontozustand geändert. Bitte neu anmelden.');
        $session=['kind'=>$operator?'operator':'account','id'=>$id,'auth_version'=>$version+1,'since'=>time()];
        if (!$operator) $session=$this->autoSelect($session,$this->actor($session));
        return $session;
    }
}
