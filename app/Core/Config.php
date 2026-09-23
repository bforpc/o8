<?php
declare(strict_types=1);
namespace O8\Core;

final class Config
{
    public static function load(string $root): ?array
    {
        if (!is_file($root . '/config.php')) return null;
        $config = require $root . '/config.php';
        if (!is_array($config)) throw new \RuntimeException('Ungültige lokale Konfiguration.');
        $config['database'] = self::database($config['database'] ?? []);
        return $config;
    }

    public static function database(array $input): array
    {
        $host = trim((string)($input['host'] ?? 'localhost'));
        $name = trim((string)($input['name'] ?? 'o8'));
        $user = trim((string)($input['user'] ?? 'o8'));
        $port = filter_var($input['port'] ?? 3306, FILTER_VALIDATE_INT);
        if (!preg_match('/^[a-zA-Z0-9_.:-]+$/D', $host) || strlen($host) > 253
            || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $name)
            || $user === '' || strlen($user) > 100 || preg_match('/[\x00-\x1f;]/', $user)
            || !$port || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Bitte gültigen Datenbankhost, Port, Datenbanknamen und Benutzer angeben.');
        }
        $password = (string)($input['password'] ?? '');
        if (strlen($password) > 4096 || str_contains($password, "\0")) throw new \InvalidArgumentException('Ungültiges Datenbankkennwort.');
        return compact('host', 'port', 'name', 'user', 'password');
    }

    public static function writeNew(string $root, array $config): void
    {
        $config['database'] = self::database($config['database'] ?? []);
        // Exclusive creation: never overwrite an existing manual configuration.
        $file = @fopen($root . '/config.php', 'x');
        if (!$file) throw new \RuntimeException('config.php kann nicht neu angelegt werden. Vorhandene Konfiguration bleibt unverändert.');
        try {
            chmod($root . '/config.php', 0640);
            $data = "<?php\ndeclare(strict_types=1);\n// Local secrets. Do not publish.\nreturn " . var_export($config, true) . ";\n";
            if (fwrite($file, $data) !== strlen($data) || !fflush($file)) throw new \RuntimeException('Konfiguration konnte nicht vollständig geschrieben werden. Manuell prüfen.');
            if (function_exists('fsync')) fsync($file);
        } finally { fclose($file); }
    }
}
