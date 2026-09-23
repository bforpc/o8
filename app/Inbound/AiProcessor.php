<?php
declare(strict_types=1);
namespace O8\Inbound;

use O8\Auth\Actor;
use O8\Storage\Storage;

/** Bounded text extraction and OpenAI-compatible inference for one inbound item. */
final class AiProcessor
{
    private const MAX_TEXT_BYTES=524288;
    private const MAX_RESPONSE_BYTES=2097152;
    public function __construct(private \PDO $db,private string $root,private array $identity) {}

    public function process(Actor $actor,int $inboundId): array
    {
        $config=(new AiConfiguration($this->db,$this->identity))->get($actor,true);
        if (!$config['enabled'] || !$config['external_processing_confirmed']) throw new \RuntimeException('ai_disabled');
        $workbench=new InboundWorkbench($this->db,$this->root); $item=$workbench->get($actor,$inboundId);
        $text='';
        if ($item['text_content']!==null) $text=trim((string)$item['text_content']);
        if (mb_strlen(preg_replace('/\s+/u','',$text)??'')<100) {
            $file=$workbench->open($actor,$inboundId,'original');
            try { $text=$this->extract($file['handle'],(string)$file['mime']); } finally { fclose($file['handle']); }
        }
        if (mb_strlen(preg_replace('/\s+/u','',$text)??'')<100) throw new \RuntimeException('text_too_short');
        $catalogue=$this->catalogue($actor->tenantId());
        $prompt=str_replace(['{{TEXT}}','{{TAGS}}','{{ACCOUNTS}}','{{VAT_RATES}}'],[mb_strcut($text,0,self::MAX_TEXT_BYTES,'UTF-8'),json_encode(array_column($catalogue['tags'],'name'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),json_encode($catalogue['accounts'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),json_encode($catalogue['rates'],JSON_THROW_ON_ERROR)],(string)$config['instruction_text']);
        $error=null; $json=null; $inspection=null;
        foreach (array_filter([$config['primary_model'],$config['fallback_model']]) as $model) {
            try { $json=$this->content($this->request($config,(string)$model,$prompt)); $inspection=(new AiSidecar())->inspectText($json,$catalogue['tags']); if (!$inspection['valid']) throw new \RuntimeException('invalid_ai_json'); $error=null; break; }
            catch (\Throwable $problem) { $error=$problem; }
        }
        if ($inspection===null || !$inspection['valid']) throw new \RuntimeException($error instanceof \RuntimeException?$error->getMessage():'ai_request_failed');
        return ['json'=>json_encode($inspection['data'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),'data'=>$inspection['data'],'matchedTags'=>$inspection['matchedTags'],'ignoredTags'=>$inspection['ignoredTags']];
    }

    public function store(Actor $actor,int $inboundId,string $json): void
    {
        $storage=new Storage($this->db,$this->root); $location=$storage->location($actor); if (!$location) throw new \RuntimeException('storage_missing'); [, $target]=$storage->paths($location);
        $directory=$target.'/inbound'; if (!is_dir($directory) && !@mkdir($directory,0750)) throw new \RuntimeException('storage_unavailable'); if (is_link($directory) || realpath($directory)!==$directory) throw new \RuntimeException('storage_unavailable'); $storage->permissions($directory,$location,true);
        $relative='inbound/'.bin2hex(random_bytes(24)).'.json'; $path=$target.'/'.$relative; $part=$path.'.part'; $handle=@fopen($part,'x+b'); if (!$handle) throw new \RuntimeException('storage_write_failed');
        try { if (fwrite($handle,$json)!==strlen($json) || !fflush($handle) || !fsync($handle)) throw new \RuntimeException('storage_write_failed'); } finally { fclose($handle); }
        $sha=hash('sha256',$json); if (!hash_equals($sha,(string)hash_file('sha256',$part))) { @unlink($part); throw new \RuntimeException('storage_verify_failed'); } $storage->permissions($part,$location); if (!@rename($part,$path)) { @unlink($part); throw new \RuntimeException('storage_write_failed'); }
        $old=null; $this->db->beginTransaction();
        try {
            $stmt=$this->db->prepare("SELECT relative_path FROM inbound_files WHERE tenant_id=? AND inbound_item_id=? AND role='json' FOR UPDATE"); $stmt->execute([$actor->tenantId(),$inboundId]); $old=$stmt->fetchColumn();
            $name=pathinfo((string)$this->db->query('SELECT original_name FROM inbound_items WHERE tenant_id='.(int)$actor->tenantId().' AND id='.(int)$inboundId)->fetchColumn(),PATHINFO_FILENAME).'.json';
            $stmt=$this->db->prepare("INSERT INTO inbound_files (tenant_id,inbound_item_id,role,storage_key,relative_path,original_name,mime_type,sha256,size_bytes) VALUES (?,?, 'json','main',?,?,'application/json',?,?) ON DUPLICATE KEY UPDATE relative_path=VALUES(relative_path),original_name=VALUES(original_name),sha256=VALUES(sha256),size_bytes=VALUES(size_bytes),created_at=UTC_TIMESTAMP()"); $stmt->execute([$actor->tenantId(),$inboundId,$relative,$name,$sha,strlen($json)]);
            $stmt=$this->db->prepare("UPDATE inbound_items SET ai_status='ready',has_json_sidecar=1,json_valid=1,error_code=NULL,revision=revision+1 WHERE tenant_id=? AND id=? AND state='pending'"); $stmt->execute([$actor->tenantId(),$inboundId]); if ($stmt->rowCount()!==1) throw new \RuntimeException('inbound_changed');
            $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); @unlink($path); throw $error; }
        if (is_string($old) && $old!==$relative && preg_match('#^inbound/[a-f0-9]{48}\.json$#D',$old)) @unlink($target.'/'.$old);
    }

    private function catalogue(int $tenant): array
    {
        $stmt=$this->db->prepare('SELECT id,name FROM tags WHERE tenant_id=? AND active=1 ORDER BY name'); $stmt->execute([$tenant]); $tags=$stmt->fetchAll();
        $stmt=$this->db->prepare('SELECT code,name FROM accounting_accounts WHERE tenant_id=? AND active=1 ORDER BY code'); $stmt->execute([$tenant]); $accounts=$stmt->fetchAll();
        $stmt=$this->db->prepare('SELECT rate FROM accounting_vat_rates WHERE tenant_id=? AND active=1 ORDER BY rate'); $stmt->execute([$tenant]); $rates=array_map('floatval',$stmt->fetchAll(\PDO::FETCH_COLUMN));
        return compact('tags','accounts','rates');
    }

    private function extract($input,string $mime): string
    {
        $directory=sys_get_temp_dir().'/o8-ai-'.bin2hex(random_bytes(10)); if (!mkdir($directory,0700)) throw new \RuntimeException('extract_failed'); $source=$directory.'/source.'.($mime==='application/pdf'?'pdf':($mime==='image/png'?'png':'jpg'));
        try {
            $output=fopen($source,'x+b'); if (!$output) throw new \RuntimeException('extract_failed'); $bytes=stream_copy_to_stream($input,$output,26214401); fclose($output); if ($bytes===false || $bytes<1 || $bytes>26214400) throw new \RuntimeException('extract_failed');
            if ($mime==='application/pdf') {
                $textFile=$directory.'/text.txt'; $this->command(['pdftotext','-layout',$source,$textFile],35); $text=is_file($textFile)?(string)file_get_contents($textFile):'';
                if (mb_strlen(preg_replace('/\s+/u','',mb_scrub($text,'UTF-8'))??'')>=100) return mb_scrub($text,'UTF-8');
                $prefix=$directory.'/page'; $this->command(['pdftoppm','-f','1','-l','20','-r','200','-jpeg',$source,$prefix],50); $parts=[];
                foreach (glob($prefix.'-*.jpg')?:[] as $image) { $parts[]=$this->command(['tesseract',$image,'stdout','-l','deu'],35,true); if (strlen(implode("\n",$parts))>=self::MAX_TEXT_BYTES) break; }
                return mb_scrub(mb_strcut(implode("\n\n",$parts),0,self::MAX_TEXT_BYTES,'UTF-8'),'UTF-8');
            }
            return mb_scrub(mb_strcut($this->command(['tesseract',$source,'stdout','-l','deu'],45,true),0,self::MAX_TEXT_BYTES,'UTF-8'),'UTF-8');
        } finally { foreach (glob($directory.'/*')?:[] as $file) if (is_file($file)) @unlink($file); @rmdir($directory); }
    }

    private function command(array $command,int $timeout,bool $capture=false): string
    {
        $pipes=[]; $process=@proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','a']],$pipes,null,[]); if (!is_resource($process)) throw new \RuntimeException('extract_tool_missing'); fclose($pipes[0]); stream_set_blocking($pipes[1],false); $output=''; $deadline=microtime(true)+$timeout;
        try { do { $output.=stream_get_contents($pipes[1]); if (strlen($output)>self::MAX_TEXT_BYTES) throw new \RuntimeException('extract_too_large'); $status=proc_get_status($process); if (!$status['running']) break; if (microtime(true)>$deadline) { proc_terminate($process,9); throw new \RuntimeException('extract_timeout'); } usleep(50000); } while (true); $output.=stream_get_contents($pipes[1]); fclose($pipes[1]); $code=proc_close($process); $process=null; if ($code!==0) throw new \RuntimeException('extract_failed'); return $capture?$output:''; }
        finally { if (isset($pipes[1]) && is_resource($pipes[1])) fclose($pipes[1]); if (is_resource($process)) { proc_terminate($process,9); proc_close($process); } }
    }

    private function request(array $config,string $model,string $prompt): string
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('curl_unavailable'); $target=SourceConnectionTester::webDavTarget((string)$config['endpoint']); $response=''; $overflow=false;
        $payload=json_encode(['model'=>$model,'messages'=>[['role'=>'system','content'=>'Du analysierst Dokumente. Antworte ausschließlich mit einem gültigen JSON-Objekt.'],['role'=>'user','content'=>$prompt]],'temperature'=>0],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $curl=curl_init($target['url']); curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$config['secret'],'Content-Type: application/json','Accept: application/json'],CURLOPT_POSTFIELDS=>$payload,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>(int)$config['timeout_seconds'],CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[$target['resolve']],CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$response,&$overflow): int { if (strlen($response)+strlen($chunk)>self::MAX_RESPONSE_BYTES) { $overflow=true; return 0; } $response.=$chunk; return strlen($chunk); }]);
        try { $ok=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); $errno=curl_errno($curl); if ($overflow) throw new \RuntimeException('ai_response_too_large'); if ($ok===false) throw new \RuntimeException($this->curlErrorCode($errno)); if ($status<200 || $status>=300) throw new \RuntimeException('ai_http_error'); } finally { curl_close($curl); }
        return $response;
    }

    private function curlErrorCode(int $errno): string
    {
        if ($errno===CURLE_OPERATION_TIMEDOUT) return 'ai_timeout';
        if ($errno===CURLE_COULDNT_RESOLVE_HOST) return 'ai_dns_failed';
        $tls=array_filter([defined('CURLE_SSL_CONNECT_ERROR')?CURLE_SSL_CONNECT_ERROR:null,defined('CURLE_PEER_FAILED_VERIFICATION')?CURLE_PEER_FAILED_VERIFICATION:null,defined('CURLE_SSL_CACERT_BADFILE')?CURLE_SSL_CACERT_BADFILE:null],static fn($value):bool=>$value!==null);
        return in_array($errno,$tls,true)?'ai_tls_failed':'ai_connection_failed';
    }

    private function content(string $response): string
    {
        try { $envelope=json_decode($response,true,64,JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new \RuntimeException('invalid_ai_response'); }
        $content=$envelope['choices'][0]['message']['content']??null; if (!is_string($content)) throw new \RuntimeException('invalid_ai_response'); $content=trim($content);
        if (preg_match('/^```(?:json)?\s*(\{.*\})\s*```$/isD',$content,$match)) $content=trim($match[1]);
        return $content;
    }
}
