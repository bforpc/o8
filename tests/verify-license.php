<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/License/LicenseVerifier.php';
if (PHP_SAPI !== 'cli') exit(1);
try {
    $input = json_decode($argv[1] ?? stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
    $result = (new O8\License\LicenseVerifier($input['keys']))->verify($input['raw'],$input['context']['tenantId'],$input['context']['installationId'],new DateTimeImmutable($input['now']));
    echo json_encode($result,JSON_THROW_ON_ERROR);
} catch (Throwable $error) { echo json_encode(['error'=>true]); }
