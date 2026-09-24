<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/app/License/LicenseVerifier.php';
use O8\License\LicenseVerifier;
$context = json_decode($argv[1],true,16,JSON_THROW_ON_ERROR);
$pair = sodium_crypto_sign_keypair(); $secret = sodium_crypto_sign_secretkey($pair);
$payload = ['version'=>1,'key_id'=>'browser-test','license_id'=>'test-1','product'=>'o8','plan'=>'support','customer'=>'Testkunde','tenant_id'=>$context['tenantId'],'installation_id'=>$context['installationId'],'issued_at'=>'2020-01-01T00:00:00Z','support_until'=>'2099-12-31','features'=>[]];
$sign = static function (array $p) use ($secret): string {
    $bytes = json_encode($p,JSON_THROW_ON_ERROR);
    return json_encode(['payload'=>LicenseVerifier::encode($bytes),'signature'=>LicenseVerifier::encode(sodium_crypto_sign_detached($bytes,$secret))],JSON_THROW_ON_ERROR);
};
echo json_encode(['keys'=>['browser-test'=>LicenseVerifier::encode(sodium_crypto_sign_publickey($pair))],'valid'=>$sign($payload),'foreign'=>$sign([...$payload,'tenant_id'=>'another']),'expired'=>$sign([...$payload,'support_until'=>'2020-02-01'])],JSON_THROW_ON_ERROR);
sodium_memzero($secret); sodium_memzero($pair);
