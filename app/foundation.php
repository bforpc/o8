<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use O8\Core\{Config,Runtime,Database};
use O8\Install\Migrator;
use O8\Auth\{AuthService,BootstrapService,InvitationService};
use O8\Admin\Administration;
use O8\Admin\TenantDeletion;
use O8\Storage\Storage;
use O8\Documents\Documents;
use O8\Documents\TrashRetention;
use O8\Inbound\{SourceManager,SourceConnectionTester,RemoteFetchJobs,AiConfiguration,AiJobs};
use O8\Documents\Evaluation;

ini_set('display_errors','0');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
$root=dirname(__DIR__); $message=''; $error=''; $installed=false; $actor=null; $fatal=false; $tenants=[]; $users=[]; $appearancePreferences=null;
$section=is_string($_GET['section']??null) ? $_GET['section'] : '';
$deletionJob=null; $deletionReview=null; $deletionId=0;
$choices=[]; $schemaPending=false; $inviteCode=''; $importSources=[]; $sourceWorkerActive=false; $aiConfiguration=[]; $aiWorkerActive=false; $aiModels=[];
$operatorLogin=($_GET['login']??'')==='operator';
function h(mixed $value): string { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function field(string $key): string { return is_string($_POST[$key]??null) ? $_POST[$key] : ''; }
function postFields(string $action, string $section): void {
    echo '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'"><input type="hidden" name="action" value="'.h($action).'"><input type="hidden" name="section" value="'.h($section).'">';
    echo '<input type="hidden" name="context_token" value="'.h($_SESSION['actor']['context_token']??'').'">';
    if (($_GET['overlay']??'')==='1') echo '<input type="hidden" name="overlay" value="1">';
}
function confirmed(): void { if (field('confirm')!=='yes') throw new RuntimeException('Bitte die Änderung ausdrücklich bestätigen.'); }
function accountInput(): array {
    if (field('admin_mode')==='existing') {
        if (trim(field('existing_login'))==='') throw new RuntimeException('Bestehenden Login oder E-Mail-Adresse angeben.');
        return ['existing_login'=>field('existing_login')];
    }
    return ['login'=>field('login'),'name'=>field('display_name'),'email'=>field('email'),'password'=>field('temporary_password')];
}
function deletionReview(O8\Auth\Actor $actor, int $stage): array {
    $actor->requireOperator(); $review=$_SESSION['tenant_delete_review']??null;
    if (!$review || $review['stage']!==$stage || $review['operator']!==$actor->id() || $review['expires']<time() || !hash_equals($review['token'],field('delete_token')) || $review['id']!==(int)field('id')) throw new RuntimeException('Löschbestätigung fehlt oder ist abgelaufen. Bitte erneut beginnen.');
    return $review;
}
try {
    umask(0007);
    $config=Config::load($root);
    $secure=isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off';
    $loopback=in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true);
    if (!$secure && !$loopback && !($config['allow_insecure_http']??false)) throw new RuntimeException('Für Einrichtung und Anmeldung ist HTTPS erforderlich.');
    session_name('o8_session');
    ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
    $runtime=new Runtime($root.'/storage/system');
    $sessionDirectory=$runtime->path.'/sessions';
    if (is_link($sessionDirectory) || (!is_dir($sessionDirectory) && !mkdir($sessionDirectory,0700) && !is_dir($sessionDirectory)) || !is_writable($sessionDirectory) || (fileperms($sessionDirectory)&0077)!==0) throw new RuntimeException('Geschützte Sitzungsablage nicht verfügbar.');
    session_save_path($sessionDirectory);
    ini_set('session.gc_maxlifetime','604800');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    $_SESSION['csrf']??=bin2hex(random_bytes(32));
    $identity=$runtime->identity();
    $db=$config ? Database::connect($config['database']) : null;
    $migrator=$db ? new Migrator($db,$root.'/database/migrations') : null;
    $installed=$migrator ? $migrator->installed($identity) : false;
    if ($installed && !$migrator->current()) { $schemaPending=true; throw new RuntimeException('Schemaaktualisierung erforderlich.'); }
    $auth=$installed ? new AuthService($db,$runtime,$identity['key']) : null;
    $administration=$installed ? new Administration($db) : null;
    $sourceManager=$installed ? new SourceManager($db,$identity) : null;
    $aiManager=$installed ? new AiConfiguration($db,$identity) : null;
    $deletion=$installed ? new TenantDeletion($db,$root) : null;
    $hadSession=isset($_SESSION['actor']);
    $actor=$auth?->actor($_SESSION['actor']??null);
    if (!$actor) {
        unset($_SESSION['actor'],$_SESSION['tenant_delete_review'],$_SESSION['invite_code']);
        if ($hadSession) $error='Sitzung abgelaufen oder widerrufen. Bitte erneut anmelden.';
    }
    elseif ($actor->kind==='tenant') $_SESSION['actor']['last_activity']=time();
    if ($actor?->kind==='account' && isset($_SESSION['actor']['tenant_id'])) {
        unset($_SESSION['actor']['tenant_id'],$_SESSION['actor']['membership_id'],$_SESSION['actor']['membership_version'],$_SESSION['actor']['context_token']);
        $_SESSION['csrf']=bin2hex(random_bytes(32));
    }
    if (isset($_GET['api'])) require __DIR__.'/document-api.php';
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        try {
            if (!hash_equals($_SESSION['csrf'],field('csrf'))) throw new RuntimeException('Sitzung abgelaufen. Bitte die Seite neu laden.');
            $action=field('action');
            if (in_array($action,['user_create','user_update','user_invite','accounting_save','session_timeout_save','tag_create','tag_rename','tag_delete','trash_retention_save','source_save','source_delete','source_test','ai_configuration_save','ai_models_refresh'],true) && (!$actor || $actor->kind!=='tenant' || !hash_equals($_SESSION['actor']['context_token']??'',field('context_token')) || field('context_token')==='')) throw new RuntimeException('Mandantenkontext geändert. Bitte die Seite neu laden.');
            $deleteRedirect=0;
            if ($action==='install' && !$installed) {
                if (!$runtime->verifySetupToken(field('setup_token'))) throw new RuntimeException('Einrichtungscode fehlt, ist ungültig oder abgelaufen.');
                $runtime->locked(function () use (&$config,&$db,&$migrator,$root,$identity): void {
                    if (!$config) {
                        $config=['database'=>Config::database(['host'=>field('host'),'port'=>field('port'),'name'=>field('name'),'user'=>field('user'),'password'=>field('password')]),'timezone'=>'Europe/Berlin','allow_insecure_http'=>false];
                        $db=Database::connect($config['database']); Database::compatible($db);
                        if ($db->query('SHOW TABLES')->fetch()) throw new RuntimeException('Die Datenbank ist nicht leer. Keine Änderungen vorgenommen.');
                        Config::writeNew($root,$config);
                    }
                    $migrator=new Migrator($db,$root.'/database/migrations'); $migrator->install($identity);
                });
                $_SESSION['flash']='Installation abgeschlossen. Jetzt als Betreiber mit admin / owndms8 und demselben Einrichtungscode anmelden.';
            } elseif ($action==='login' && $installed && !$actor) {
                $loginSession=$auth->login(field('kind'),field('login'),field('password'),$_SERVER['REMOTE_ADDR']??'',field('setup_token'));
                $_SESSION=['actor'=>$loginSession];
                session_regenerate_id(true);
            } elseif ($action==='logout') {
                $_SESSION=[]; session_regenerate_id(true);
            } elseif ($action==='password' && $actor) {
                if (field('new_password')!==field('repeat_password')) throw new RuntimeException('Neue Passwörter stimmen nicht überein.');
                $_SESSION['actor']=$auth->changePassword($actor,field('old_password'),field('new_password')); session_regenerate_id(true);
                $_SESSION['flash']='Passwort geändert. Andere Anmeldungen dieses Kontos sind ungültig.';
            } elseif ($action==='tenant_switch' && $actor) {
                $next=$auth->switchTenant($actor,(int)field('tenant_id'),$_SESSION['actor']);
                $_SESSION=['actor'=>$next]; session_regenerate_id(true);
            } elseif ($action==='invitation_accept' && $actor) {
                $tenant=(new InvitationService($db))->accept($actor,field('invitation'));
                $next=$auth->switchTenant($actor,$tenant,$_SESSION['actor']);
                $_SESSION=['actor'=>$next,'flash'=>'Einladung angenommen. Mandant wurde geöffnet.']; session_regenerate_id(true);
            } elseif ($action==='bootstrap' && $actor) {
                $uuid=(new BootstrapService($db))->complete($actor,field('display_name'),field('email'),field('tenant_name'));
                $_SESSION['flash']='Erster Mandant eingerichtet. Normaler Benutzer-Login: admin oder Ihre E-Mail mit dem soeben gesetzten Passwort. Betreiber- und Benutzerkonto sind unabhängig.';
            } elseif ($action==='tenant_create' && $actor) {
                $uuid=$administration->createTenant($actor,field('tenant_name'),field('contact_email'),accountInput());
                $_SESSION['flash']=field('admin_mode')==='existing'?'Mandant angelegt und bestehendes Konto ausdrücklich als erster Admin zugeordnet.':'Mandant angelegt. Individuelles Startpasswort sicher mitteilen; beim Erstlogin muss es geändert werden.';
            } elseif ($action==='tenant_update' && $actor) {
                confirmed(); $administration->updateTenant($actor,(int)field('id'),field('tenant_name'),field('contact_email'),field('active')==='1');
                $_SESSION['flash']='Mandant gespeichert. Bei Statusänderung wurden seine Benutzersitzungen widerrufen.';
            } elseif ($action==='storage_configure' && $actor) {
                confirmed(); (new Storage($db,$root))->configure($actor,(int)field('tenant_id'),field('root_path'),field('linux_owner'),field('linux_group'),field('enforce_file_attributes')==='1');
                $_SESSION['flash']='Storage-Zuweisung geprüft und gespeichert. Bestehende Dateien wurden nicht verändert.';
            } elseif ($action==='storage_relocate' && $actor) {
                confirmed(); (new Storage($db,$root))->relocate($actor,(int)field('tenant_id'),field('root_path'));
                $_SESSION['flash']='Storage-Pfad geprüft und geändert. Dateien wurden nicht verschoben oder verändert.';
            } elseif ($action==='accounting_save' && $actor) {
                $rates=array_values(array_filter(array_map('trim',preg_split('/\R/u',field('vat_rates')))));
                $accounts=[]; foreach (preg_split('/\R/u',field('accounts')) as $line) { $line=trim($line); if ($line==='') continue; $parts=explode(';',$line,2); if (count($parts)!==2) throw new RuntimeException('Konten bitte als Nummer; Bezeichnung eingeben.'); $accounts[]=['code'=>trim($parts[0]),'name'=>trim($parts[1])]; }
                (new Documents($db,$root))->saveAccountingSettings($actor,['framework'=>field('framework'),'vatRates'=>$rates,'accounts'=>$accounts]);
                $_SESSION['flash']='Buchhaltungs-Einstellungen gespeichert.';
            } elseif ($action==='session_timeout_save' && $actor) {
                $auth->saveSessionMinutes($actor,field('minutes'));
                $_SESSION['flash']='Sitzungsdauer für diesen Mandanten gespeichert.';
            } elseif ($action==='tag_create' && $actor) {
                (new Documents($db,$root))->createTag($actor,field('name'));
                $_SESSION['flash']='Tag angelegt.';
            } elseif ($action==='tag_rename' && $actor) {
                (new Documents($db,$root))->renameTag($actor,(int)field('id'),field('name'));
                $_SESSION['flash']='Tag umbenannt; Dokumentzuordnungen bleiben erhalten.';
            } elseif ($action==='tag_delete' && $actor) {
                confirmed(); $count=(new Documents($db,$root))->deleteTag($actor,(int)field('id'),(int)field('document_count'));
                $_SESSION['flash']='Tag gelöscht und von '.$count.' Dokumenten entfernt.';
            } elseif ($action==='trash_retention_save' && $actor) {
                $days=field('days');
                if (!ctype_digit($days)) throw new RuntimeException('Papierkorbfrist muss eine ganze Zahl sein.');
                $retention=new TrashRetention($db,$root); $retention->saveDays($actor,(int)$days,field('retention_confirm')==='yes');
                $_SESSION['flash']=(int)$days===0?'Papierkorb-Dokumente werden unbegrenzt aufbewahrt.':'Papierkorbfrist gespeichert. Die endgültige Löschung erfolgt erst durch den Serverdienst.';
            } elseif ($action==='source_save' && $actor) {
                $sourceManager->save($actor,['acceptanceEnabled'=>field('acceptance_enabled')==='1','acceptanceTagMode'=>field('acceptance_tag_mode')?:'add','acceptanceFolders'=>$_POST['acceptance_folders']??[],'acceptanceTags'=>$_POST['acceptance_tags']??[],'id'=>field('id'),'revision'=>field('revision'),'kind'=>field('kind'),'name'=>field('source_name'),'interval'=>field('interval'),'host'=>field('host'),'port'=>field('port'),'security'=>field('security'),'username'=>field('username'),'folder'=>field('folder'),'url'=>field('url'),'recursive'=>field('recursive')==='1','deleteAfter'=>field('delete_after')==='1','aiMode'=>field('ai_mode'),'secret'=>field('source_secret'),'enabled'=>field('enabled')==='1']);
                $automatic=field('enabled')==='1' && (int)field('interval')>0;
                $_SESSION['flash']=$automatic && !(new RemoteFetchJobs($db,$root,$identity))->workerActive()
                    ? 'Eingangsquelle gespeichert. Für das automatische Intervall fehlt ein aktiver Cronjob; Hinweise stehen in den Quelleneinstellungen.'
                    : 'Eingangsquelle sicher gespeichert.'.($automatic?' Automatischer Abrufworker ist aktiv.':' Manueller Abruf benötigt keinen Cronjob.');
            } elseif ($action==='source_delete' && $actor) {
                confirmed(); $sourceManager->delete($actor,(int)field('id'),(int)field('revision'));
                $_SESSION['flash']='Unbenutzte Eingangsquelle und ihre verschlüsselten Zugangsdaten wurden gelöscht.';
            } elseif ($action==='ai_configuration_save' && $actor) {
                $aiManager->save($actor,['revision'=>field('revision'),'enabled'=>field('enabled')==='1','external_processing_confirmed'=>field('external_processing_confirmed')==='1','endpoint'=>field('endpoint'),'primary_model'=>field('primary_model'),'fallback_model'=>field('fallback_model'),'instruction_text'=>field('instruction_text'),'timeout_seconds'=>field('timeout_seconds'),'secret'=>field('ai_secret')]);
                $_SESSION['flash']='Externe KI-Verarbeitung sicher konfiguriert.';
            } elseif ($action==='ai_models_refresh' && $actor) {
                $models=$aiManager->models($actor); $_SESSION['ai_models']=['tenant'=>$actor->tenantId(),'expires'=>time()+600,'values'=>$models]; $_SESSION['flash']=count($models).' aktuelle IONOS-Modelle geladen. Bitte gewünschtes Modell auswählen und die KI-Konfiguration speichern.';
            } elseif ($action==='tenant_delete_review' && $actor) {
                $preview=$deletion->preview($actor,(int)field('id'));
                $_SESSION['tenant_delete_review']=['id'=>(int)$preview['id'],'uuid'=>$preview['public_id'],'name'=>$preview['name'],'documents'=>$preview['documents'],'users'=>$preview['users'],'folders'=>$preview['folders'],'operator'=>$actor->id(),'stage'=>1,'expires'=>time()+600,'token'=>bin2hex(random_bytes(32))];
                $deleteRedirect=(int)$preview['id'];
            } elseif ($action==='tenant_delete_confirm' && $actor) {
                $review=deletionReview($actor,1); confirmed();
                $_SESSION['tenant_delete_review']['stage']=2;
                $_SESSION['tenant_delete_review']['token']=bin2hex(random_bytes(32));
                $deleteRedirect=$review['id'];
            } elseif ($action==='tenant_delete_start' && $actor) {
                $review=deletionReview($actor,2); confirmed();
                if (!hash_equals($review['name'],field('tenant_name'))) throw new RuntimeException('Bitte den Mandantennamen exakt eingeben.');
                $deletion->start($actor,$review['id'],$review['uuid'],$review['name']);
                unset($_SESSION['tenant_delete_review']); $deleteRedirect=$review['id'];
            } elseif ($action==='tenant_delete_step' && $actor) {
                $job=$deletion->step($actor,(int)field('id'));
                if ($job['phase']==='done') $_SESSION['flash']='Mandant und alle zugehörigen Anwendungsdaten und verwalteten Dateien wurden endgültig gelöscht. Keine Wiederherstellung über den Papierkorb möglich.';
                else $deleteRedirect=(int)field('id');
            } elseif ($action==='source_test' && $actor) {
                $probe=(new SourceConnectionTester($sourceManager))->test($actor,(int)field('id'));
                $_SESSION['flash']=$probe['message'].' Gefundene '.($probe['kind']==='imap'?'Nachrichten':'Dateien').': '.$probe['count'].'.';
            } elseif ($action==='ai_configuration_save' && $actor) {
                $aiManager->save($actor,['revision'=>field('revision'),'enabled'=>field('enabled')==='1','external_processing_confirmed'=>field('external_processing_confirmed')==='1','endpoint'=>field('endpoint'),'primary_model'=>field('primary_model'),'fallback_model'=>field('fallback_model'),'instruction_text'=>field('instruction_text'),'timeout_seconds'=>field('timeout_seconds'),'secret'=>field('ai_secret')]);
                $_SESSION['flash']='Externe KI-Verarbeitung sicher konfiguriert.';
            } elseif ($action==='ai_models_refresh' && $actor) {
                $models=$aiManager->models($actor); $_SESSION['ai_models']=['tenant'=>$actor->tenantId(),'expires'=>time()+600,'values'=>$models]; $_SESSION['flash']=count($models).' aktuelle IONOS-Modelle geladen. Bitte gewünschtes Modell auswählen und die KI-Konfiguration speichern.';
            } elseif ($action==='tenant_delete_review' && $actor) {
                $administration->createUser($actor,accountInput(),field('role'));
                $_SESSION['flash']='Benutzer angelegt. Startpasswort sicher mitteilen; beim ersten Login ist ein Passwortwechsel erforderlich.';
            } elseif ($action==='user_update' && $actor) {
                confirmed(); $administration->updateUser($actor,(int)field('id'),(int)field('version'),field('display_name'),field('role'),field('active')==='1');
                $_SESSION['flash']='Zuordnung gespeichert. Bestehende Zugriffe auf diesen Mandanten wurden widerrufen; andere Mandanten bleiben unverändert.';
            } elseif ($action==='user_create' && $actor) {
                $administration->createUser($actor,accountInput(),field('role'));
                $_SESSION['flash']='Benutzer angelegt. Startpasswort sicher mitteilen; beim ersten Login ist ein Passwortwechsel erforderlich.';
            } elseif ($action==='user_update' && $actor) {
                confirmed(); $administration->updateUser($actor,(int)field('id'),(int)field('version'),field('display_name'),field('role'),field('active')==='1');
                $_SESSION['flash']='Zuordnung gespeichert. Bestehende Zugriffe auf diesen Mandanten wurden widerrufen; andere Mandanten bleiben unverändert.';
            } elseif ($action==='user_invite' && $actor) {
                $_SESSION['invite_code']=$administration->inviteUser($actor,field('role'));
            } elseif ($action==='tenant_login' && $actor) {
                $actor->requireOperator(); $hint=null;
                foreach ($administration->tenants($actor) as $item) if ($item['public_id']===field('tenant') && $item['active']) $hint=$item['public_id'];
                if (!$hint) throw new RuntimeException('Mandant nicht verfügbar.');
                $_SESSION=['flash'=>'Betreiber abgemeldet. Bitte mit dem gemeinsamen Benutzerkonto anmelden.']; session_regenerate_id(true);
            } elseif ($action==='tenant_delete_cancel' && $actor) {
                $actor->requireOperator(); unset($_SESSION['tenant_delete_review']);
                $_SESSION['flash']='Löschvorbereitung abgebrochen. Kein Löschauftrag gestartet.';
            } else throw new RuntimeException('Aktion in diesem Zustand nicht erlaubt.');
            $_SESSION['csrf']=bin2hex(random_bytes(32));
            $destination=in_array(field('section'),['tenants','users','account','storage','accounting','settings','documents'],true)?'?section='.field('section'):'';
            if ($action==='install') $destination='?login=operator';
            if (in_array($action,['tenant_switch','invitation_accept'],true)) $destination='';
            if ($deleteRedirect) $destination='?section=tenant_delete&id='.$deleteRedirect;
            if (field('overlay')==='1') $destination.=($destination===''?'?':'&').'overlay=1';
            header('Location: '.($_SERVER['SCRIPT_NAME']??'index.php').$destination,true,303); exit;
        } catch (PDOException $exception) { $error='Datenbankaktion fehlgeschlagen. Zugang, Schema und Berechtigungen prüfen.'; }
        catch (RuntimeException|InvalidArgumentException $exception) { $error=$exception->getMessage(); }
    }
    $message=$_SESSION['flash']??''; unset($_SESSION['flash']);
    $inviteCode=$_SESSION['invite_code']??''; unset($_SESSION['invite_code']);
    if ($actor && !$actor->row['must_change_password'] && !($actor->row['bootstrap_pending']??false)) {
        if ($actor->kind!=='operator') $choices=$auth->memberships($actor);
        $allowed=$actor->kind==='operator'?['tenants','storage','account']:($actor->kind==='account'?['choose','account']:($actor->row['role']==='admin'?['documents','users','accounting','evaluation','settings','account','choose']:['documents','settings','account','choose']));
        if ($section==='tenant_delete' && $actor->kind==='operator') {
            $deletionId=(int)($_GET['id']??0);
            $deletionJob=$deletion->job($actor,$deletionId);
            $review=$_SESSION['tenant_delete_review']??null;
            if ($review && $review['id']===$deletionId && $review['operator']===$actor->id() && $review['expires']>=time()) $deletionReview=$review;
        } elseif (!in_array($section,$allowed,true)) $section=$allowed[0];
        if ($actor->kind==='tenant' && $section!=='documents') $appearancePreferences=(new Documents($db,$root))->preferences($actor);
        if ($section==='tenants') {
            $tenants=$administration->tenants($actor);
            foreach ($tenants as &$tenant) $tenant['deleting']=$deletion->job($actor,(int)$tenant['id'])!==null;
            unset($tenant);
        }
        if ($section==='users') $users=$administration->users($actor,is_string($_GET['q']??null)?$_GET['q']:'');
        if ($section==='storage') $storageLocations=(new Storage($db,$root))->locations($actor);
        if ($section==='accounting') $accountingSettings=(new Documents($db,$root))->accountingSettings($actor);
        if ($section==='settings' && $actor->kind==='tenant') {
            $tagCatalogue=(new Documents($db,$root))->tagCatalogue($actor,is_string($_GET['q']??null)?$_GET['q']:'');
            if ($actor->row['role']==='admin') $sessionTimeoutMinutes=$auth->sessionMinutes($actor->tenantId());
        }
        if ($section==='evaluation' && $actor->row['role']==='admin') {
            $filter=['query'=>is_string($_GET['query']??null)?$_GET['query']:'',
                'dateFrom'=>is_string($_GET['dateFrom']??null)?$_GET['dateFrom']:'','dateTo'=>is_string($_GET['dateTo']??null)?$_GET['dateTo']:'',
                'amountFrom'=>is_string($_GET['amountFrom']??null)?$_GET['amountFrom']:'','amountTo'=>is_string($_GET['amountTo']??null)?$_GET['amountTo']:'',
                'invoiceNumbers'=>is_string($_GET['invoiceNumbers']??null)?$_GET['invoiceNumbers']:'','accountCode'=>is_string($_GET['accountCode']??null)?$_GET['accountCode']:'',
                'documentType'=>is_string($_GET['documentType']??null)?$_GET['documentType']:'',
                'folder'=>is_string($_GET['folder']??null)?$_GET['folder']:'',
                'folders'=>is_array($_GET['folders']??null)?$_GET['folders']:[],
                'resultView'=>is_string($_GET['resultView']??null)?$_GET['resultView']:'all','latestCount'=>is_string($_GET['latestCount']??null)?$_GET['latestCount']:'25',
                'accounts'=>is_array($_GET['accounts']??null)?$_GET['accounts']:[],
                'tag'=>is_string($_GET['tag']??null)?$_GET['tag']:'',
                'notTag'=>is_string($_GET['notTag']??null)?$_GET['notTag']:'',
                'owner'=>is_string($_GET['owner']??null)?$_GET['owner']:'',
                'sort'=>is_string($_GET['sort']??null)?$_GET['sort']:'newest','size'=>is_string($_GET['size']??null)?$_GET['size']:'all',
                'includeExpired'=>($_GET['includeExpired']??'')==='1'?'1':'',
                'includeNotSearchable'=>($_GET['includeNotSearchable']??'')==='1'?'1':''];
            $result=null;
            if (isset($_GET['run'])) {
                try { $result=(new Evaluation($db))->bookingSummary($actor,$filter); } catch (RuntimeException $e) { $error=$e->getMessage(); }
            }
            $accounts=(new Documents($db,$root))->accounting($actor)['accounts'];
            $tags=$db->prepare('SELECT id,name FROM tags WHERE tenant_id=? AND active=1 ORDER BY name'); $tags->execute([$actor->tenantId()]); $tags=$tags->fetchAll();
            $owners=$db->prepare('SELECT id,display_name FROM users WHERE tenant_id=? ORDER BY display_name'); $owners->execute([$actor->tenantId()]); $owners=$owners->fetchAll();
            $folders=(new Documents($db,$root))->folders($actor);
        }
        if ($section==='account' && $actor->kind==='tenant') { $retention=new TrashRetention($db,$root); $trashRetentionDays=$retention->days($actor); $trashWorkerActive=$retention->workerActive(); $importSources=$sourceManager->sources($actor); $sourceWorkerActive=(new RemoteFetchJobs($db,$root,$identity))->workerActive(); if ($actor->row['role']==='admin') { $aiConfiguration=$aiManager->get($actor); $aiWorkerActive=(new AiJobs($db,$root,$identity))->workerActive(); $cached=$_SESSION['ai_models']??null; if (is_array($cached) && ($cached['tenant']??0)===$actor->tenantId() && ($cached['expires']??0)>=time() && is_array($cached['values']??null)) $aiModels=$cached['values']; } }
    }
} catch (Throwable $exception) {
    $fatal=true; http_response_code(503);
    $error=$schemaPending?'Datenbankschema wird aktualisiert. Auf dem Server php bin/setup.php upgrade ausführen; keine Neuinstallation erforderlich.':($exception instanceof PDOException ? 'Datenbank nicht erreichbar. Bitte lokale config.php und Datenbankberechtigungen prüfen.' : 'Einrichtung nicht verfügbar. HTTPS, lokale Konfiguration und Schreibrechte für storage/system prüfen.');
}
if (isset($_GET['api'])) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'error'=>$error]); exit; }
if (($_GET['overlay']??'')==='1' && !$fatal && $actor && in_array($actor->kind,['tenant','account'],true) && !$actor->row['must_change_password'] && !($actor->row['bootstrap_pending']??false)) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'");
    require __DIR__.'/views/admin-overlay.php'; return;
}
if (!$fatal && $actor?->kind==='tenant' && $section==='documents' && !$actor->row['must_change_password']) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    require __DIR__.'/views/live-workspace.php'; return;
}
require __DIR__.'/views/foundation.php';
