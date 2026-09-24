<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';

use O8\Core\{Config,Database,Runtime};
use O8\Documents\TrashRetention;
use O8\Install\Migrator;

try {
    $root=dirname(__DIR__); $config=Config::load($root);
    if (!$config) throw new RuntimeException('Lokale Konfiguration fehlt.');
    $db=Database::connect($config['database']); $runtime=new Runtime($root.'/storage/system');
    $migrator=new Migrator($db,$root.'/database/migrations');
    if (!$migrator->installed($runtime->identity()) || !$migrator->current()) throw new RuntimeException('Installation oder Schemaaktualisierung fehlt.');
    $count=(new TrashRetention($db,$root))->run();
    echo "Endgültig gelöschte Papierkorb-Dokumente: $count\n";
} catch (Throwable $error) {
    fwrite(STDERR,$error instanceof PDOException?'Datenbankaktion fehlgeschlagen.':$error->getMessage()."\n"); exit(1);
}
