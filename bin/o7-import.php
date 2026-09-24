<?php
declare(strict_types=1);
/* One-time administrative o7 -> o8 import. It is never loaded by the web UI. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/tools/O7Import.php';

use O8\OneTimeMigration\{O7ImportOptions,O7ImportRunner};

function o7Usage(): never
{
    fwrite(STDERR,"Usage:\n  php bin/o7-import.php --dry-run [--source-root=/absolute/o7/path]\n  php bin/o7-import.php --reset-and-import --confirm=RESET-SILENTSUN-AND-IMPORT-O7 [--source-root=/absolute/o7/path]\n");
    exit(2);
}

try {
    $options=O7ImportOptions::fromArgv($argv);
    $runner=new O7ImportRunner(dirname(__DIR__),$options);
    $plan=$runner->inspect();
    $count=$plan->counts;
    echo "o7 → o8 einmalige Migration (nur Mandant silentsun)\n";
    echo 'Zielmandant: '.$plan->tenant['name'].' (#'.$plan->tenant['id'].")\n";
    echo 'Zielbesitzer: '.$plan->owner['display_name'].' / jn (#'.$plan->owner['id'].")\n";
    foreach (['o7Found'=>'o7-Dokumente gefunden','o8Documents'=>'vorhandene o8-Dokumente','o8Tags'=>'vorhandene o8-Tags','importFiles'=>'zu importierende Dateien','pdf'=>'PDF','jpeg'=>'JPEG','png'=>'PNG','odt'=>'ODT','txt'=>'TXT','upTo2025'=>'bis 31.12.2025','from2026'=>'ab 01.01.2026','fewo2026'=>'2026 mit Fewosteuer','tags'=>'unterschiedliche Tags','missingFiles'=>'fehlende Quelldateien','unsupportedFiles'=>'nicht unterstützte Dateitypen','skippedBinaryFiles'=>'bewusst übersprungene Binärdateien','oversizedFiles'=>'Dateien über o8-Limit (1 GiB)','invalidFiles'=>'ungültige unterstützte Dateien','invalidDocuments'=>'ungültige/fehlende Dokumentdaten','ambiguous'=>'sonstige nicht eindeutige Datensätze','missingDate'=>'ohne gültiges Dokumentdatum','bookingImported'=>'eindeutig übernehmbare Buchungsdaten','bookingSkipped'=>'übersprungene Buchungsdaten','ocrImported'=>'übernehmbarer OCR-Text','ocrSkipped'=>'nicht übernehmbarer OCR-Text'] as $key=>$label) echo $label.': '.(int)($count[$key]??0)."\n";
    if (isset($count['storageFree'],$count['storageRequired'])) echo 'Geprüfter freier Storage: '.number_format($count['storageFree']/1048576,1,',','.').' MiB (mindestens '.number_format($count['storageRequired']/1048576,1,',','.')." MiB erforderlich)\n";
    echo "Geplante Zielordner:\n";
    $folderCounts=[]; foreach ($plan->documents as $document) foreach ($document['folderNames'] as $folder) $folderCounts[$folder]=($folderCounts[$folder]??0)+1;
    foreach (['2026','FeWo','FeWo/Steuer','Steuer Jan','Steuer CC','Bank','Verträge','Behörden','KFZ','Haus','x-2025'] as $folder) echo '  '.$folder.': '.($folderCounts[$folder]??0)."\n";
    foreach ($plan->problems as $name=>$number) if ($number) echo 'BLOCKIEREND '.$name.': '.$number."\n";
    foreach ($plan->warnings as $name=>$number) if ($number) echo 'Hinweis '.$name.': '.$number."\n";
    if ($options->dryRun) { echo $plan->valid()?"Dry-Run erfolgreich: Es wurde nichts verändert.\n":"Dry-Run mit Problemen: Es wurde nichts verändert.\n"; exit($plan->valid()?0:1); }
    if (!$plan->valid()) throw new RuntimeException('Import nicht gestartet: Preflight enthält blockierende Probleme.');
    $result=$runner->import($plan);
    echo 'Importiert: '.$result['imported']." Dokumente\n";
    echo 'Bewusst übersprungene Binärdateien: '.$result['skipped']."\n";
    echo 'Neu angelegte Tags: '.$result['tags']."; Tag-Verknüpfungen: ".$result['tagLinks']."\n";
    echo "Konsistenzprüfungen erfolgreich.\n";
} catch (Throwable $error) {
    fwrite(STDERR,$error->getMessage()."\n");
    exit(1);
}
