<?php
declare(strict_types=1);
namespace O8\Core;

final class Runtime
{
    public function __construct(public readonly string $path)
    {
        if (is_link($path)) throw new \RuntimeException('Systemverzeichnis storage/system darf kein Symlink sein.');
        if (!is_dir($path) && !@mkdir($path, 0770, true) && !is_dir($path)) throw new \RuntimeException('Systemverzeichnis storage/system konnte nicht angelegt werden. Der Web-PHP-Benutzer benötigt Schreibrecht auf storage oder storage/system muss vorher angelegt werden.');
        if (!is_writable($path)) throw new \RuntimeException('Systemverzeichnis storage/system ist für den Web-PHP-Benutzer nicht beschreibbar.');
    }
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $h = bin2hex($bytes);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
    public function read(string $name): ?array
    {
        $file = $this->path . '/' . $name . '.json';
        if (!is_file($file)) return null;
        $contents=file_get_contents($file);
        if ($contents===false) throw new \RuntimeException('Systemdatei storage/system/'.$name.'.json kann nicht gelesen werden. Rechte für den Web-PHP-Benutzer prüfen.');
        try { $data=json_decode($contents, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new \RuntimeException('Systemdatei storage/system/'.$name.'.json enthält ungültiges JSON. Bei einer nachweislich noch unbenutzten Erstinstallation diese Datei entfernen; bei einer bestehenden Installation aus dem Backup wiederherstellen.'); }
        if (!is_array($data)) throw new \RuntimeException('Systemdatei storage/system/'.$name.'.json enthält keinen gültigen Systemzustand. Bei einer bestehenden Installation aus dem Backup wiederherstellen.');
        return $data;
    }
    public function write(string $name, array $value): void
    {
        $tmp = tempnam($this->path, '.write-');
        if ($tmp === false) throw new \RuntimeException('Systemzustand nicht schreibbar.');
        try {
            chmod($tmp, 0640);
            $data = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            if (file_put_contents($tmp, $data, LOCK_EX) !== strlen($data) || !rename($tmp, $this->path.'/'.$name.'.json')) throw new \RuntimeException('Systemzustand konnte nicht gespeichert werden.');
        } finally { if (is_file($tmp)) unlink($tmp); }
    }
    public function locked(callable $callback): mixed
    {
        $file = fopen($this->path . '/setup.lock', 'c');
        if (!$file) throw new \RuntimeException('Sperrdatei in storage/system kann nicht erstellt werden. Schreibrechte für den Web-PHP-Benutzer prüfen.');
        if (!flock($file, LOCK_EX | LOCK_NB)) { fclose($file); throw new \RuntimeException('Eine Einrichtung läuft bereits.'); }
        try { return $callback(); } finally { flock($file, LOCK_UN); fclose($file); }
    }
    public function identity(): array
    {
        return $this->locked(function (): array {
            $value = $this->read('installation');
            if ($value) {
                if (!is_string($value['id']??null) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$value['id']) || !is_string($value['key']??null) || !preg_match('/^[a-f0-9]{64}$/D',$value['key'])) throw new \RuntimeException('Systemdatei storage/system/installation.json ist unvollständig oder ungültig. Bei einer bestehenden Installation aus dem Backup wiederherstellen.');
                return $value;
            }
            $value = ['id'=>self::uuid(), 'key'=>bin2hex(random_bytes(32))];
            $this->write('installation', $value);
            return $value;
        });
    }
    public function issueSetupToken(): string
    {
        return $this->locked(function (): string {
            $token = bin2hex(random_bytes(24));
            $this->write('setup-token', ['hash'=>hash('sha256',$token), 'expires'=>time()+3600]);
            return $token;
        });
    }
    public function verifySetupToken(string $token): bool
    {
        $saved = $this->read('setup-token');
        return $saved && ($saved['expires'] ?? 0) >= time() && hash_equals($saved['hash'], hash('sha256',$token));
    }
}
