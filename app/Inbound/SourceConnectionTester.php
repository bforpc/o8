<?php
declare(strict_types=1);
namespace O8\Inbound;

use O8\Auth\Actor;

/** Bounded, read-only connection probes. Never returns credentials or raw server errors. */
final class SourceConnectionTester
{
    private const MAX_DAV_RESPONSE=2097152;
    public function __construct(private SourceManager $sources) {}

    public function test(Actor $actor,int $sourceId): array
    {
        $connection=$this->sources->connection($actor,$sourceId);
        return $connection['kind']==='imap'?$this->imap($connection['config'],$connection['secret']):$this->webdav($connection['config'],$connection['secret']);
    }

    private function imap(array $config,string $secret): array
    {
        if (!function_exists('imap_open')) throw new \RuntimeException('IMAP-Unterstützung ist auf dem Server nicht verfügbar.');
        imap_timeout(IMAP_OPENTIMEOUT,10); imap_timeout(IMAP_READTIMEOUT,15); imap_timeout(IMAP_WRITETIMEOUT,15); imap_timeout(IMAP_CLOSETIMEOUT,5);
        $transport=$config['security']==='tls'?'ssl':'tls';
        $mailbox='{'.$config['host'].':'.(int)$config['port'].'/imap/'.$transport.'}'.$config['folder'];
        $stream=@imap_open($mailbox,$config['username'],$secret,OP_READONLY,1,['DISABLE_AUTHENTICATOR'=>'GSSAPI']);
        try {
            if ($stream===false) throw new \RuntimeException('IMAP-Verbindung oder Anmeldung fehlgeschlagen. Server, Port, TLS und Zugangsdaten prüfen.');
            $status=@imap_status($stream,$mailbox,SA_MESSAGES|SA_UIDNEXT|SA_UIDVALIDITY);
            if ($status===false) throw new \RuntimeException('IMAP-Postfach konnte nicht gelesen werden. Postfachordner und Rechte prüfen.');
            return ['kind'=>'imap','message'=>'IMAP-Verbindung erfolgreich.','count'=>(int)$status->messages,'uidValidity'=>(int)$status->uidvalidity,'uidNext'=>(int)$status->uidnext];
        } finally {
            if ($stream!==false) @imap_close($stream);
            imap_errors(); imap_alerts();
        }
    }

    private function webdav(array $config,string $secret): array
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('WebDAV-Unterstützung ist auf dem Server nicht verfügbar.');
        $target=self::webDavTarget((string)$config['url']);
        $body='<?xml version="1.0" encoding="UTF-8"?><D:propfind xmlns:D="DAV:"><D:prop><D:resourcetype/><D:getcontentlength/><D:getcontenttype/><D:getlastmodified/><D:getetag/></D:prop></D:propfind>';
        $response=''; $overflow=false; $handle=curl_init($target['url']);
        curl_setopt_array($handle,[CURLOPT_CUSTOMREQUEST=>'PROPFIND',CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Depth: 1','Content-Type: application/xml; charset=utf-8','Content-Length: '.strlen($body)],CURLOPT_USERPWD=>$config['username'].':'.$secret,CURLOPT_HTTPAUTH=>CURLAUTH_BASIC|CURLAUTH_DIGEST,CURLOPT_RETURNTRANSFER=>false,CURLOPT_HEADER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[$target['resolve']],CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk) use (&$response,&$overflow): int { if (strlen($response)+strlen($chunk)>self::MAX_DAV_RESPONSE) { $overflow=true; return 0; } $response.=$chunk; return strlen($chunk); }]);
        try {
            $ok=curl_exec($handle); $status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
            if ($overflow) throw new \RuntimeException('WebDAV-Antwort ist zu groß. Quellordner verkleinern.');
            if ($ok===false) throw new \RuntimeException('WebDAV-Verbindung fehlgeschlagen. HTTPS, Zertifikat und Erreichbarkeit prüfen.');
            if ($status===401 || $status===403) throw new \RuntimeException('WebDAV-Anmeldung oder Berechtigung fehlgeschlagen.');
            if ($status!==207) throw new \RuntimeException('WebDAV-Server beantwortet PROPFIND nicht wie erwartet.');
            $count=$this->davCount($response);
            return ['kind'=>'webdav','message'=>'WebDAV-Verbindung erfolgreich.','count'=>$count];
        } finally { curl_close($handle); }
    }

    private function davCount(string $xml): int
    {
        $previous=libxml_use_internal_errors(true);
        try {
            $document=new \DOMDocument();
            if (!$document->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS)) throw new \RuntimeException('WebDAV-Antwort enthält kein gültiges XML.');
            $xpath=new \DOMXPath($document); $xpath->registerNamespace('d','DAV:');
            $responses=$xpath->query('//d:response'); if ($responses===false) throw new \RuntimeException('WebDAV-Antwort konnte nicht ausgewertet werden.');
            $files=0; foreach ($responses as $response) { $collections=$xpath->query('.//d:resourcetype/d:collection',$response); if ($collections!==false && $collections->length===0) ++$files; }
            return $files;
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }

    public static function webDavTarget(string $url): array
    {
        $parts=parse_url($url); $host=mb_strtolower((string)($parts['host']??'')); $port=(int)($parts['port']??443);
        if ($parts===false || ($parts['scheme']??'')!=='https' || $host==='' || $port<1 || $port>65535) throw new \RuntimeException('WebDAV-Ziel ist ungültig.');
        $addresses=[];
        if (filter_var($host,FILTER_VALIDATE_IP)!==false) $addresses=[$host];
        else {
            $records=@dns_get_record($host,DNS_A|DNS_AAAA);
            foreach ($records?:[] as $record) { $address=$record['ip']??$record['ipv6']??null; if (is_string($address)) $addresses[]=$address; }
        }
        $addresses=array_values(array_unique($addresses));
        if (!$addresses) throw new \RuntimeException('WebDAV-Server konnte nicht sicher aufgelöst werden.');
        foreach ($addresses as $address) if (!self::allowedWebDavIp($address)) throw new \RuntimeException('WebDAV-Ziel liegt in einem nicht freigegebenen Netz. Erlaubt sind öffentliche Ziele und 192.168.0.0/16.');
        $selected=$addresses[0]; $resolveHost=str_contains($selected,':')?'['.$selected.']':$selected;
        return ['url'=>$url,'resolve'=>$host.':'.$port.':'.$resolveHost];
    }

    public static function allowedWebDavIp(string $address): bool
    {
        if (filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)!==false) {
            $numeric=ip2long($address);
            if ($numeric!==false && (($numeric & 0xffff0000)===0xc0a80000)) return true;
        }
        return filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false;
    }
}
