<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';

use O8\Core\{Config,Database,Runtime};
use O8\Inbound\RemoteFetchJobs;
use O8\Install\Migrator;

try {
    if (getenv('O8_SOURCE_WORKER')!=='1') throw new RuntimeException('Sicherheitsstopp: O8_SOURCE_WORKER=1 muss ausdrücklich gesetzt sein.');
    if (($argv[1]??'')!=='work' || !in_array('--once',array_slice($argv,2),true)) throw new RuntimeException('Aufruf: O8_SOURCE_WORKER=1 php bin/source-fetch.php work --once');
    $root=dirname(__DIR__); $config=Config::load($root); if (!$config) throw new RuntimeException('Lokale Konfiguration fehlt.');
    $db=Database::connect($config['database']); $runtime=new Runtime($root.'/storage/system'); $migrator=new Migrator($db,$root.'/database/migrations');
    if (!$migrator->installed($runtime->identity()) || !$migrator->current()) throw new RuntimeException('Installation oder Schemaaktualisierung fehlt.');
    $result=(new RemoteFetchJobs($db,$root,$runtime->identity()))->runNext();
    $acceptance=(new \O8\Inbound\AutomaticAcceptance($db,$root))->runNext();
    if ($acceptance!==null) printf("Automatische Übernahme Eingang %d: %s%s\n",$acceptance['id'],$acceptance['status'],isset($acceptance['documentId'])?' · D'.$acceptance['documentId']:' · '.$acceptance['message']);
    if ($result===null) { echo "Kein Abrufauftrag wartet.\n"; exit; }
    printf("Auftrag %d: %s · Fehler: %d%s\n",$result['id'],$result['status'],$result['failed'],$result['warning']?' · Hinweis: '.$result['warning']:'');
    exit($result['status']==='completed'?0:1);
} catch (Throwable $error) { fwrite(STDERR,($error instanceof PDOException?'Datenbankaktion fehlgeschlagen.':$error->getMessage())."\n"); exit(1); }
