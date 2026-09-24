<?php
declare(strict_types=1);

/*
 * One-time administrative helper for a deliberately isolated o7 -> o8 import.
 * It is loaded only by bin/o7-import.php and is not part of the web application.
 */
namespace O8\OneTimeMigration;

use O8\Auth\Actor;
use O8\Core\{Config, Database, Runtime};
use O8\Documents\Documents;
use O8\Install\Migrator;
use O8\Storage\Storage;

final class O7ImportOptions
{
    public function __construct(
        public readonly bool $dryRun,
        public readonly bool $resetAndImport,
        public readonly ?string $sourceRoot,
    ) {}

    public static function fromArgv(array $argv): self
    {
        $arguments=array_slice($argv,1);
        $dry=in_array('--dry-run',$arguments,true);
        $reset=in_array('--reset-and-import',$arguments,true);
        $allowed=['--dry-run','--reset-and-import'];
        $source=null; $confirmation=null;
        foreach ($arguments as $argument) {
            if (str_starts_with($argument,'--source-root=')) { $source=substr($argument,14); continue; }
            if (str_starts_with($argument,'--confirm=')) { $confirmation=substr($argument,10); continue; }
            if (!in_array($argument,$allowed,true)) throw new \RuntimeException('Unbekannter Parameter.');
        }
        if ($dry===$reset) throw new \RuntimeException('Genau --dry-run oder --reset-and-import angeben.');
        if ($dry && $confirmation!==null) throw new \RuntimeException('Dry-Run akzeptiert keine Bestätigung.');
        if ($reset && !hash_equals('RESET-SILENTSUN-AND-IMPORT-O7',(string)$confirmation)) throw new \RuntimeException('Sicherheitsstopp: Exakte Bestätigung fehlt.');
        foreach (['source'=>$source] as $name=>$path) {
            if ($path!==null && ($path==='' || $path[0]!=='/' || str_contains($path,"\0"))) throw new \RuntimeException('Ungültiger '.$name.'-Pfad.');
        }
        return new self($dry,$reset,$source);
    }
}

final class O7ImportRules
{
    public const FOLDERS=[
        ['2026',null], ['FeWo',null], ['Steuer','FeWo'], ['Steuer Jan',null], ['Steuer CC',null],
        ['Bank',null], ['Verträge',null], ['Behörden',null], ['KFZ',null], ['Haus',null], ['x-2025',null],
    ];

    public static function tag(string $name): string { return mb_strtolower(trim($name),'UTF-8'); }
    public static function fewo(string $name): bool { return self::tag($name)==='fewosteuer'; }
    public static function date(?string $value): ?string
    {
        if (!is_string($value) || $value==='') return null;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        return $date && $date->format('Y-m-d')===$value ? $value : null;
    }
    /** Missing or invalid dates intentionally follow the explicit legacy rule: x-2025. */
    public static function yearFolder(?string $date): string { return $date!==null && $date>='2026-01-01' ? '2026' : 'x-2025'; }
    public static function folderNames(?string $date,bool $fewosteuer): array
    {
        $year=self::yearFolder($date);
        return $year==='2026' && $fewosteuer ? ['2026','FeWo/Steuer'] : [$year];
    }
    public static function documentType(mixed $value): string
    {
        $type=mb_strtolower(trim(is_scalar($value)?(string)$value:''),'UTF-8');
        return ['rechnung'=>'invoice','invoice'=>'invoice','gutschrift'=>'credit_note','credit_note'=>'credit_note',
            'vertrag'=>'contract','contract'=>'contract','bescheinigung'=>'certificate','certificate'=>'certificate'][$type]??'document';
    }
    public static function scalar(array $data,array $path): string
    {
        foreach ($path as $key) { if (!is_array($data) || !array_key_exists($key,$data)) return ''; $data=$data[$key]; }
        return is_scalar($data)?trim((string)$data):'';
    }
    public static function sourcePath(array $file): ?string
    {
        $path=(string)($file['path_filename']??'');
        if ($path==='' || $path[0]!=='/') return null;
        $real=realpath($path);
        if ($real===false || is_link($path) || !is_file($real) || !is_readable($real)) return null;
        return $real;
    }
    public static function safeRelative(string $relative): bool
    {
        return (bool)preg_match('/^[a-f0-9]{48}\.(?:pdf|jpg|png|odt|json|txt)$/D',$relative);
    }
}

final class O7ImportPlan
{
    /** @param array<int,array<string,mixed>> $documents */
    public function __construct(
        public array $tenant,
        public array $owner,
        public array $location,
        public string $storageTarget,
        public string $sourceRoot,
        public array $documents,
        public array $tagNames,
        public array $counts,
        public array $problems,
        public array $warnings,
        public array $sourceItemLinks,
        public array $existingFiles,
    ) {}

    public function blockers(): int { return array_sum($this->problems); }
    public function valid(): bool { return $this->blockers()===0; }
}

/** Small guard for target identities used by the isolated CLI workflow. */
final class O7ImportSafety
{
    public static function exactlyOne(array $rows,string $message): array
    {
        if (count($rows)!==1) throw new \RuntimeException($message);
        return $rows[0];
    }
}

final class O7ImportRunner
{
    private ?\PDO $db=null;
    private ?\PDO $o7=null;
    private ?Storage $storage=null;
    private ?Documents $documents=null;
    private ?Actor $actor=null;

