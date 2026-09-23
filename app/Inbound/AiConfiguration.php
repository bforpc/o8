<?php
declare(strict_types=1);
namespace O8\Inbound;

use O8\Auth\{Access,Actor};

final class AiConfiguration
{
    private AiCredentialVault $vault;
    public function __construct(private \PDO $db,array $identity) { $this->vault=new AiCredentialVault($identity); }

    public function get(Actor $actor,bool $withSecret=false): array
    {
        (new Access($this->db))->tenant($actor);
        $stmt=$this->db->prepare('SELECT * FROM ai_configurations WHERE tenant_id=?'); $stmt->execute([$actor->tenantId()]); $row=$stmt->fetch();
        if (!$row) return ['configured'=>false,'enabled'=>false,'external_processing_confirmed'=>false,'endpoint'=>'https://openai.inference.de-txl.ionos.com/v1/chat/completions','primary_model'=>'meta-llama/Llama-3.3-70B-Instruct','fallback_model'=>'','instruction_text'=>self::defaultInstruction(),'timeout_seconds'=>120,'secret_set'=>false,'revision'=>0];
        $result=['configured'=>true,'enabled'=>(bool)$row['enabled'],'external_processing_confirmed'=>(bool)$row['external_processing_confirmed'],'endpoint'=>$row['endpoint'],'primary_model'=>$row['primary_model'],'fallback_model'=>$row['fallback_model']??'','instruction_text'=>$row['instruction_text'],'timeout_seconds'=>(int)$row['timeout_seconds'],'secret_set'=>$row['ciphertext']!==null,'revision'=>(int)$row['revision']];
        if ($withSecret) $result['secret']=$this->vault->decrypt((string)$row['ciphertext'],(string)$row['key_id'],(int)$row['crypto_version'],$actor->tenantId());
        return $result;
    }

