<?php
declare(strict_types=1);
namespace O8\Auth;

final class Actor
{
    public function __construct(public readonly string $kind, public readonly array $row) {}
    public function id(): int { return (int)$this->row['id']; }
    public function accountId(): int
    {
        if ($this->kind==='operator') throw new \RuntimeException('Betreiber hat kein gemeinsames Benutzerkonto.');
        return $this->kind==='account'?$this->id():(int)$this->row['account_id'];
    }
    public function tenantId(): int
    {
        if ($this->kind !== 'tenant') throw new \RuntimeException('Betreiber hat keinen Dokumentkontext.');
        return (int)$this->row['tenant_id'];
    }
    public function requireReady(): void
    {
        if (!in_array($this->kind,['operator','account','tenant'],true) || ($this->kind==='tenant' && !in_array($this->row['role']??'',['admin','user'],true))) throw new \RuntimeException('Unbekannter Berechtigungskontext.');
        if ($this->row['must_change_password']) throw new \RuntimeException('Zuerst das Startpasswort ändern.');
        if ($this->row['bootstrap_pending'] ?? false) throw new \RuntimeException('Zuerst die Ersteinrichtung abschließen.');
    }
    public function requireOperator(): void { $this->requireReady(); if ($this->kind !== 'operator') throw new \RuntimeException('Nur für Betreiber.'); }
    public function requireAdmin(): void { $this->requireReady(); if ($this->kind !== 'tenant' || $this->row['role'] !== 'admin') throw new \RuntimeException('Nur für Mandanten-Admins.'); }
    public function canAccess(array $resource): bool
    {
        $this->requireReady();
        return $this->kind === 'tenant' && (int)($resource['tenant_id'] ?? 0) === $this->tenantId()
            && ($this->row['role'] === 'admin' || (int)($resource['owner_id'] ?? 0) === $this->id());
    }
    public function documentScope(string $alias = 'd'): array
    {
        $this->requireReady();
        if (!preg_match('/^[a-z]+$/D',$alias) || $this->kind !== 'tenant') throw new \RuntimeException('Kein Dokumentzugriff.');
        $sql=$alias.'.tenant_id = ?'; $params=[$this->tenantId()];
        if ($this->row['role'] !== 'admin') { $sql.=' AND '.$alias.'.owner_id = ?'; $params[]=$this->id(); }
        return [$sql,$params];
    }
}