    public function __construct(private readonly string $root,private readonly O7ImportOptions $options) {}

    public function inspect(): O7ImportPlan
    {
        [$db,$tenant,$owner,$actor,$location,$target]=$this->target();
        $sourceRoot=$this->sourceRoot(); $source=$this->source($sourceRoot);
        $this->requireO7Schema($source);
        $existingFiles=$this->existingFiles($tenant['id'],$target);
        $sourceLinks=$this->sourceItemLinks($tenant['id']);
        $this->checkSourceTagReferences((int)$tenant['id']);

        $tags=[];
        foreach ($source->query('SELECT tag_id,name FROM tag ORDER BY tag_id') as $row) {
            $name=trim((string)$row['name']);
            if ($name==='') continue;
            $tags[(int)$row['tag_id']]=$name;
        }
        $accountIds=[];
        $s=$db->prepare('SELECT id,code FROM accounting_accounts WHERE tenant_id=? AND active=1'); $s->execute([$tenant['id']]);
        foreach ($s->fetchAll() as $row) $accountIds[(string)$row['code']]=(int)$row['id'];
        $bookings=[];
        foreach ($source->query('SELECT accounting_id,dms_id,konto,gegenkonto,buchungstext,waehrung,netto,mwst,brutto,belegdatum,buchungsdatum,leistungsdatum,steuersatz,typ,status FROM accounting ORDER BY dms_id,accounting_id') as $row) $bookings[(int)$row['dms_id']][]=$row;
        $ocrLengths=[];
        foreach ($source->query("SELECT file_id,OCTET_LENGTH(ocr) AS bytes FROM file WHERE ocr IS NOT NULL AND ocr<>''") as $row) $ocrLengths[(int)$row['file_id']]=(int)$row['bytes'];

        $counts=['o7Found'=>0,'o7Deleted'=>0,'o8Documents'=>$this->count('documents',(int)$tenant['id']),'o8Tags'=>$this->count('tags',(int)$tenant['id']),
            'importFiles'=>0,'pdf'=>0,'jpeg'=>0,'png'=>0,'odt'=>0,'txt'=>0,'upTo2025'=>0,'from2026'=>0,'fewo2026'=>0,'tags'=>0,'missingFiles'=>0,'unsupportedFiles'=>0,'skippedBinaryFiles'=>0,'oversizedFiles'=>0,'invalidFiles'=>0,
            'invalidDocuments'=>0,'ambiguous'=>0,'missingDate'=>0,'bookingImported'=>0,'bookingSkipped'=>0,'ocrImported'=>0,'ocrSkipped'=>0,'sourceLinksCleared'=>count($sourceLinks)];
        $problems=['missingFiles'=>0,'unsupportedFiles'=>0,'oversizedFiles'=>0,'invalidFiles'=>0,'invalidDocuments'=>0,'ambiguous'=>0,'sourceTagSettings'=>0,'activeJobs'=>0,'storageFiles'=>0,'storageSpace'=>0];
        $warnings=['invalidAiJson'=>0,'o7AiMetadataPartiallyMapped'=>0,'titleTruncated'=>0,'memoTruncated'=>0,'ocrTooLarge'=>0,'bookingMultipleRows'=>0,'bookingUnmappedAccount'=>0,'bookingUnsupportedFields'=>0,'deletedO7'=>0];
        $documents=[]; $tagNames=[];
        $sql='SELECT d.dms_id,d.file_id,d.description,d.memo,d.deleted,d.expired,d.searchable,d.date_document,d.date_remind,d.json_ai_info,d.json_tag_ids,d.created_at,'
            .'f.path_filename,f.filename,f.org_filename,f.mime_type,f.checksum FROM dms d LEFT JOIN file f ON f.file_id=d.file_id ORDER BY d.dms_id';
        foreach ($source->query($sql) as $row) {
            ++$counts['o7Found'];
            if ((int)$row['deleted']!==0) { ++$counts['o7Deleted']; ++$warnings['deletedO7']; continue; }
            $id=(int)$row['dms_id']; $date=O7ImportRules::date($row['date_document']===null?null:(string)$row['date_document']);
            if ($date===null) ++$counts['missingDate'];
            if ($date!==null && $date>='2026-01-01') ++$counts['from2026']; else ++$counts['upTo2025'];
            $tagIds=json_decode((string)($row['json_tag_ids']??''),true);
            $docTags=[]; $badTag=!is_array($tagIds);
            if (is_array($tagIds)) {
                foreach ($tagIds as $tagId) {
                    if (!is_int($tagId) && !(is_string($tagId) && ctype_digit($tagId))) { $badTag=true; break; }
                    $tagId=(int)$tagId;
                    if ($tagId<1 || !isset($tags[$tagId])) { $badTag=true; break; }
                    $name=$tags[$tagId]; $normalized=O7ImportRules::tag($name);
                    if ($normalized==='' || mb_strlen($name)>190) { $badTag=true; break; }
                    $docTags[$normalized]=$name;
                }
            }
            $fewo=(bool)array_filter(array_keys($docTags),static fn(string $name):bool=>O7ImportRules::fewo($name));
            if ($fewo && $date!==null && $date>='2026-01-01') ++$counts['fewo2026'];
            $sourcePath=O7ImportRules::sourcePath($row);
            if ($sourcePath===null) { ++$counts['missingFiles']; ++$problems['missingFiles']; continue; }
            $file=$this->inspectSourceFile($sourcePath);
            if (!$file['valid']) {
                $reason=$file['reason'];
                if ($reason==='size') { ++$counts['oversizedFiles']; ++$problems['oversizedFiles']; }
                elseif ($reason==='type') { ++$counts['unsupportedFiles']; ++$counts['skippedBinaryFiles']; }
                else { ++$counts['invalidFiles']; ++$problems['invalidFiles']; }
                continue;
            }
            if ($badTag) { ++$counts['invalidDocuments']; ++$problems['invalidDocuments']; continue; }
            foreach ($docTags as $normalized=>$name) $tagNames[$normalized]??=$name;
            $ai=[]; $aiRaw=(string)($row['json_ai_info']??'');
            if ($aiRaw!=='') { try { $decoded=json_decode($aiRaw,true,64,JSON_THROW_ON_ERROR); if (is_array($decoded)) { $ai=$decoded; ++$warnings['o7AiMetadataPartiallyMapped']; } else ++$warnings['invalidAiJson']; } catch (\JsonException) { ++$warnings['invalidAiJson']; } }
            $original=trim((string)($row['org_filename']?:$row['filename']?:basename($sourcePath)));
            if ($original==='') $original='D'.$id.'.'.$file['extension'];
            $title=trim((string)($row['description']??'')); if ($title==='') $title=$original;
            if (mb_strlen($title)>255) { $title=mb_strimwidth($title,0,255,'','UTF-8'); ++$warnings['titleTruncated']; }
            $memo=(string)($row['memo']??''); if (mb_strlen($memo)>10000) { $memo=mb_strimwidth($memo,0,10000,'','UTF-8'); ++$warnings['memoTruncated']; }
            $sender=O7ImportRules::scalar($ai,['parteien','absender','firma']) ?: O7ImportRules::scalar($ai,['parteien','absender','name']);
            $reference=O7ImportRules::scalar($ai,['referenzen','rechnungsnummer']) ?: O7ImportRules::scalar($ai,['referenzen','vertragsnummer']) ?: O7ImportRules::scalar($ai,['referenzen','aktenzeichen']);
            if (mb_strlen($sender)>255) $sender=mb_strimwidth($sender,0,255,'','UTF-8');
            if (mb_strlen($reference)>255) $reference=mb_strimwidth($reference,0,255,'','UTF-8');
            $folderNames=O7ImportRules::folderNames($date,$fewo);
            $booking=$this->bookingPlan($bookings[$id]??[],$accountIds,$date,$sender,$reference,$warnings,$counts);
            $ocrBytes=$ocrLengths[(int)$row['file_id']]??0;
            $ocr=$ocrBytes>0 && $ocrBytes<=1048576;
            if ($ocr) ++$counts['ocrImported']; elseif ($ocrBytes>0) { ++$counts['ocrSkipped']; ++$warnings['ocrTooLarge']; }
            $documents[]=['sourceId'=>$id,'fileId'=>(int)$row['file_id'],'sourcePath'=>$sourcePath,'sourceHash'=>$file['sha256'],'size'=>$file['size'],'mime'=>$file['mime'],'originalName'=>$original,
                'title'=>$title,'sender'=>$sender,'reference'=>$reference,'memo'=>$memo,'date'=>$date,'remindAt'=>O7ImportRules::date($row['date_remind']===null?null:(string)$row['date_remind']),
                'documentType'=>O7ImportRules::documentType($ai['dokument']['dokumenttyp']??null),'expired'=>(int)$row['expired']!==0,'notSearchable'=>(int)$row['searchable']===0,
                'tags'=>array_keys($docTags),'folderNames'=>$folderNames,'fewo'=>$fewo,'booking'=>$booking,'ocr'=>$ocr,'ocrBytes'=>$ocr?$ocrBytes:0];
            ++$counts['importFiles']; ++$counts[$file['kind']];
        }
        $counts['tags']=count($tagNames);
        foreach ($this->sourceTagReferenceCounts((int)$tenant['id']) as $name=>$number) { $problems[$name]=$number; if ($number) $counts['ambiguous']+=$number; }
        $active=$this->activeJobs((int)$tenant['id']); $problems['activeJobs']=$active; $counts['ambiguous']+=$active;
        if ($existingFiles['errors']) { $problems['storageFiles']=$existingFiles['errors']; $counts['ambiguous']+=$existingFiles['errors']; }
        $space=$this->storageSpace($target,$documents);
        $counts['storageFree']=$space['free']; $counts['storageRequired']=$space['required'];
        if (!$space['sufficient']) { $problems['storageSpace']=1; ++$counts['ambiguous']; }
        return new O7ImportPlan($tenant,$owner,$location,$target,$sourceRoot,$documents,$tagNames,$counts,$problems,$warnings,$sourceLinks,$existingFiles['files']);
    }

