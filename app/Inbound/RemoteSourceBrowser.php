<?php
declare(strict_types=1);
namespace O8\Inbound;

use O8\Auth\Actor;

/** Read-only remote inventory following the proven o7 list-before-fetch workflow. */
final class RemoteSourceBrowser
{
    public const MAX_MESSAGES=200;
    public const MAX_DAV_RESPONSE=4194304;
    private const EXTENSIONS=['pdf','jpg','jpeg','png','json','txt'];
    public function __construct(private SourceManager $sources) {}

    public function browse(Actor $actor,int $sourceId): array
    {
        $connection=$this->sources->connection($actor,$sourceId);
        return $connection['kind']==='imap'?$this->imap($connection['config'],$connection['secret'],false):$this->webdav($connection['config'],$connection['secret'],false);
    }

    /** Re-lists the source and resolves opaque browser keys to worker-only locators. */
    public function resolveSelection(Actor $actor,int $sourceId,array $keys): array
    {
        if (!$keys || count($keys)>100) throw new \RuntimeException('Bitte 1 bis 100 Quelldateien auswählen.');
        foreach ($keys as $key) if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D',$key)) throw new \RuntimeException('Ungültige Quellenauswahl.');
        $keys=array_values(array_unique($keys));
        $connection=$this->sources->connection($actor,$sourceId);
        $inventory=$connection['kind']==='imap'?$this->imap($connection['config'],$connection['secret'],true):$this->webdav($connection['config'],$connection['secret'],true);
        $resolved=self::selectDocuments($inventory,$keys);
        if (count($resolved)!==count($keys)) throw new \RuntimeException('Die Quellenauswahl ist nicht mehr aktuell. Bitte neu laden.');
        return ['kind'=>$connection['kind'],'config'=>$connection['config'],'items'=>$resolved];
    }

    private function imap(array $config,string $secret,bool $internal): array
    {
        if (!function_exists('imap_open')) throw new \RuntimeException('IMAP-Unterstützung ist auf dem Server nicht verfügbar.');
        imap_timeout(IMAP_OPENTIMEOUT,10); imap_timeout(IMAP_READTIMEOUT,20); imap_timeout(IMAP_CLOSETIMEOUT,5);
        $transport=$config['security']==='tls'?'ssl':'tls'; $mailbox='{'.$config['host'].':'.(int)$config['port'].'/imap/'.$transport.'}'.$config['folder'];
        $stream=@imap_open($mailbox,$config['username'],$secret,OP_READONLY,1,['DISABLE_AUTHENTICATOR'=>'GSSAPI']);
        try {
            if ($stream===false) throw new \RuntimeException('IMAP-Verbindung oder Anmeldung fehlgeschlagen.');
            $status=@imap_status($stream,$mailbox,SA_MESSAGES|SA_UIDVALIDITY); if ($status===false) throw new \RuntimeException('IMAP-Postfach konnte nicht gelesen werden.');
            $uids=@imap_sort($stream,SORTARRIVAL,1,SE_UID)?:[]; $uids=array_slice($uids,0,self::MAX_MESSAGES); $rows=[];
            foreach ($uids as $uid) {
                $overview=@imap_fetch_overview($stream,(string)(int)$uid,FT_UID); $header=$overview[0]??null; $structure=@imap_fetchstructure($stream,(string)(int)$uid,FT_UID); if (!$header || !$structure) continue;
                $attachments=[]; foreach ($this->parts($structure) as $part) {
                    $name=$this->filename($part['part']); if ($name==='' || !$this->supported($name)) continue;
                    $attachments[]=['remoteKey'=>hash('sha256',(int)$status->uidvalidity."\0".(int)$uid."\0".$part['section']),'section'=>$part['section'],'encoding'=>(int)($part['part']->encoding??0),'filename'=>$name,'size'=>(int)($part['part']->bytes??0),'mime'=>$this->mime($part['part'])];
                }
                if (!$attachments) continue;
                $rows[]=['uid'=>(int)$uid,'from'=>$this->header((string)($header->from??'')),'subject'=>$this->header((string)($header->subject??'(kein Betreff)')),'date'=>$this->date((string)($header->date??'')),'attachments'=>$attachments];
            }
            if (!$internal) foreach ($rows as &$row) foreach ($row['attachments'] as &$attachment) unset($attachment['section'],$attachment['encoding']);
            return ['kind'=>'imap','uidValidity'=>(int)$status->uidvalidity,'total'=>(int)$status->messages,'limited'=>count($uids)<(int)$status->messages,'rows'=>$rows];
        } finally { if ($stream!==false) @imap_close($stream); imap_errors(); imap_alerts(); }
    }