    public function save(Actor $actor,array $input): void
    {
        $actor->requireAdmin(); $endpoint=trim((string)($input['endpoint']??'')); $parts=parse_url($endpoint);
        if ($parts===false || ($parts['scheme']??'')!=='https' || empty($parts['host']) || isset($parts['user'],$parts['pass'],$parts['fragment']) || strlen($endpoint)>768) throw new \InvalidArgumentException('KI-Endpunkt muss eine gültige HTTPS-Adresse ohne Zugangsdaten sein.');
        $model=$this->text((string)($input['primary_model']??''),'Primärmodell',190); $fallback=trim((string)($input['fallback_model']??'')); if ($fallback!=='' && mb_strlen($fallback)>190) throw new \InvalidArgumentException('Fallbackmodell ist zu lang.');
        $instruction=trim((string)($input['instruction_text']??'')); if (mb_strlen($instruction)<20 || mb_strlen($instruction)>50000 || !str_contains($instruction,'{{TEXT}}')) throw new \InvalidArgumentException('KI-Anweisung muss 20 bis 50.000 Zeichen lang sein und {{TEXT}} enthalten.');
        $timeout=filter_var($input['timeout_seconds']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>10,'max_range'=>300]]); if ($timeout===false) throw new \InvalidArgumentException('KI-Timeout muss zwischen 10 und 300 Sekunden liegen.');
        $enabled=!empty($input['enabled']); $confirmed=!empty($input['external_processing_confirmed']); if ($enabled && !$confirmed) throw new \InvalidArgumentException('Externe Verarbeitung muss ausdrücklich bestätigt werden.');
        $revision=filter_var($input['revision']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]); if ($revision===false) throw new \InvalidArgumentException('Ungültiger Konfigurationsstand.'); $secret=(string)($input['secret']??'');
        $this->db->beginTransaction();
        try {
            (new Access($this->db))->tenant($actor); $actor->requireAdmin();
            $stmt=$this->db->prepare('SELECT * FROM ai_configurations WHERE tenant_id=? FOR UPDATE'); $stmt->execute([$actor->tenantId()]); $current=$stmt->fetch();
            if ($current && (int)$current['revision']!==$revision) throw new \RuntimeException('KI-Konfiguration wurde zwischenzeitlich geändert. Bitte neu laden.');
            $cipher=$current['ciphertext']??null; $keyId=$current['key_id']??null; $version=$current['crypto_version']??null;
            if ($secret!=='') { $cipher=$this->vault->encrypt($secret,$actor->tenantId()); $keyId=$this->vault->keyId(); $version=1; }
            if ($enabled && $cipher===null) throw new \RuntimeException('Zum Aktivieren ist ein KI-API-Token erforderlich.');
            if ($current) { $stmt=$this->db->prepare('UPDATE ai_configurations SET enabled=?,external_processing_confirmed=?,endpoint=?,primary_model=?,fallback_model=?,instruction_text=?,timeout_seconds=?,ciphertext=?,key_id=?,crypto_version=?,revision=revision+1 WHERE tenant_id=? AND revision=?'); $stmt->execute([(int)$enabled,(int)$confirmed,$endpoint,$model,$fallback?:null,$instruction,$timeout,$cipher,$keyId,$version,$actor->tenantId(),$revision]); }
            else { $stmt=$this->db->prepare('INSERT INTO ai_configurations (tenant_id,enabled,external_processing_confirmed,endpoint,primary_model,fallback_model,instruction_text,timeout_seconds,ciphertext,key_id,crypto_version) VALUES (?,?,?,?,?,?,?,?,?,?,?)'); $stmt->execute([$actor->tenantId(),(int)$enabled,(int)$confirmed,$endpoint,$model,$fallback?:null,$instruction,$timeout,$cipher,$keyId,$version]); }
            $stmt=$this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id) VALUES (?,?,'ai.configuration.saved','ai_configuration',?)"); $stmt->execute([$actor->tenantId(),$actor->id(),(string)$actor->tenantId()]); $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }

    /** Fetches model identifiers server-side; the write-only token never reaches the browser. */
    public function models(Actor $actor): array
    {
        $actor->requireAdmin(); $public=$this->get($actor); if (!$public['configured'] || !$public['secret_set']) throw new \RuntimeException('KI-Konfiguration und API-Token zuerst speichern.'); $config=$this->get($actor,true);
        $parts=parse_url((string)$config['endpoint']); if ($parts===false) throw new \RuntimeException('KI-Endpunkt ist ungültig.');
        $path=(string)($parts['path']??'');
        if (preg_match('#/chat/completions/?$#D',$path)) $path=preg_replace('#/chat/completions/?$#D','/models',$path);
        elseif (preg_match('#/v1/?$#D',$path)) $path=rtrim($path,'/').'/models';
        else throw new \RuntimeException('Für den Modellabruf muss der Endpunkt auf /v1 oder /v1/chat/completions enden.');
        $url='https://'.$parts['host'].(isset($parts['port'])?':'.(int)$parts['port']:'').$path;
        try { $target=SourceConnectionTester::webDavTarget($url); } catch (\Throwable) { throw new \RuntimeException('IONOS-Endpunkt konnte nicht sicher aufgelöst werden.'); } if (!function_exists('curl_init')) throw new \RuntimeException('cURL ist für den Modellabruf nicht verfügbar.'); $body=''; $overflow=false; $curl=curl_init($target['url']);
        curl_setopt_array($curl,[CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$config['secret'],'Accept: application/json'],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[$target['resolve']],CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$body,&$overflow): int { if (strlen($body)+strlen($chunk)>1048576) { $overflow=true; return 0; } $body.=$chunk; return strlen($chunk); }]);
        try { $ok=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); if ($overflow) throw new \RuntimeException('Modellliste ist unerwartet groß.'); if ($ok===false || $status<200 || $status>=300) throw new \RuntimeException('IONOS-Modellliste konnte nicht geladen werden.'); } finally { curl_close($curl); }
        try { $decoded=json_decode($body,true,32,JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new \RuntimeException('IONOS-Modellliste hat ein ungültiges Format.'); }
        $models=[]; foreach ($decoded['data']??[] as $row) { $id=is_array($row)?trim((string)($row['id']??'')):''; if ($id!=='' && mb_strlen($id)<=190 && preg_match('/^[\pL\pN._:\/-]+$/uD',$id)) $models[$id]=$id; if (count($models)>=500) break; }
        if (!$models) throw new \RuntimeException('IONOS hat keine verwendbaren Modelle zurückgegeben.'); natcasesort($models); return array_values($models);
    }

    public static function defaultInstruction(): string
    {
        return <<<'PROMPT'
Analysiere den folgenden Dokumenttext. Antworte ausschließlich mit einem gültigen JSON-Objekt, ohne Markdown oder Erklärtext. Erfinde keine Informationen; unbekannte Werte sind null. Datumswerte: YYYY-MM-DD, Beträge: Dezimalzahlen, Währung: ISO-Code. Nutze für dokument.tags ausschließlich exakte Namen aus {{TAGS}}. Nutze für Buchungskonten ausschließlich {{ACCOUNTS}} und für Steuersätze ausschließlich {{VAT_RATES}}.

Erwartete Struktur: {"dokument":{"hauptgruppe":null,"untergruppe":null,"dokumenttyp":null,"titel":null,"tags":[],"deutschtext":null},"daten":{"dokumentdatum":null,"leistungsdatum":null,"faelligkeitsdatum":null},"parteien":{"absender":{"name":null,"firma":null,"adresse":null},"empfaenger":{"name":null,"firma":null,"adresse":null}},"referenzen":{"rechnungsnummer":null,"vertragsnummer":null,"kundennummer":null,"aktenzeichen":null},"betraege":{"waehrung":null,"netto":null,"mwst":null,"brutto":null},"buchungskonten":[],"inhalt":{"kurzzusammenfassung":null,"wichtige_punkte":[]},"extraktion":{"unsichere_felder":[],"hinweise":[]}}

Dokumenttext:
{{TEXT}}
PROMPT;
    }
    private function text(string $value,string $label,int $max): string { $value=trim($value); if ($value==='' || mb_strlen($value)>$max || preg_match('/[\x00-\x1f]/',$value)) throw new \InvalidArgumentException("$label fehlt oder ist ungültig."); return $value; }
}
