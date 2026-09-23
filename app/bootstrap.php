<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'O8\\')) return;
    $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, 3)) . '.php';
    if (is_file($path)) require $path;
});
