<?php
declare(strict_types=1);
namespace O8\Auth;

final class AccountStore
{
    public function __construct(private \PDO $db) {}
    public function create(array $input, bool $mustChange=true): array
    {
        if (!$this->db->inTransaction()) throw new \LogicException('Account creation requires a transaction.');
        $login=mb_strtolower(trim((string)($input['login']??'')));
        $name=trim((string)($input['name']??'')); $email=mb_strtolower(trim((string)($input['email']??'')));
        if (!preg_match('/^[a-z0-9][a-z0-9._@-]{0,189}$/D',$login) || !$name || mb_strlen($name)>190 || preg_match('/[\x00-\x1f]/',$name) || strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Gültigen Login, Namen und E-Mail-Adresse angeben.');
        if (isset($input['hash'])) $hash=$input['hash'];
        else { AuthService::password((string)($input['password']??'')); $hash=password_hash($input['password'],PASSWORD_DEFAULT); }
        $s=$this->db->prepare('INSERT INTO accounts (login,display_name,email,email_normalized,password_hash,must_change_password) VALUES (?,?,?,?,?,?)'); $s->execute([$login,$name,$email,$email,$hash,(int)$mustChange]);
        $id=(int)$this->db->lastInsertId();
        $s=$this->db->prepare('INSERT INTO account_identifiers (identifier,account_id) VALUES (?,?)');
        foreach (array_unique([$login,$email]) as $identifier) $s->execute([$identifier,$id]);
        return ['id'=>$id,'display_name'=>$name];
    }
    public function existing(string $identifier): array
    {
        $s=$this->db->prepare('SELECT a.* FROM accounts a JOIN account_identifiers i ON i.account_id=a.id WHERE i.identifier=? AND a.active=1 FOR UPDATE'); $s->execute([mb_strtolower(trim($identifier))]);
        $row=$s->fetch(); if (!$row) throw new \RuntimeException('Aktives Benutzerkonto nicht gefunden.'); return $row;
    }
    public function join(int $tenant, array $account, string $role): int
    {
        $s=$this->db->prepare('INSERT INTO users (tenant_id,account_id,display_name,role) VALUES (?,?,?,?)'); $s->execute([$tenant,$account['id'],$account['display_name'],$role]); return (int)$this->db->lastInsertId();
    }
}
