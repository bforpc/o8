<?php
declare(strict_types=1);
namespace O8\Admin;

final class TenantName
{
    public static function normalize(string $name): string
    {
        $name=trim($name);
        if ($name==='' || mb_strlen($name)>190 || preg_match('/[\x00-\x1f]/',$name)) throw new \InvalidArgumentException('Bitte einen Mandantennamen mit maximal 190 Zeichen angeben.');
        return $name;
    }
    public static function available(\PDO $db, string $name, int $except=0): void
    {
        // Same comparison as the unique generated database key, also usable before upgrade.
        $s=$db->prepare('SELECT id FROM tenants WHERE LOWER(TRIM(name)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(?)) COLLATE utf8mb4_unicode_ci AND id<>? LIMIT 1');
        $s->execute([$name,$except]);
        if ($s->fetchColumn()!==false) throw new \RuntimeException('Dieser Mandantenname ist bereits vergeben. Bitte einen anderen Namen wählen.');
    }
    public static function isConflict(\PDOException $error): bool
    {
        return (int)($error->errorInfo[1]??0)===1062 && str_contains($error->getMessage(),'tenants_name_unique');
    }
}
