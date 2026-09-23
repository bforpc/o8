<?php
declare(strict_types=1);
namespace O8\License;

// Reiner Offline-Prüfer für M2, noch keine HTTP-API oder Berechtigungsentscheidung.
final class LicenseVerifier
{
    public static function decode(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $value)) throw new \InvalidArgumentException('Ungültige Lizenzkodierung.');
        $bytes = base64_decode(strtr($value, '-_', '+/'), true);
        if ($bytes === false || self::encode($bytes) !== $value) throw new \InvalidArgumentException('Ungültige Lizenzkodierung.');
        return $bytes;
    }
    public static function encode(string $bytes): string { return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); }
    public function __construct(private readonly array $publicKeys) {}
    public function verify(string $raw, string $tenantId, string $installationId, ?\DateTimeImmutable $now = null): array
    {
        if (strlen($raw) > 16384) throw new \InvalidArgumentException('Lizenzdatei zu groß.');
        $envelope = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($envelope) || !is_string($envelope['payload'] ?? null) || !is_string($envelope['signature'] ?? null)) throw new \InvalidArgumentException('Ungültige Lizenzdatei.');
        $bytes = self::decode($envelope['payload']);
        $p = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($p) || !is_string($p['key_id'] ?? null) || !array_key_exists($p['key_id'], $this->publicKeys)) throw new \InvalidArgumentException('Unbekannter Aussteller.');
        $key = self::decode($this->publicKeys[$p['key_id']]); $signature = self::decode($envelope['signature']);
        if (strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($signature, $bytes, $key)) throw new \InvalidArgumentException('Ungültige Signatur.');
        if (($p['version'] ?? null) !== 1 || ($p['product'] ?? null) !== 'o8' || ($p['plan'] ?? null) !== 'support'
            || !is_string($p['license_id'] ?? null) || !preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $p['license_id'])
            || !is_string($p['customer'] ?? null) || !trim($p['customer']) || preg_match('/[\x00-\x1f]/', $p['customer'])
            || preg_match_all('/./us', $p['customer']) > 190
            || !is_string($p['issued_at'] ?? null) || !is_string($p['support_until'] ?? null)
            || ($p['features'] ?? null) !== [] || !is_array(json_decode($bytes)->features ?? null)) throw new \InvalidArgumentException('Nicht unterstützter Lizenzinhalt.');
        $utc = new \DateTimeZone('UTC'); $now ??= new \DateTimeImmutable('now', $utc);
        $issued = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $p['issued_at'], $utc);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $p['support_until'], $utc);
        if (!$issued || !$end || $issued->format('Y-m-d\TH:i:s\Z') !== $p['issued_at'] || $end->format('Y-m-d') !== $p['support_until']
            || $issued > $now || $issued >= $end->modify('+1 day')) throw new \InvalidArgumentException('Ungültiger Lizenzzeitraum.');
        if (($p['tenant_id'] ?? null) !== $tenantId || ($p['installation_id'] ?? null) !== $installationId) throw new \InvalidArgumentException('Lizenz gehört zu einem anderen Mandanten oder einer anderen Installation.');
        return ['payload'=>$p, 'status'=>$now < $end->modify('+1 day') ? 'active' : 'expired'];
    }
}