    /** @return array{imported:int,tags:int,tagLinks:int,skipped:int,warnings:array<string,int>} */
    public function import(O7ImportPlan $plan): array
    {
        if (!$this->options->resetAndImport) throw new \RuntimeException('Sicherheitsstopp: Nur mit --reset-and-import möglich.');
        if (!$plan->valid()) throw new \RuntimeException('Preflight enthält Probleme. Reset und Import wurden nicht begonnen.');
        $this->lock((int)$plan->tenant['id']);
        $reset=false; $staged=[];
        try {
            // Rebuild immediately before the irreversible section to reject changed files, users or storage.
            $plan=$this->inspect();
            if (!$plan->valid()) throw new \RuntimeException('Preflight hat sich geändert oder enthält Probleme. Reset und Import wurden nicht begonnen.');

            // This only renames current files temporarily so the reset cannot leave a
            // database row pointing at a file. It creates no copy or recoverable backup.
            $staged=$this->stageFiles($plan);
            $this->resetDatabase($plan); $reset=true;

            $this->removeStaged($staged); $staged=[];
            [$folders,$tags]=$this->createFoldersAndTags($plan);
            $imported=0; $tagLinks=0;
            foreach ($plan->documents as $index=>$document) {
                $this->ensureSourceUnchanged($document);
                $this->importDocument($plan,$document,$folders,$tags);
                $tagLinks+=count($document['tags']); ++$imported;
                if (($index+1)%25===0 || $index+1===count($plan->documents)) fwrite(STDOUT,sprintf("Import: %d/%d Dokumente\n",$index+1,count($plan->documents)));
            }
            $this->verifyImport($plan,$folders,$tags);
            return ['imported'=>$imported,'tags'=>count($tags),'tagLinks'=>$tagLinks,'skipped'=>(int)$plan->counts['skippedBinaryFiles'],'warnings'=>$plan->warnings];
        } catch (\Throwable $error) {
            if (!$reset) { if ($staged) $this->restoreStaged($staged); throw $error; }
            foreach ($staged as $file) if (is_file($file['stage'])) @unlink($file['stage']);
            throw new \RuntimeException('Import fehlgeschlagen, nachdem der Dokumentbestand von silentsun zurückgesetzt wurde. Es wurde bewusst keine Sicherung erstellt. Ursache: '.$error->getMessage());
        } finally { $this->unlock((int)$plan->tenant['id']); }
    }