    private function webdav(array $config,string $secret,bool $internal): array
    {
        $target=SourceConnectionTester::webDavTarget((string)$config['url']);
        $body='<?xml version="1.0" encoding="UTF-8"?><D:propfind xmlns:D="DAV:"><D:prop><D:resourcetype/><D:getcontentlength/><D:getcontenttype/><D:getlastmodified/><D:getetag/><D:displayname/></D:prop></D:propfind>';
        $response=''; $overflow=false; $handle=curl_init($target['url']);
        curl_setopt_array($handle,[CURLOPT_CUSTOMREQUEST=>'PROPFIND',CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Depth: 1','Content-Type: application/xml; charset=utf-8','Content-Length: '.strlen($body)],CURLOPT_USERPWD=>$config['username'].':'.$secret,CURLOPT_HTTPAUTH=>CURLAUTH_BASIC|CURLAUTH_DIGEST,CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[$target['resolve']],CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk) use (&$response,&$overflow): int { if (strlen($response)+strlen($chunk)>self::MAX_DAV_RESPONSE) { $overflow=true; return 0; } $response.=$chunk; return strlen($chunk); }]);
        try {
            $ok=curl_exec($handle); $status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
            if ($overflow) throw new \RuntimeException('WebDAV-Dateiliste ist zu groß. Quellordner verkleinern.');
            if ($ok===false) throw new \RuntimeException('WebDAV-Dateiliste konnte nicht geladen werden.');
            if ($status===401 || $status===403) throw new \RuntimeException('WebDAV-Anmeldung oder Berechtigung fehlgeschlagen.');
            if ($status!==207) throw new \RuntimeException('WebDAV-Server beantwortet PROPFIND nicht wie erwartet.');
            $rows=array_values(array_filter(self::parseDavListing($response,$config['url']),fn(array $row):bool=>$this->supported($row['filename'])));
            if (!$internal) foreach ($rows as &$row) unset($row['href'],$row['etag']);
            return ['kind'=>'webdav','total'=>count($rows),'limited'=>false,'rows'=>$rows];
        } finally { curl_close($handle); }
    }

    public static function parseDavListing(string $xml,string $baseUrl): array
    {
        $base=parse_url($baseUrl); $basePath=rtrim(rawurldecode((string)($base['path']??'/')),'/').'/'; $previous=libxml_use_internal_errors(true);
        try {
            $document=new \DOMDocument(); if (!$document->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS)) throw new \RuntimeException('WebDAV-Antwort enthält kein gültiges XML.');
            $xpath=new \DOMXPath($document); $xpath->registerNamespace('d','DAV:'); $nodes=$xpath->query('//d:response'); if ($nodes===false) throw new \RuntimeException('WebDAV-Antwort konnte nicht ausgewertet werden.'); $rows=[];
            foreach ($nodes as $node) {
                if (($xpath->query('.//d:resourcetype/d:collection',$node)?->length??0)>0) continue;
                $href=trim((string)($xpath->query('./d:href',$node)?->item(0)?->textContent??'')); if ($href==='') continue;
                $path=rawurldecode((string)(parse_url($href,PHP_URL_PATH)??'')); if (!str_starts_with($path,$basePath) || preg_match('#(?:^|/)\.{1,2}(?:/|$)#D',$path)) continue;
                $name=trim((string)($xpath->query('.//d:displayname',$node)?->item(0)?->textContent??'')); if ($name==='') $name=rawurldecode(basename($path));
                $etag=trim((string)($xpath->query('.//d:getetag',$node)?->item(0)?->textContent??'')); $size=max(0,(int)trim((string)($xpath->query('.//d:getcontentlength',$node)?->item(0)?->textContent??'0')));
                $rows[]=['remoteKey'=>hash('sha256',$href."\0".$etag),'href'=>$href,'etag'=>$etag,'filename'=>mb_substr($name,0,255),'size'=>$size,'mime'=>mb_substr(trim((string)($xpath->query('.//d:getcontenttype',$node)?->item(0)?->textContent??'')),0,100),'modified'=>mb_substr(trim((string)($xpath->query('.//d:getlastmodified',$node)?->item(0)?->textContent??'')),0,100)];
            }
            return $rows;
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }

    public static function selectDocuments(array $inventory,array $keys): array
    {
        $wanted=array_fill_keys($keys,true); $documents=[]; $all=[];
        if (($inventory['kind']??'')==='imap') {
            foreach ($inventory['rows']??[] as $message) foreach ($message['attachments']??[] as $attachment) $all[]=$attachment+['uid'=>(int)$message['uid'],'uidValidity'=>(int)$inventory['uidValidity']];
            foreach ($all as $candidate) if (isset($wanted[$candidate['remoteKey']]) && self::documentName((string)$candidate['filename'])) {
                $stem=mb_strtolower(pathinfo((string)$candidate['filename'],PATHINFO_FILENAME)); $sidecars=[];
                foreach ($all as $sidecar) if ((int)$sidecar['uid']===(int)$candidate['uid'] && mb_strtolower(pathinfo((string)$sidecar['filename'],PATHINFO_FILENAME))===$stem && in_array(mb_strtolower(pathinfo((string)$sidecar['filename'],PATHINFO_EXTENSION)),['json','txt'],true)) $sidecars[]=$sidecar;
                $messageDocuments=array_values(array_filter($all,fn(array $file):bool=>(int)$file['uid']===(int)$candidate['uid'] && self::documentName((string)$file['filename'])));
                $deleteEligible=count($messageDocuments)>0 && count(array_filter($messageDocuments,fn(array $file):bool=>isset($wanted[$file['remoteKey']])))===count($messageDocuments);
                $documents[]=['remoteKey'=>$candidate['remoteKey'],'filename'=>$candidate['filename'],'size'=>(int)$candidate['size'],'mime'=>$candidate['mime'],'locator'=>['uidValidity'=>$candidate['uidValidity'],'uid'=>$candidate['uid'],'section'=>$candidate['section'],'encoding'=>$candidate['encoding'],'deleteMessageEligible'=>$deleteEligible],'sidecars'=>array_map(fn(array $s):array=>['filename'=>$s['filename'],'size'=>(int)$s['size'],'mime'=>$s['mime'],'locator'=>['uidValidity'=>$s['uidValidity'],'uid'=>$s['uid'],'section'=>$s['section'],'encoding'=>$s['encoding']]],$sidecars)];
            }
        } elseif (($inventory['kind']??'')==='webdav') {
            $all=$inventory['rows']??[];
            foreach ($all as $candidate) if (isset($wanted[$candidate['remoteKey']]) && self::documentName((string)$candidate['filename'])) {
                $stem=mb_strtolower(pathinfo((string)$candidate['filename'],PATHINFO_FILENAME)); $sidecars=[];
                foreach ($all as $sidecar) if (mb_strtolower(pathinfo((string)$sidecar['filename'],PATHINFO_FILENAME))===$stem && in_array(mb_strtolower(pathinfo((string)$sidecar['filename'],PATHINFO_EXTENSION)),['json','txt'],true)) $sidecars[]=['filename'=>$sidecar['filename'],'size'=>(int)$sidecar['size'],'mime'=>$sidecar['mime'],'locator'=>['href'=>$sidecar['href'],'etag'=>$sidecar['etag']]];
                $documents[]=['remoteKey'=>$candidate['remoteKey'],'filename'=>$candidate['filename'],'size'=>(int)$candidate['size'],'mime'=>$candidate['mime'],'locator'=>['href'=>$candidate['href'],'etag'=>$candidate['etag']],'sidecars'=>$sidecars];
            }
        }
        return $documents;
    }

    public static function documentName(string $name): bool { return in_array(mb_strtolower(pathinfo($name,PATHINFO_EXTENSION)),['pdf','jpg','jpeg','png'],true); }

    private function parts(object $structure,string $prefix=''): array { $rows=[]; if (!empty($structure->parts)) foreach ($structure->parts as $index=>$part) { $section=$prefix===''?(string)($index+1):$prefix.'.'.($index+1); $rows=array_merge($rows,!empty($part->parts)?$this->parts($part,$section):[['part'=>$part,'section'=>$section]]); } else $rows[]=['part'=>$structure,'section'=>'1']; return $rows; }
    private function filename(object $part): string { foreach (['dparameters','parameters'] as $property) foreach ($part->{$property}??[] as $parameter) if (in_array(mb_strtolower((string)($parameter->attribute??'')),['filename','name'],true)) return mb_substr($this->header((string)$parameter->value),0,255); return ''; }
    private function header(string $value): string { $result=''; foreach (imap_mime_header_decode($value)?:[] as $part) { $text=(string)($part->text??''); $charset=mb_strtolower((string)($part->charset??'default')); if (!in_array($charset,['default','utf-8'],true)) { $converted=@iconv($charset,'UTF-8//TRANSLIT//IGNORE',$text); if ($converted!==false) $text=$converted; } $result.=$text; } return mb_substr(trim($result),0,500); }
    private function mime(object $part): string { $types=['text','multipart','message','application','audio','image','video','other']; return ($types[(int)($part->type??7)]??'application').'/'.mb_strtolower((string)($part->subtype??'octet-stream')); }
    private function supported(string $name): bool { return in_array(mb_strtolower(pathinfo($name,PATHINFO_EXTENSION)),self::EXTENSIONS,true); }
    private function date(string $value): ?string { $time=strtotime($value); return $time===false?null:gmdate('Y-m-d H:i:s',$time); }
}
