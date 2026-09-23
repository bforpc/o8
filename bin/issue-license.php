<?php
declare(strict_types=1);
// Nur auf dem getrennten Aussteller-Rechner benutzen, NICHT auf Kundeninstallationen.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/License/LicenseVerifier.php';
use O8\License\LicenseVerifier;
if ($argc !== 3) { fwrite(STDERR, "Verwendung: php bin/issue-license.php private-key.base64url payload.json\nPrivater Ed25519-Schlüssel: 64 Bytes, Base64url, sicher außerhalb des Projekts aufbewahren.\n"); exit(1); }
try {
    $secret = LicenseVerifier::decode(trim((string) file_get_contents($argv[1])));
    if (strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new RuntimeException('Ungültige Schlüssellänge.');
    $payload = (string) file_get_contents($argv[2]);
    $data = json_decode($payload,true,16,JSON_THROW_ON_ERROR);
    $license = json_encode(['payload'=>LicenseVerifier::encode($payload),'signature'=>LicenseVerifier::encode(sodium_crypto_sign_detached($payload,$secret))],JSON_THROW_ON_ERROR);
    $public = LicenseVerifier::encode(sodium_crypto_sign_publickey_from_secretkey($secret));
    sodium_memzero($secret);
    (new LicenseVerifier([$data['key_id']=>$public]))->verify($license,$data['tenant_id'],$data['installation_id']);
    fwrite(STDOUT,$license . PHP_EOL);
} catch (Throwable $error) { fwrite(STDERR,"Lizenz konnte nicht erstellt werden. Eingaben und Schlüssel lokal prüfen.\n"); exit(1); }
