<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';

use O8\Core\{Config,Database,Runtime};
use O8\Inbound\AiJobs;
use O8\Install\Migrator;

try {
    if (getenv('O8_AI_WORKER')!=='1') throw new RuntimeException('Sicherheitsstopp: O8_AI_WORKER=1 muss ausdrücklich gesetzt sein.');
    if (($argv[1]??'')!=='work' || !in_array('--once',array_slice($argv,2),true)) throw new RuntimeException('Aufruf: O8_AI_WORKER=1 php bin/inbound-ai.php work --once');
    $root=dirname(__DIR__); $config=Config::load($root); if (!$config) throw new RuntimeException('Lokale Konfiguration fehlt.'); $db=Database::connect($config['database']); $runtime=new Runtime($root.'/storage/system'); $migrator=new Migrator($db,$root.'/database/migrations'); $identity=$runtime->identity();
    if (!$migrator->installed($identity) || !$migrator->current()) throw new RuntimeException('Installation oder Schemaaktualisierung fehlt.');
    $job=(new AiJobs($db,$root,$identity))->runNext(); if ($job===null) { echo "Kein KI-Auftrag wartet.\n"; exit; } printf("KI-Auftrag %d: %s · %d/%d · Fehler: %d\n",$job['id'],$job['status'],$job['completed_count'],$job['total_count'],$job['failed_count']); exit($job['status']==='failed'?1:0);
} catch (Throwable $error) { fwrite(STDERR,($error instanceof PDOException?'Datenbankaktion fehlgeschlagen.':$error->getMessage())."\n"); exit(1); }
