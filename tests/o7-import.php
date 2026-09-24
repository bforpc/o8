<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/tools/O7Import.php';

use O8\OneTimeMigration\{O7ImportOptions,O7ImportRules,O7ImportSafety};
use O8\Documents\Documents;

$checks=0;
function check(bool $condition,string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); ++$checks; }
function rejected(callable $call,string $message): void { try { $call(); } catch (RuntimeException) { check(true,$message); return; } throw new RuntimeException($message); }

$dry=O7ImportOptions::fromArgv(['o7-import.php','--dry-run']);
check($dry->dryRun && !$dry->resetAndImport,'Dry-Run is parsed without destructive mode.');
rejected(static fn()=>O7ImportOptions::fromArgv(['o7-import.php','--reset-and-import']),'Destructive mode without confirmation is rejected.');
rejected(static fn()=>O7ImportOptions::fromArgv(['o7-import.php','--dry-run','--reset-and-import','--confirm=RESET-SILENTSUN-AND-IMPORT-O7']),'Dry-Run and reset cannot be combined.');
rejected(static fn()=>O7ImportOptions::fromArgv(['o7-import.php','--dry-run','--backup-dir=/tmp']),'The removed backup option is rejected.');
check(O7ImportSafety::exactlyOne([['id'=>3]],'must be unique')['id']===3,'Exactly one target is accepted.');
rejected(static fn()=>O7ImportSafety::exactlyOne([],'missing target'),'Missing target is rejected.');
rejected(static fn()=>O7ImportSafety::exactlyOne([['id'=>1],['id'=>2]],'duplicate target'),'Ambiguous target is rejected.');

check(O7ImportRules::yearFolder('2025-12-31')==='x-2025','2025 documents go to x-2025.');
check(O7ImportRules::yearFolder('2026-01-01')==='2026','2026 documents go to 2026.');
check(O7ImportRules::yearFolder(null)==='x-2025','Missing dates deliberately go to x-2025.');
check(O7ImportRules::folderNames('2025-12-31',true)===['x-2025'],'Fewosteuer before 2026 has no additional folder.');
check(O7ImportRules::folderNames('2026-01-01',true)===['2026','FeWo/Steuer'],'Fewosteuer in 2026 receives exactly two links.');
check(O7ImportRules::fewo('FEWOSTEUER') && O7ImportRules::tag('Steuer')===O7ImportRules::tag('steuer'),'Tags are case-insensitive.');
check(O7ImportRules::safeRelative(str_repeat('a',48).'.odt'),'ODT paths are safe managed originals.');
check(Documents::MIME_EXTENSIONS['application/vnd.oasis.opendocument.text']==='odt' && Documents::MIME_EXTENSIONS['text/plain']==='txt','ODT and TXT are explicit supported original types.');

echo "o7 import safety tests: $checks passed\n";
