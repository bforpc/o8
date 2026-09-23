<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';

use O8\Auth\Actor;
use O8\Core\{Config,Database,Runtime};
use O8\Inbound\InboundScanner;
use O8\Install\Migrator;

function inboundUsage(): never {
    fwrite(STDERR,"Usage:\n  O8_INBOUND_SCAN=1 php bin/inbound.php list --tenant=ID\n  O8_INBOUND_SCAN=1 php bin/inbound.php scan --tenant=ID --source=ID\n"); exit(2);
}
function inboundOption(string $name): ?string { global $argv; foreach ($argv as $argument) if (str_starts_with($argument,'--'.$name.'=')) return substr($argument,strlen($name)+3); return null; }

try {
    if (getenv('O8_INBOUND_SCAN')!=='1') throw new RuntimeException('Sicherheitsstopp: O8_INBOUND_SCAN=1 muss ausdrücklich gesetzt sein.');
    $command=$argv[1]??''; $tenant=inboundOption('tenant'); $source=inboundOption('source');
    if (!in_array($command,['list','scan'],true) || !ctype_digit((string)$tenant) || (int)$tenant<1 || ($command==='scan' && (!ctype_digit((string)$source) || (int)$source<1))) inboundUsage();
    $root=dirname(__DIR__); $config=Config::load($root); if (!$config) throw new RuntimeException('Lokale Konfiguration fehlt.');
    $db=Database::connect($config['database']); $runtime=new Runtime($root.'/storage/system'); $migrator=new Migrator($db,$root.'/database/migrations');
    if (!$migrator->installed($runtime->identity()) || !$migrator->current()) throw new RuntimeException('Installation oder Schemaaktualisierung fehlt.');
    $stmt=$db->prepare("SELECT u.*,a.id AS account_id,a.auth_version AS account_auth_version,a.must_change_password,t.name AS tenant_name,t.public_id AS tenant_uuid FROM users u JOIN accounts a ON a.id=u.account_id JOIN tenants t ON t.id=u.tenant_id WHERE u.tenant_id=? AND u.active=1 AND a.active=1 AND a.must_change_password=0 ORDER BY CASE u.role WHEN 'admin' THEN 0 ELSE 1 END,u.id LIMIT 1");
    $stmt->execute([(int)$tenant]); $row=$stmt->fetch(); if (!$row) throw new RuntimeException('Mandant benötigt einen aktiven Benutzer mit abgeschlossenem Erstlogin.');
    $actor=new Actor('tenant',$row); $scanner=new InboundScanner($db);
    if ($command==='list') {
        printf("%-6s %-8s %-24s %-24s %s\n",'ID','Status','Name','Besitzer','Pfad');
        foreach ($scanner->sources($actor) as $item) printf("%-6d %-8s %-24s %-24s %s\n",$item['id'],$item['enabled']?'aktiv':'inaktiv',mb_strimwidth($item['name'],0,24,'…'),mb_strimwidth($item['owner_name'],0,24,'…'),$item['path']);
        exit;
    }
    $result=$scanner->scan($actor,(int)$source);
    printf("Geprüft: %d · bereit: %d · Duplikate: %d · ungültig: %d\n",$result['count'],$result['counts']['pending'],$result['counts']['duplicate'],$result['counts']['invalid']);
    foreach ($result['items'] as $item) printf("%-10s %s%s%s\n",$item['status'],$item['name'],$item['matchedTags']?' · Tags: '.implode(', ',array_column($item['matchedTags'],'name')):'',$item['ignoredTags']?' · ignoriert: '.implode(', ',$item['ignoredTags']):'');
} catch (Throwable $error) { fwrite(STDERR,($error instanceof PDOException?'Datenbankaktion fehlgeschlagen.':$error->getMessage())."\n"); exit(1); }