    private function target(): array
    {
        $config=Config::load($this->root); if (!$config) throw new \RuntimeException('Lokale o8-Konfiguration fehlt.');
        $system=$this->root.'/storage/system'; if (!is_dir($system) || is_link($system)) throw new \RuntimeException('o8-Systemverzeichnis fehlt oder ist unsicher.');
        $runtime=new Runtime($system); $identity=$runtime->read('installation'); if (!is_array($identity) || !is_string($identity['id']??null) || !is_string($identity['key']??null)) throw new \RuntimeException('o8-Installationsidentität fehlt.');
        $this->db=Database::connect($config['database']); $migrator=new Migrator($this->db,$this->root.'/database/migrations');
        if (!$migrator->installed($identity) || !$migrator->current()) throw new \RuntimeException('o8-Installation oder Schemaaktualisierung fehlt.');
        $s=$this->db->prepare("SELECT * FROM tenants WHERE active=1 AND LOWER(TRIM(name))=LOWER('silentsun')"); $s->execute(); $tenants=$s->fetchAll();
        $tenant=O7ImportSafety::exactlyOne($tenants,'Es muss exakt ein aktiver Zielmandant „silentsun“ existieren.');
        $s=$this->db->prepare("SELECT u.*,a.auth_version AS account_auth_version,a.must_change_password,a.active AS account_active,t.name AS tenant_name,t.public_id AS tenant_uuid FROM users u JOIN accounts a ON a.id=u.account_id JOIN tenants t ON t.id=u.tenant_id WHERE u.tenant_id=? AND LOWER(TRIM(a.login))=LOWER('jn')"); $s->execute([$tenant['id']]); $owners=$s->fetchAll();
        $owner=O7ImportSafety::exactlyOne($owners,'Benutzer „jn“ muss im Mandanten silentsun eindeutig existieren.');
        if (!(bool)$owner['active'] || !(bool)$owner['account_active'] || (bool)$owner['must_change_password'] || !in_array($owner['role'],['admin','user'],true)) throw new \RuntimeException('Benutzer „jn“ ist nicht aktiv oder nicht für den Dokumentzugriff bereit.');
        $this->actor=new Actor('tenant',$owner); $this->storage=new Storage($this->db,$this->root); $this->documents=new Documents($this->db,$this->root);
        $location=$this->storage->location($this->actor); if (!$location) throw new \RuntimeException('Für silentsun fehlt eine geprüfte Dokumentablage.');
        [, $target]=$this->storage->paths($location);
        return [$this->db,$tenant,$owner,$this->actor,$location,$target];
    }

    private function sourceRoot(): string
    {
        $candidate=$this->options->sourceRoot ?: (getenv('O7_IMPORT_SOURCE')?:$this->root.'/../o7');
        $real=realpath($candidate);
        if ($real===false || !is_dir($real) || is_link($candidate) || !is_file($real.'/config/app.config.php')) throw new \RuntimeException('o7-Quellinstallation fehlt oder ist nicht lesbar. --source-root=/absoluter/pfad angeben.');
        return $real;
    }

    private function source(string $sourceRoot): \PDO
    {
        $config=require $sourceRoot.'/config/app.config.php';
        $db=is_array($config)?($config['db_connect']??null):null;
        if (!is_array($db) || ($db['driver']??null)!=='mysql' || !is_string($db['host']??null) || !is_string($db['database']??null) || !is_string($db['username']??null) || !is_string($db['password']??null)) throw new \RuntimeException('o7-Datenbankkonfiguration ist unvollständig.');
        try {
            $this->o7=new \PDO('mysql:host='.$db['host'].';dbname='.$db['database'].';charset='.($db['charset']??'utf8mb4'),$db['username'],$db['password'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,\PDO::ATTR_EMULATE_PREPARES=>false]);
        } catch (\PDOException) { throw new \RuntimeException('o7-Datenquelle ist nicht erreichbar.'); }
        return $this->o7;
    }

