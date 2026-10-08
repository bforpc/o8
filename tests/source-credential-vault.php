<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use O8\Inbound\SourceCredentialVault;
use O8\Inbound\SourceConnectionTester;
use O8\Inbound\RemoteSourceBrowser;
use O8\Core\Languages;

$checks=0;
function check(bool $condition,string $message): void { global $checks; if (!$condition) throw new RuntimeException('FAIL '.$message); ++$checks; echo "PASS $message\n"; }
$browserSource=file_get_contents(dirname(__DIR__).'/app/Inbound/RemoteSourceBrowser.php');
check(is_string($browserSource) && str_contains($browserSource,"imap_sort(\$stream,SORTARRIVAL,true,SE_UID,'UNDELETED')"),'IMAP inventory passes a boolean reverse flag and excludes deleted messages');
check(is_string($browserSource) && str_contains($browserSource,'imap_fetchstructure($stream,(int)$uid,FT_UID)'),'IMAP inventory passes an integer UID to imap_fetchstructure');
$fetchSource=file_get_contents(dirname(__DIR__).'/app/Inbound/RemoteFetchJobs.php');
check(is_string($fetchSource) && str_contains($fetchSource,'imap_fetchbody($stream,(int)$locator[\'uid\']'),'IMAP fetch passes an integer UID to imap_fetchbody');
check(str_contains($fetchSource,'imap_expunge($stream)'),'successful configured mailbox cleanup expunges messages marked for deletion');
check(str_contains(RemoteSourceBrowser::describeImapFailure('connect',['LOGIN failed'],'INBOX'),'IMAP-Anmeldung fehlgeschlagen'),'IMAP failure identifies authentication errors');
check(str_contains(RemoteSourceBrowser::describeImapFailure('connect',['Can not connect to server'],'INBOX'),'IMAP-Verbindung fehlgeschlagen'),'IMAP failure identifies connection errors');
check(str_contains(RemoteSourceBrowser::describeImapFailure('connect',['Mailbox does not exist'],'Archive'),'IMAP-Ordner „Archive“'),'IMAP failure identifies mailbox folder errors');
$safeImapError=RemoteSourceBrowser::describeImapFailure('connect',['mailbox xyz denied for user private@example.test'],'INBOX');
check(!str_contains($safeImapError,'private@example.test'),'IMAP diagnostics do not expose server-provided account details');
$identity=['id'=>'01234567-89ab-4def-8123-456789abcdef','key'=>str_repeat('ab',32)];
$vault=new SourceCredentialVault($identity); $cipher=$vault->encrypt('private-token',12,34,'imap');
check(!str_contains($cipher,'private-token'),'ciphertext does not contain plaintext');
check($vault->decrypt($cipher,$identity['id'],1,12,34,'imap')==='private-token','credential decrypts in its bound context');
try { $vault->decrypt($cipher,$identity['id'],1,12,35,'imap'); check(false,'foreign owner context rejected'); } catch (RuntimeException) { check(true,'foreign owner context rejected'); }
try { $vault->decrypt($cipher,$identity['id'],1,12,34,'webdav'); check(false,'foreign source kind rejected'); } catch (RuntimeException) { check(true,'foreign source kind rejected'); }
try { $vault->decrypt($cipher,'ffffffff-ffff-4fff-8fff-ffffffffffff',1,12,34,'imap'); check(false,'foreign installation key id rejected'); } catch (RuntimeException) { check(true,'foreign installation key id rejected'); }
check(SourceConnectionTester::allowedWebDavIp('93.184.216.34'),'public WebDAV address accepted by network guard');
foreach (['192.168.0.1','192.168.255.254'] as $address) check(SourceConnectionTester::allowedWebDavIp($address),'explicit 192.168/16 intranet address accepted: '.$address);
foreach (['127.0.0.1','10.0.0.1','172.16.0.1','169.254.169.254','::1','fc00::1','fe80::1'] as $address) check(!SourceConnectionTester::allowedWebDavIp($address),'non-approved private or reserved address rejected: '.$address);
check(str_contains(SourceConnectionTester::describeDavStatus(401),'HTTP 401') && str_contains(SourceConnectionTester::describeDavStatus(401),'Benutzername'),'WebDAV 401 diagnosis points to authentication');
check(str_contains(SourceConnectionTester::describeDavStatus(403),'HTTP 403') && str_contains(SourceConnectionTester::describeDavStatus(403),'PROPFIND-Berechtigung'),'WebDAV 403 diagnosis points to path permissions');
check(str_contains(SourceConnectionTester::describeDavStatus(404),'HTTP 404') && str_contains(SourceConnectionTester::describeDavStatus(404),'fehlender Kontoberechtigung'),'WebDAV 404 diagnosis also accounts for servers hiding permission failures');
check(str_contains(SourceConnectionTester::describeDavStatus(302),'HTTP 302') && str_contains(SourceConnectionTester::describeDavStatus(302),'Weiterleitung'),'WebDAV redirects are identified without following them');
check(str_contains(SourceConnectionTester::describeDavStatus(405),'HTTP 405') && str_contains(SourceConnectionTester::describeDavStatus(405),'PROPFIND'),'WebDAV method rejection is identified');
$languageCatalog=Languages::available(dirname(__DIR__).'/lang');
$translatedDav=Languages::display($languageCatalog,'en',SourceConnectionTester::describeDavStatus(401));
check($translatedDav==='WebDAV authentication was rejected (HTTP 401). Check the username, password or app password.','WebDAV HTTP diagnosis translates with its status placeholder');
$dav='<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote/inbox/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response><d:response><d:href>/remote/inbox/Rechnung%201.pdf</d:href><d:propstat><d:prop><d:resourcetype/><d:getcontentlength>1234</d:getcontentlength><d:getcontenttype>application/pdf</d:getcontenttype><d:getlastmodified>Mon, 21 Sep 2026 08:00:00 GMT</d:getlastmodified><d:getetag>"abc"</d:getetag><d:displayname>Rechnung 1.pdf</d:displayname></d:prop></d:propstat></d:response><d:response><d:href>/outside/secret.pdf</d:href><d:propstat><d:prop><d:resourcetype/><d:getcontentlength>1</d:getcontentlength></d:prop></d:propstat></d:response></d:multistatus>';
$davRows=RemoteSourceBrowser::parseDavListing($dav,'https://dav.example.test/remote/inbox/');
check(count($davRows)===1 && $davRows[0]['filename']==='Rechnung 1.pdf' && $davRows[0]['size']===1234 && strlen($davRows[0]['remoteKey'])===64,'bounded DAV parser keeps files below configured base path and stable opaque keys');
$traversal=RemoteSourceBrowser::parseDavListing('<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote/inbox/%2e%2e/secret.pdf</d:href><d:propstat><d:prop><d:resourcetype/><d:getetag>"x"</d:getetag></d:prop></d:propstat></d:response></d:multistatus>','https://dav.example.test/remote/inbox/');
check($traversal===[],'DAV inventory rejects encoded parent-path traversal');
$davWithSidecar=RemoteSourceBrowser::parseDavListing('<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"><d:response><d:href>/remote/inbox/a.pdf</d:href><d:propstat><d:prop><d:resourcetype/><d:getcontentlength>8</d:getcontentlength><d:getetag>"doc"</d:getetag></d:prop></d:propstat></d:response><d:response><d:href>/remote/inbox/a.json</d:href><d:propstat><d:prop><d:resourcetype/><d:getcontentlength>4</d:getcontentlength><d:getetag>"json"</d:getetag></d:prop></d:propstat></d:response></d:multistatus>','https://dav.example.test/remote/inbox/');
$selected=RemoteSourceBrowser::selectDocuments(['kind'=>'webdav','rows'=>$davWithSidecar],[$davWithSidecar[0]['remoteKey']]);
check(count($selected)===1 && $selected[0]['sidecars'][0]['filename']==='a.json' && $selected[0]['locator']['etag']==='"doc"','verified DAV selection resolves only opaque document key and pairs same-name sidecar');
$mailInventory=['kind'=>'imap','uidValidity'=>17,'rows'=>[['uid'=>42,'attachments'=>[
    ['remoteKey'=>str_repeat('a',64),'section'=>'1','encoding'=>3,'filename'=>'one.pdf','size'=>8,'mime'=>'application/pdf'],
    ['remoteKey'=>str_repeat('b',64),'section'=>'2','encoding'=>3,'filename'=>'one.json','size'=>4,'mime'=>'application/json'],
    ['remoteKey'=>str_repeat('c',64),'section'=>'3','encoding'=>3,'filename'=>'two.pdf','size'=>8,'mime'=>'application/pdf'],
]]]];
$one=RemoteSourceBrowser::selectDocuments($mailInventory,[str_repeat('a',64)]);
$both=RemoteSourceBrowser::selectDocuments($mailInventory,[str_repeat('a',64),str_repeat('c',64)]);
check(count($one)===1 && $one[0]['sidecars'][0]['filename']==='one.json' && !$one[0]['locator']['deleteMessageEligible'],'partial message selection never authorizes deleting its email');
check(count($both)===2 && $both[0]['locator']['deleteMessageEligible'] && $both[1]['locator']['deleteMessageEligible'],'complete document selection authorizes post-fetch email cleanup');
try { RemoteSourceBrowser::parseDavListing('<broken','https://dav.example.test/remote/inbox/'); check(false,'invalid DAV XML rejected'); } catch (RuntimeException) { check(true,'invalid DAV XML rejected'); }
echo "$checks source credential checks passed.\n";
