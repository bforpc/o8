<?php
// Lokaler Entwicklungsserver: php -S 127.0.0.1:18088 router.php
declare(strict_types=1);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}
$file = realpath(__DIR__ . $path);
$assetRoot = realpath(__DIR__ . '/public/assets') . DIRECTORY_SEPARATOR;
if (str_starts_with($path, '/public/assets/') && $file && str_starts_with($file, $assetRoot) && is_file($file)) {
    return false;
}
http_response_code(404);
echo 'Nicht gefunden';
