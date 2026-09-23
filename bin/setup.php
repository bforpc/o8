<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
use O8\Core\{Config,Runtime,Database};
use O8\Install\Migrator;
try {
    umask(0007);
    $root=dirname(__DIR__); $runtime=new Runtime($root.'/storage/system');
    $command=$argv[1]??'help';
    if ($command==='token') {
        echo "Einrichtungscode (60 Minuten gültig; nicht veröffentlichen):\n".$runtime->issueSetupToken()."\n";
    } elseif ($command==='status' || $command==='upgrade') {
        $config=Config::load($root);
        if (!$config) { echo "Konfiguration fehlt.\n"; exit(1); }
        $db=Database::connect($config['database']);
        $migrator=new Migrator($db,$root.'/database/migrations');
        if ($command==='upgrade') $migrator->upgrade($runtime->identity());
        echo 'Datenbank erreichbar. '.($migrator->installed($runtime->identity())?'Installation abgeschlossen.':'Webinstallation ausstehend.')."\n";
    } else {
        echo "php bin/setup.php token   – lokalen Nachweis für Webinstallation/Erstlogin erzeugen\nphp bin/setup.php status  – Datenbank und Installationsstatus prüfen\nphp bin/setup.php upgrade – ausstehende Schemaaktualisierungen anwenden (keine Neuinstallation)\n";
    }
} catch (Throwable $error) {
    // PDO errors can contain connection details; never print those.
    fwrite(STDERR,$error instanceof PDOException ? "Datenbankzugriff fehlgeschlagen. Zugang und Datenbank prüfen.\n" : $error->getMessage()."\n"); exit(1);
}
