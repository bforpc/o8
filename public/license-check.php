<?php
declare(strict_types=1);
// M1: zustandslose Signaturprüfung, KEINE Aktivierung/Autorisierung und kein DB-Schreiben.
// Tenant-/Installationsangaben hier nur Prüfparameter. In M2 aus der Session beziehen.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); echo '{"success":false,"error":"POST erforderlich."}'; exit; }
try {
    if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) throw new InvalidArgumentException('JSON erforderlich.');
    $raw = file_get_contents('php://input', false, null, 0, 32769);
    if ($raw === false || strlen($raw) > 32768) throw new InvalidArgumentException('Anfrage zu groß.');
    $request = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    foreach (['license','tenantId','installationId'] as $field) if (!is_string($request[$field] ?? null)) throw new InvalidArgumentException('Prüfparameter fehlen.');
    if (strlen($request['tenantId']) > 100 || strlen($request['installationId']) > 100) throw new InvalidArgumentException('Prüfparameter zu lang.');
    require dirname(__DIR__) . '/app/License/LicenseVerifier.php';
    $keys = require dirname(__DIR__) . '/config/license-public-keys.php';
    $result = (new O8\License\LicenseVerifier($keys))->verify($request['license'],$request['tenantId'],$request['installationId']);
    echo json_encode(['success'=>true,'data'=>$result],JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code(400);
    $message = $error instanceof InvalidArgumentException ? $error->getMessage() : 'Lizenzprüfung nicht möglich. Format und lokale Serverkonfiguration prüfen.';
    echo json_encode(['success'=>false,'error'=>$message],JSON_THROW_ON_ERROR);
}
