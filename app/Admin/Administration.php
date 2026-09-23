<?php
declare(strict_types=1);
namespace O8\Admin;

use O8\Auth\{Actor,AuthService,AccountStore};
use O8\Core\Runtime;

/** All administrative writes recheck the actor inside the transaction. */
final class Administration
{
    public function __construct(private \PDO $db) {}

    private static function name(string $value): string
    {
        $value=trim($value);
        if ($value==='' || mb_strlen($value)>190 || preg_match('/[\x00-\x1f]/',$value)) throw new \InvalidArgumentException('Bitte einen Namen mit maximal 190 Zeichen angeben.');
        return $value;
    }
    private static function email(string $value): string
    {
        $value=mb_strtolower(trim($value));
        if (strlen($value)>254 || !filter_var($value,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Bitte eine gültige E-Mail-Adresse angeben.');
        return $value;
    }
    private static function role(string $value): string
    {
        if (!in_array($value,['admin','user'],true)) throw new \InvalidArgumentException('Unbekannte Benutzerrolle.');
        return $value;
    }
    private function transaction(Actor $actor, ?int $tenant, callable $callback): mixed
    {
        $this->db->beginTransaction();
        try {
            // One serialization point per tenant protects the last active admin,
            // including concurrent requests affecting different user rows.
            if ($tenant!==null) {
                $s=$this->db->prepare('SELECT active FROM tenants WHERE id=? FOR UPDATE'); $s->execute([$tenant]); $row=$s->fetch();
                if (!$row || ($actor->kind==='tenant' && !$row['active'])) throw new \RuntimeException('Mandant nicht verfügbar.');
                $s=$this->db->prepare('SELECT 1 FROM platform_settings WHERE setting_key=?'); $s->execute([TenantDeletion::key($tenant)]);
                if ($s->fetchColumn()) throw new \RuntimeException('Mandant wird endgültig gelöscht und kann nicht mehr geändert oder reaktiviert werden.');
            }
            $query=$actor->kind==='operator'?'SELECT * FROM platform_operators WHERE id=? FOR UPDATE':'SELECT u.*,a.must_change_password,a.active AS account_active,a.auth_version AS account_auth_version FROM users u JOIN accounts a ON a.id=u.account_id WHERE u.id=? FOR UPDATE';
            $s=$this->db->prepare($query); $s->execute([$actor->id()]); $row=$s->fetch();
            if (!$row || !$row['active'] || (int)$row['auth_version']!==(int)$actor->row['auth_version']) throw new \RuntimeException('Berechtigung wurde geändert. Bitte neu anmelden.');
            if ($actor->kind==='tenant' && (!$row['account_active'] || (int)$row['account_auth_version']!==(int)$actor->row['account_auth_version'] || (int)$row['account_id']!==$actor->accountId())) throw new \RuntimeException('Benutzerkonto wurde geändert. Bitte neu anmelden.');
            $current=new Actor($actor->kind,$row);
            if ($actor->kind==='operator') $current->requireOperator();
            else { $current->requireAdmin(); if ($current->tenantId()!==$tenant) throw new \RuntimeException('Falscher Mandantenkontext.'); }
            $result=$callback(); $this->db->commit(); return $result;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($e instanceof \PDOException && TenantName::isConflict($e)) throw new \RuntimeException('Dieser Mandantenname ist bereits vergeben. Bitte einen anderen Namen wählen.');
            if ($e instanceof \PDOException && ($e->errorInfo[1]??null)===1062) throw new \RuntimeException('Login, E-Mail-Adresse oder Zuordnung bereits vergeben. Bestehende Konten ausdrücklich zuordnen, nicht erneut anlegen.');
            throw $e;
        }
    }
    private function audit(Actor $actor, int $tenant, string $action, ?int $user=null): void
    {
        if ($actor->kind==='operator') {
            $s=$this->db->prepare('INSERT INTO platform_audit_events (operator_id,tenant_id,action) VALUES (?,?,?)'); $s->execute([$actor->id(),$tenant,$action]);
        } else {
            $s=$this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id) VALUES (?,?,?,'user',?)"); $s->execute([$tenant,$actor->id(),$action,(string)$user]);
        }
    }
    public function tenants(Actor $actor): array
    {
        $actor->requireOperator();
        return $this->db->query('SELECT id,public_id,name,contact_email,active FROM tenants ORDER BY name,id')->fetchAll();
    }
    public function createTenant(Actor $actor, string $name, string $contact, array $admin): string
    {
        $actor->requireOperator(); $name=TenantName::normalize($name); $contact=$contact===''?null:self::email($contact);
        $existing=trim((string)($admin['existing_login']??''));
        if ($existing==='') $admin=$this->userInput($admin);
        $uuid=Runtime::uuid();
        return $this->transaction($actor,null,function() use($actor,$name,$contact,$admin,$uuid,$existing): string {
            TenantName::available($this->db,$name);
            $s=$this->db->prepare('INSERT INTO tenants (public_id,name,contact_email) VALUES (?,?,?)'); $s->execute([$uuid,$name,$contact]); $id=(int)$this->db->lastInsertId();
            $s=$this->db->prepare("INSERT INTO roles (tenant_id,code,name,system_role) VALUES (?,'admin','Administrator',1),(?,'user','Benutzer',1)"); $s->execute([$id,$id]);
            if ($existing!=='') { $accounts=new AccountStore($this->db); $accounts->join($id,$accounts->existing($existing),'admin'); }
            else $this->insertUser($id,$admin,'admin');
            $this->audit($actor,$id,'tenant.created'); return $uuid;
        });
    }
    public function updateTenant(Actor $actor, int $id, string $name, string $contact, bool $active): void
    {
        $actor->requireOperator(); $name=TenantName::normalize($name); $contact=$contact===''?null:self::email($contact);
        $this->transaction($actor,$id,function() use($actor,$id,$name,$contact,$active): void {
            TenantName::available($this->db,$name,$id);
            $s=$this->db->prepare('SELECT active FROM tenants WHERE id=?'); $s->execute([$id]); $old=(bool)$s->fetchColumn();
            $s=$this->db->prepare('UPDATE tenants SET name=?,contact_email=?,active=? WHERE id=?'); $s->execute([$name,$contact,(int)$active,$id]);
            if ($old!==$active) {
                // Revocation persists after reactivation; old sessions never revive.
                $s=$this->db->prepare('UPDATE users SET auth_version=auth_version+1 WHERE tenant_id=?'); $s->execute([$id]);
            }
            $this->audit($actor,$id,$old!==$active?($active?'tenant.activated':'tenant.deactivated'):'tenant.updated');
        });
    }
    public function users(Actor $actor, string $search=''): array
    {
        $actor->requireAdmin();
        $search=mb_substr(trim($search),0,190);
        $s=$this->db->prepare("SELECT u.id,a.login,u.display_name,a.email,u.role,u.active,u.auth_version,a.must_change_password FROM users u JOIN accounts a ON a.id=u.account_id WHERE u.tenant_id=? AND (?='' OR LOCATE(?,CONCAT_WS(' ',a.login COLLATE utf8mb4_unicode_ci,u.display_name COLLATE utf8mb4_unicode_ci,a.email COLLATE utf8mb4_unicode_ci))>0) ORDER BY u.display_name,u.id LIMIT 201");
        $s->execute([$actor->tenantId(),$search,$search]); return $s->fetchAll();
    }
    private function userInput(array $input): array
    {
        $login=mb_strtolower(trim((string)($input['login']??'')));
        if (!preg_match('/^[a-z0-9][a-z0-9._@-]{0,189}$/D',$login)) throw new \InvalidArgumentException('Login: Buchstaben a–z, Ziffern und . _ @ - (maximal 190 Zeichen).');
        $name=self::name((string)($input['name']??'')); $email=self::email((string)($input['email']??''));
        $password=(string)($input['password']??''); AuthService::password($password);
        return ['login'=>$login,'name'=>$name,'email'=>$email,'hash'=>password_hash($password,PASSWORD_DEFAULT)];
    }
    private function insertUser(int $tenant, array $user, string $role): int
    {
        $accounts=new AccountStore($this->db);
        return $accounts->join($tenant,$accounts->create($user),$role);
    }
    public function createUser(Actor $actor, array $input, string $role): int
    {
        $actor->requireAdmin(); $input=$this->userInput($input); $role=self::role($role);
        return $this->transaction($actor,$actor->tenantId(),function() use($actor,$input,$role): int {
            $id=$this->insertUser($actor->tenantId(),$input,$role); $this->audit($actor,$actor->tenantId(),'user.created',$id); return $id;
        });
    }
    private function target(Actor $actor, int $id, int $version): array
    {
        $s=$this->db->prepare('SELECT * FROM users WHERE tenant_id=? AND id=? FOR UPDATE'); $s->execute([$actor->tenantId(),$id]); $row=$s->fetch();
        if (!$row) throw new \RuntimeException('Benutzer nicht verfügbar.');
        if ((int)$row['auth_version']!==$version) throw new \RuntimeException('Benutzer wurde zwischenzeitlich geändert. Bitte neu laden.');
        return $row;
    }
    public function updateUser(Actor $actor, int $id, int $version, string $name, string $role, bool $active): void
    {
        $actor->requireAdmin(); $name=self::name($name); $role=self::role($role);
        $this->transaction($actor,$actor->tenantId(),function() use($actor,$id,$version,$name,$role,$active): void {
            $row=$this->target($actor,$id,$version);
            if ($row['active'] && $row['role']==='admin' && (!$active || $role!=='admin')) {
                $s=$this->db->prepare("SELECT u.id FROM users u JOIN accounts a ON a.id=u.account_id WHERE u.tenant_id=? AND u.active=1 AND u.role='admin' AND a.active=1 FOR UPDATE"); $s->execute([$actor->tenantId()]);
                if (count($s->fetchAll())<=1) throw new \RuntimeException('Der letzte aktive Administrator darf nicht gesperrt oder zum User geändert werden.');
            }
            $s=$this->db->prepare('UPDATE users SET display_name=?,role=?,active=?,auth_version=auth_version+1 WHERE tenant_id=? AND id=?'); $s->execute([$name,$role,(int)$active,$actor->tenantId(),$id]);
            $this->audit($actor,$actor->tenantId(),'user.updated',$id);
        });
    }
    public function resetPassword(Actor $actor, int $id, int $version, string $password): void
    {
        $actor->requireAdmin();
        throw new \RuntimeException('Mandanten-Admins dürfen das gemeinsame Kontopasswort nicht zurücksetzen.');
    }
    public function inviteUser(Actor $actor, string $role): string
    {
        $actor->requireAdmin(); $role=self::role($role); $token=bin2hex(random_bytes(24));
        return $this->transaction($actor,$actor->tenantId(),function() use($actor,$role,$token): string {
            $s=$this->db->prepare('INSERT INTO tenant_invitations (tenant_id,created_by,creator_version,role,token_hash,expires_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP()+INTERVAL 7 DAY)');
            $s->execute([$actor->tenantId(),$actor->id(),$actor->row['auth_version'],$role,hash('sha256',$token)]);
            $this->audit($actor,$actor->tenantId(),'user.invited'); return $token;
        });
    }
}