    private function requireO7Schema(\PDO $source): void
    {
        $required=['dms'=>['dms_id','file_id','description','memo','deleted','expired','searchable','date_document','date_remind','json_ai_info','json_tag_ids'],
            'file'=>['file_id','path_filename','filename','org_filename','mime_type','ocr'],'tag'=>['tag_id','name'],'accounting'=>['accounting_id','dms_id','konto','gegenkonto','buchungstext','waehrung','netto','mwst','brutto','belegdatum','buchungsdatum','leistungsdatum','steuersatz','typ','status']];
        $s=$source->prepare('SELECT table_name,column_name FROM information_schema.columns WHERE table_schema=DATABASE()'); $s->execute(); $actual=[];
        foreach ($s->fetchAll() as $row) $actual[$row['table_name']][$row['column_name']]=true;
        foreach ($required as $table=>$columns) foreach ($columns as $column) if (!isset($actual[$table][$column])) throw new \RuntimeException('o7-Quellschema enthält '.$table.'.'.$column.' nicht.');
    }

    private function inspectSourceFile(string $path): array
    {
        if (is_link($path) || !is_file($path) || !is_readable($path)) return ['valid'=>false,'reason'=>'missing'];
        $size=filesize($path); if ($size===false || $size<1) return ['valid'=>false,'reason'=>'invalid'];
        if ($size>Documents::MAX_BYTES) return ['valid'=>false,'reason'=>'size'];
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path); $map=['application/pdf'=>['pdf','pdf'],'image/jpeg'=>['jpeg','jpg'],'image/png'=>['png','png'],'application/vnd.oasis.opendocument.text'=>['odt','odt'],'text/plain'=>['txt','txt']];
        if (!isset($map[$mime])) return ['valid'=>false,'reason'=>'type'];
        if ($mime==='application/pdf' && file_get_contents($path,false,null,0,5)!=='%PDF-') return ['valid'=>false,'reason'=>'invalid'];
        if (in_array($mime,['image/jpeg','image/png'],true)) { $image=@getimagesize($path); if (!$image || $image[0]*$image[1]>40000000) return ['valid'=>false,'reason'=>'invalid']; }
        if ($mime==='application/vnd.oasis.opendocument.text' && file_get_contents($path,false,null,0,4)!=="PK\x03\x04") return ['valid'=>false,'reason'=>'invalid'];
        if ($mime==='text/plain' && !Documents::isPlainTextFile($path)) return ['valid'=>false,'reason'=>'type'];
        $hash=hash_file('sha256',$path); if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D',$hash)) return ['valid'=>false,'reason'=>'invalid'];
        return ['valid'=>true,'size'=>(int)$size,'mime'=>$mime,'kind'=>$map[$mime][0],'extension'=>$map[$mime][1],'sha256'=>$hash];
    }

    private function bookingPlan(array $rows,array $accounts,?string $documentDate,string $sender,string $reference,array &$warnings,array &$counts): ?array
    {
        if (!$rows) return null;
        if (count($rows)!==1) { ++$warnings['bookingMultipleRows']; ++$counts['bookingSkipped']; return null; }
        $row=$rows[0]; $values=[];
        foreach (['netto'=>'net','mwst'=>'tax','brutto'=>'gross'] as $from=>$to) if ($row[$from]!==null && $row[$from]!=='') {
            $value=(string)$row[$from]; if (!preg_match('/^-?\d+(?:\.\d{1,4})?$/D',$value)) { ++$counts['bookingSkipped']; return null; } $values[$to]=$value;
        }
        if (!$values) { ++$counts['bookingSkipped']; return null; }
        $currency=strtoupper(trim((string)($row['waehrung']??'EUR'))); if (!preg_match('/^[A-Z]{3}$/D',$currency)) { ++$counts['bookingSkipped']; return null; }
        $code=trim((string)($row['konto']??'')); $accountId=$code!==''?($accounts[$code]??null):null; if ($code!=='' && $accountId===null) ++$warnings['bookingUnmappedAccount'];
        if (trim((string)($row['gegenkonto']??''))!=='' || trim((string)($row['buchungstext']??''))!=='' || trim((string)($row['leistungsdatum']??''))!=='' || trim((string)($row['buchungsdatum']??''))!=='') ++$warnings['bookingUnsupportedFields'];
        ++$counts['bookingImported'];
        return ['sender'=>$sender,'number'=>$reference,'date'=>$documentDate??'','currency'=>$currency,'accountId'=>$accountId===null?'':(string)$accountId,
            'net'=>$values['net']??null,'tax'=>$values['tax']??null,'gross'=>$values['gross']??null];
    }

    private function count(string $table,int $tenant): int { $s=$this->db->prepare('SELECT COUNT(*) FROM '.$table.' WHERE tenant_id=?'); $s->execute([$tenant]); return (int)$s->fetchColumn(); }

    private function existingFiles(int $tenant,string $target): array
    {
        $s=$this->db->prepare('SELECT relative_path,size_bytes,sha256 FROM document_files WHERE tenant_id=? ORDER BY id'); $s->execute([$tenant]); $files=[]; $errors=0;
        foreach ($s->fetchAll() as $row) {
            $relative=(string)$row['relative_path']; $path=$target.'/'.$relative;
            if (!O7ImportRules::safeRelative($relative) || is_link($path) || !is_file($path) || filesize($path)!==(int)$row['size_bytes']) { ++$errors; continue; }
            $hash=hash_file('sha256',$path); if (!is_string($hash) || ($row['sha256']!==null && !hash_equals((string)$row['sha256'],$hash))) { ++$errors; continue; }
            $files[]=['relative'=>$relative,'size'=>(int)$row['size_bytes'],'sha256'=>$hash];
        }
        return ['files'=>$files,'errors'=>$errors];
    }

    private function sourceItemLinks(int $tenant): array
    {
        $s=$this->db->prepare('SELECT id,document_id FROM source_items WHERE tenant_id=? AND document_id IS NOT NULL'); $s->execute([$tenant]); return $s->fetchAll();
    }

    private function sourceTagReferenceCounts(int $tenant): array
    {
        $s=$this->db->prepare('SELECT config_json FROM import_sources WHERE tenant_id=?'); $s->execute([$tenant]); $references=0;
        foreach ($s->fetchAll(\PDO::FETCH_COLUMN) as $json) {
            try { $config=json_decode((string)$json,true,32,JSON_THROW_ON_ERROR); } catch (\JsonException) { ++$references; continue; }
            $tags=$config['acceptance']['tags']??[]; if (is_array($tags) && $tags) ++$references;
        }
        return ['sourceTagSettings'=>$references];
    }
    private function checkSourceTagReferences(int $tenant): void { /* Counted in the read-only plan; destructive run refuses when non-zero. */ }
    private function activeJobs(int $tenant): int
    {
        $s=$this->db->prepare("SELECT COUNT(*) FROM background_jobs WHERE tenant_id=? AND status IN ('queued','running')"); $s->execute([$tenant]); return (int)$s->fetchColumn();
    }

    /** Space for new originals/OCR plus a small filesystem safety margin. */
    private function storageSpace(string $target,array $documents): array
    {
        $importBytes=array_sum(array_map(static fn(array $document):int=>(int)$document['size']+(int)($document['ocrBytes']??0),$documents));
        $margin=67108864;
        $required=$importBytes+$margin;
        $free=disk_free_space($target);
        if ($free===false) throw new \RuntimeException('Freier Storage kann nicht geprüft werden.');
        return ['free'=>(int)$free,'required'=>$required,'sufficient'=>$free>=$required];
    }

    private function lock(int $tenant): void
    {
        $name='o8.one-time-o7-import.'.$tenant; $s=$this->db->prepare('SELECT GET_LOCK(?,0)'); $s->execute([$name]); if ((int)$s->fetchColumn()!==1) throw new \RuntimeException('Ein o7-Import für diesen Mandanten läuft bereits.');
    }
    private function unlock(int $tenant): void { if ($this->db) { $s=$this->db->prepare('SELECT RELEASE_LOCK(?)'); $s->execute(['o8.one-time-o7-import.'.$tenant]); } }

    private function stageFiles(O7ImportPlan $plan): array
    {
        $token='.o7-reset-'.bin2hex(random_bytes(12)); $staged=[];
        try {
            foreach ($plan->existingFiles as $file) {
                $source=$plan->storageTarget.'/'.$file['relative']; $stage=$source.$token;
                if (file_exists($stage) || !@rename($source,$stage)) throw new \RuntimeException('Bestehende Dokumentdatei konnte nicht sicher vorbereitet werden.');
                $staged[]=['source'=>$source,'stage'=>$stage];
            }
            return $staged;
        } catch (\Throwable $error) { $this->restoreStaged($staged); throw $error; }
    }
    private function restoreStaged(array $staged): void { foreach (array_reverse($staged) as $file) if (is_file($file['stage']) && !@rename($file['stage'],$file['source'])) throw new \RuntimeException('Vorbereitete Dokumentdatei konnte nicht zurückbenannt werden.'); }
    private function removeStaged(array $staged): void { foreach ($staged as $file) if (is_file($file['stage']) && !@unlink($file['stage'])) throw new \RuntimeException('Zurückgesetzte Dokumentdatei konnte nicht entfernt werden.'); }

    private function resetDatabase(O7ImportPlan $plan): void
    {
        $tenant=(int)$plan->tenant['id']; $this->db->beginTransaction();
        try {
            $s=$this->db->prepare('UPDATE source_items SET document_id=NULL WHERE tenant_id=? AND document_id IS NOT NULL'); $s->execute([$tenant]);
            foreach (['invoice_items','invoice_tax_totals','accounting_entries','document_invoices','document_tags','folder_documents'] as $table) { $s=$this->db->prepare('DELETE FROM '.$table.' WHERE tenant_id=?'); $s->execute([$tenant]); }
            $s=$this->db->prepare('DELETE FROM document_files WHERE tenant_id=?'); $s->execute([$tenant]);
            $s=$this->db->prepare('DELETE FROM documents WHERE tenant_id=?'); $s->execute([$tenant]);
            $s=$this->db->prepare("DELETE FROM migration_map WHERE tenant_id=? AND entity_type='document'"); $s->execute([$tenant]);
            $s=$this->db->prepare("DELETE FROM audit_events WHERE tenant_id=? AND entity_type IN ('document','folder','tag')"); $s->execute([$tenant]);
            $folders=$this->folderOrder($tenant,false); foreach ($folders as $folder) { $s=$this->db->prepare('DELETE FROM folders WHERE tenant_id=? AND id=?'); $s->execute([$tenant,$folder['id']]); }
            $s=$this->db->prepare('DELETE FROM tags WHERE tenant_id=?'); $s->execute([$tenant]);
            foreach (['documents','folders','tags'] as $table) if ($this->count($table,$tenant)!==0) throw new \RuntimeException('Dokumentreset ist unvollständig.');
            $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }

    private function folderOrder(int $tenant,bool $parentsFirst): array
    {
        $s=$this->db->prepare('SELECT id,parent_id,name,owner_id,description,created_at FROM folders WHERE tenant_id=? ORDER BY id'); $s->execute([$tenant]); $rows=$s->fetchAll(); $byParent=[];
        foreach ($rows as $row) $byParent[$row['parent_id']===null?'root':(string)$row['parent_id']][]=$row;
        $ordered=[]; $walk=function(string $parent) use (&$walk,&$ordered,&$byParent): void { foreach ($byParent[$parent]??[] as $row) { $ordered[]=$row; $walk((string)$row['id']); } };
        $walk('root'); if (count($ordered)!==count($rows)) throw new \RuntimeException('Ordnerstruktur ist zyklisch oder beschädigt.');
        return $parentsFirst?$ordered:array_reverse($ordered);
    }

    private function createFoldersAndTags(O7ImportPlan $plan): array
    {
        $folders=[]; $tags=[]; $this->db->beginTransaction();
        try {
            $insertTag=$this->db->prepare('INSERT INTO tags (tenant_id,name,normalized_name,active) VALUES (?,?,?,1)');
            foreach ($plan->tagNames as $normalized=>$name) { $insertTag->execute([$plan->tenant['id'],$name,$normalized]); $tags[$normalized]=(int)$this->db->lastInsertId(); }
            $insertFolder=$this->db->prepare('INSERT INTO folders (tenant_id,parent_id,owner_id,name) VALUES (?,?,?,?)');
            foreach (O7ImportRules::FOLDERS as [$name,$parent]) { $parentId=$parent===null?null:($folders[$parent]??null); if ($parent!==null && $parentId===null) throw new \RuntimeException('Vorgegebene Ordnerstruktur ist ungültig.'); $insertFolder->execute([$plan->tenant['id'],$parentId,$plan->owner['id'],$name]); $folders[$parent===null?$name:$parent.'/'.$name]=(int)$this->db->lastInsertId(); }
            $audit=$this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id,details_json) VALUES (?,?,'o7.import.reset','migration','o7',?)");
            $audit->execute([$plan->tenant['id'],$plan->owner['id'],json_encode(['documents'=>count($plan->documents),'source'=>'o7'],JSON_THROW_ON_ERROR)]);
            $this->db->commit(); return [$folders,$tags];
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }

    private function ensureSourceUnchanged(array $document): void
    {
        $file=$this->inspectSourceFile($document['sourcePath']);
        if (!$file['valid'] || $file['size']!==$document['size'] || !hash_equals($document['sourceHash'],$file['sha256'])) throw new \RuntimeException('o7-Quelldatei wurde nach dem Preflight geändert oder ist nicht mehr gültig.');
    }

    private function importDocument(O7ImportPlan $plan,array $document,array $folders,array $tags): void
    {
        $sidecars=[]; $ocr='';
        if ($document['ocr']) { $ocr=$this->sourceOcr((int)$document['fileId']); if (strlen($ocr)>1048576) throw new \RuntimeException('o7-OCR-Text hat sich nach dem Preflight verändert.'); $sidecars['ocr_text']=$ocr; }
        $this->documents->ingest($this->actor,$document['sourcePath'],$document['originalName'],'upload',function(int $id) use($plan,$document,$folders,$tags,$ocr): void {
            $tagIds=[]; foreach ($document['tags'] as $normalized) { if (!isset($tags[$normalized])) throw new \RuntimeException('Import-Tag fehlt.'); $tagIds[]=$tags[$normalized]; }
            $this->documents->save($this->actor,$id,1,['title'=>$document['title'],'sender'=>$document['sender'],'reference'=>$document['reference'],'date'=>$document['date']??'','memo'=>$document['memo'],'documentType'=>$document['documentType'],'expired'=>$document['expired']?'1':'','notSearchable'=>$document['notSearchable']?'1':'','tags'=>$tagIds]);
            $revision=2;
            if ($document['booking']!==null) { $this->documents->savePartialInvoice($this->actor,$id,$revision++,$document['booking']); }
            foreach ($document['folderNames'] as $name) { if (!isset($folders[$name])) throw new \RuntimeException('Import-Ordner fehlt.'); $this->documents->link($this->actor,$id,$revision++,$folders[$name],false); }
            $s=$this->db->prepare('UPDATE documents SET remind_at=?,search_text=?,revision=revision+1 WHERE tenant_id=? AND id=?'); $s->execute([$document['remindAt'],$ocr===''?null:mb_scrub($ocr,'UTF-8'),$plan->tenant['id'],$id]);
            $s=$this->db->prepare("INSERT INTO migration_map (tenant_id,source_system,entity_type,source_id,target_id,source_metadata) VALUES (?,'o7','document',?,?,?)");
            $s->execute([$plan->tenant['id'],$document['sourceId'],$id,json_encode(['sha256'=>$document['sourceHash'],'source_file_id'=>$document['fileId']],JSON_THROW_ON_ERROR)]);
            $s=$this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id,details_json) VALUES (?,?,'document.imported.o7','document',?,?)");
            $s->execute([$plan->tenant['id'],$plan->owner['id'],(string)$id,json_encode(['o7_dms_id'=>$document['sourceId']],JSON_THROW_ON_ERROR)]);
        },$sidecars);
    }

    private function sourceOcr(int $fileId): string
    {
        $s=$this->o7->prepare('SELECT ocr FROM file WHERE file_id=?'); $s->execute([$fileId]); $value=$s->fetchColumn(); if (!is_string($value)) return ''; return mb_scrub($value,'UTF-8');
    }

    private function verifyImport(O7ImportPlan $plan,array $folders,array $tags): void
    {
        $tenant=(int)$plan->tenant['id'];
        if ($this->count('documents',$tenant)!==count($plan->documents)) throw new \RuntimeException('Konsistenzprüfung: Dokumentanzahl stimmt nicht.');
        $s=$this->db->prepare('SELECT COUNT(*) FROM documents WHERE tenant_id=? AND owner_id<>?'); $s->execute([$tenant,$plan->owner['id']]); if ($s->fetchColumn()) throw new \RuntimeException('Konsistenzprüfung: Dokumentbesitzer stimmt nicht.');
        $s=$this->db->prepare("SELECT COUNT(*) FROM folders WHERE tenant_id=?"); $s->execute([$tenant]); if ((int)$s->fetchColumn()!==count(O7ImportRules::FOLDERS)) throw new \RuntimeException('Konsistenzprüfung: Ordnerstruktur stimmt nicht.');
        $s=$this->db->prepare('SELECT id,name,parent_id FROM folders WHERE tenant_id=?'); $s->execute([$tenant]); $actualFolders=[]; foreach ($s->fetchAll() as $folder) $actualFolders[$folder['parent_id']===null?$folder['name']:(string)$folder['parent_id'].'/'.$folder['name']]=$folder;
        foreach (O7ImportRules::FOLDERS as [$name,$parent]) {
            if ($parent===null) { if (!isset($actualFolders[$name])) throw new \RuntimeException('Konsistenzprüfung: erwarteter Ordner fehlt.'); continue; }
            $parentRow=$actualFolders[$parent]??null; if (!$parentRow || !isset($actualFolders[(string)$parentRow['id'].'/'.$name])) throw new \RuntimeException('Konsistenzprüfung: erwarteter Unterordner fehlt.');
        }
        $s=$this->db->prepare("SELECT normalized_name,COUNT(*) c FROM tags WHERE tenant_id=? GROUP BY normalized_name HAVING c>1"); $s->execute([$tenant]); if ($s->fetch()) throw new \RuntimeException('Konsistenzprüfung: Tag-Dubletten gefunden.');
        $s=$this->db->prepare("SELECT COUNT(*) FROM migration_map WHERE tenant_id=? AND source_system='o7' AND entity_type='document'"); $s->execute([$tenant]); if ((int)$s->fetchColumn()!==count($plan->documents)) throw new \RuntimeException('Konsistenzprüfung: Migrationszuordnung fehlt.');
        foreach ($plan->documents as $document) {
            $s=$this->db->prepare("SELECT target_id FROM migration_map WHERE tenant_id=? AND source_system='o7' AND entity_type='document' AND source_id=?"); $s->execute([$tenant,$document['sourceId']]); $id=(int)$s->fetchColumn(); if ($id<1) throw new \RuntimeException('Konsistenzprüfung: importiertes Dokument fehlt.');
            $s=$this->db->prepare('SELECT f.relative_path,f.size_bytes,f.sha256 FROM document_files f WHERE f.tenant_id=? AND f.document_id=? AND f.role=\'original\''); $s->execute([$tenant,$id]); $file=$s->fetch(); $path=$plan->storageTarget.'/'.($file['relative_path']??'');
            if (!$file || !O7ImportRules::safeRelative($file['relative_path']) || !is_file($path) || filesize($path)!==(int)$file['size_bytes'] || !hash_equals($file['sha256'],(string)hash_file('sha256',$path))) throw new \RuntimeException('Konsistenzprüfung: importierte Originaldatei fehlt oder ist verändert.');
            $expected=array_map(static fn(string $name):int=>$folders[$name],$document['folderNames']); sort($expected); $s=$this->db->prepare('SELECT folder_id FROM folder_documents WHERE tenant_id=? AND document_id=? ORDER BY folder_id'); $s->execute([$tenant,$id]); $actual=array_map('intval',$s->fetchAll(\PDO::FETCH_COLUMN)); if ($actual!==$expected) throw new \RuntimeException('Konsistenzprüfung: Ordnerverknüpfung stimmt nicht.');
            $expectedTags=[]; foreach ($document['tags'] as $tag) $expectedTags[]=$tags[$tag]; sort($expectedTags); $s=$this->db->prepare('SELECT tag_id FROM document_tags WHERE tenant_id=? AND document_id=? ORDER BY tag_id'); $s->execute([$tenant,$id]); if (array_map('intval',$s->fetchAll(\PDO::FETCH_COLUMN))!==$expectedTags) throw new \RuntimeException('Konsistenzprüfung: Tag-Verknüpfung stimmt nicht.');
        }
    }

}
