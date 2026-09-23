<?php
declare(strict_types=1);
namespace O8\Core;

final class Database
{
    public static function connect(array $config): \PDO
    {
        $db = Config::database($config);
        $pdo = new \PDO('mysql:host='.$db['host'].';port='.$db['port'].';dbname='.$db['name'].';charset=utf8mb4', $db['user'], $db['password'], [
            \PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES=>false, \PDO::ATTR_TIMEOUT=>5, \PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }
    public static function compatible(\PDO $pdo): string
    {
        $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $minimum = stripos($version,'MariaDB') !== false ? '10.6.0' : '8.0.0';
        if (!preg_match('/\d+\.\d+\.\d+/', $version, $match) || version_compare($match[0], $minimum, '<')) throw new \RuntimeException('Benötigt MariaDB 10.6+ oder MySQL 8.0+.');
        return $version;
    }
}
