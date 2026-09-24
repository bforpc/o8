<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use O8\Inbound\SourceCredentialVault;
use O8\Inbound\SourceConnectionTester;
use O8\Inbound\RemoteSourceBrowser;

$checks=0;
function check(bool $condition,string $message): void { global $checks; if (!$condition) throw new RuntimeException('FAIL '.$message); ++$checks; echo "PASS $message\n"; }
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
    ['remoteKey'=>str_repeat('b',64),'section'=>'2','encoding'=>3,'filename'=>'one.txt','size'=>4,'mime'=>'text/plain'],
    ['remoteKey'=>str_repeat('c',64),'section'=>'3','encoding'=>3,'filename'=>'two.pdf','size'=>8,'mime'=>'application/pdf'],
]]]];
$one=RemoteSourceBrowser::selectDocuments($mailInventory,[str_repeat('a',64)]);
$both=RemoteSourceBrowser::selectDocuments($mailInventory,[str_repeat('a',64),str_repeat('c',64)]);
check(count($one)===1 && $one[0]['sidecars'][0]['filename']==='one.txt' && !$one[0]['locator']['deleteMessageEligible'],'partial message selection never authorizes deleting its email');
check(count($both)===2 && $both[0]['locator']['deleteMessageEligible'] && $both[1]['locator']['deleteMessageEligible'],'complete document selection authorizes post-fetch email cleanup');
try { RemoteSourceBrowser::parseDavListing('<broken','https://dav.example.test/remote/inbox/'); check(false,'invalid DAV XML rejected'); } catch (RuntimeException) { check(true,'invalid DAV XML rejected'); }
echo "$checks source credential checks passed.\n";
