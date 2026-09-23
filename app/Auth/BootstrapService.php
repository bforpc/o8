<?php
declare(strict_types=1);
namespace O8\Auth;
use O8\Core\Runtime;
use O8\Admin\TenantName;

final class BootstrapService
{
    public function __construct(private \PDO $db) {}
    public function complete(Actor $actor, string $name, string $email, string $tenantName): string
    {
        if ($actor->kind !== 'operator' || $actor->row['must_change_password'] || !$actor->row['bootstrap_pending']) throw new \RuntimeException('Ersteinrichtung nicht erlaubt.');
        $name=trim($name); $email=mb_strtolower(trim($email)); $tenantName=TenantName::normalize($tenantName);
        if ($name==='' || mb_strlen($name)>190 || $tenantName==='' || mb_strlen($tenantName)>190 || strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Name, gültige E-Mail-Adresse und Mandantenname erforderlich.');
        $uuid=Runtime::uuid();
        $this->db->beginTransaction();
        try {
            $stmt=$this->db->prepare('SELECT bootstrap_pending,auth_version FROM platform_operators WHERE id=? FOR UPDATE'); $stmt->execute([$actor->id()]); $current=$stmt->fetch();
            if (!$current || !$current['bootstrap_pending'] || (int)$current['auth_version']!==(int)$actor->row['auth_version']) throw new \RuntimeException('Einrichtung wurde bereits geändert. Bitte neu anmelden.');
            TenantName::available($this->db,$tenantName);
            $stmt=$this->db->prepare('INSERT INTO tenants (public_id,name,contact_email) VALUES (?,?,?)'); $stmt->execute([$uuid,$tenantName,$email]); $tenant=(int)$this->db->lastInsertId();
            $stmt=$this->db->prepare("INSERT INTO roles (tenant_id,code,name,system_role) VALUES (?,'admin','Administrator',1),(?,'user','Benutzer',1)"); $stmt->execute([$tenant,$tenant]);
            // Explicit first-tenant membership only. No access to other tenants.
            $accounts=new AccountStore($this->db);
            $account=$accounts->create(['login'=>'admin','name'=>$name,'email'=>$email,'hash'=>$actor->row['password_hash']],false);
            $accounts->join($tenant,$account,'admin');
            $stmt=$this->db->prepare('UPDATE platform_operators SET display_name=?,email_normalized=?,bootstrap_pending=0 WHERE id=?'); $stmt->execute([$name,$email,$actor->id()]);
            $stmt=$this->db->prepare("INSERT INTO platform_audit_events (operator_id,tenant_id,action) VALUES (?,?,'installation.bootstrap_completed')"); $stmt->execute([$actor->id(),$tenant]);
            $this->db->commit(); return $uuid;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($error instanceof \PDOException && TenantName::isConflict($error)) throw new \RuntimeException('Dieser Mandantenname ist bereits vergeben. Bitte einen anderen Namen wählen.');
            throw $error;
        }
    }
}
