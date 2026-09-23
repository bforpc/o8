<?php
declare(strict_types=1);
$publicPath = $publicPath ?? '.';
if (($_GET['demo'] ?? '') !== '1') {
    require dirname(__DIR__) . '/app/foundation.php';
    return;
}
$config = require dirname(__DIR__) . '/config/app.php';
$publicPath = $publicPath ?? '.';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
function asset(string $path): string {
    global $publicPath;
    $version = filemtime(__DIR__ . '/assets/' . $path);
    return htmlspecialchars($publicPath . '/assets/' . $path . '?v=' . $version, ENT_QUOTES, 'UTF-8');
}
require dirname(__DIR__) . '/app/views/workspace.php';
