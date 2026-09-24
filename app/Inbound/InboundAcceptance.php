<?php
declare(strict_types=1);
namespace O8\Inbound;

use O8\Auth\Actor;
use O8\Documents\Documents;

/** Shared revision-pinned acceptance for manual and configured automatic imports. */
final class InboundAcceptance
{
    public function __construct(private \PDO $db,private string $root) {}

    public function proposal(Actor $actor,int $id): array
    {
        $item=(new InboundWorkbench($this->db,$this->root))->get($actor,$id);
        $inspection=$item['json_text']===null?null:(new AiSidecar())->inspectText($item['json_text'],(new Documents($this->db,$this->root))->tags($actor));
        $ai=$inspection['data']??[];
        $text=static fn($value):string=>is_scalar($value)?trim((string)$value):'';
        $sender=$text($ai['parteien']['absender']['firma']??'')?:$text($ai['parteien']['absender']['name']??'');
        $number=$text($ai['referenzen']['rechnungsnummer']??'');
        $date=$text($ai['daten']['dokumentdatum']??'');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)) $date='';
        $type=mb_strtolower($text($ai['dokument']['dokumenttyp']??''));
        $type=['rechnung'=>'invoice','invoice'=>'invoice','gutschrift'=>'credit_note','credit_note'=>'credit_note','vertrag'=>'contract','contract'=>'contract','certificate'=>'certificate','bescheinigung'=>'certificate'][$type]??'document';
        $amounts=is_array($ai['betraege']??null)?$ai['betraege']:[];
        $rawTags=$ai['dokument']['tags']??[]; if (is_string($rawTags)) $rawTags=[$rawTags];
        $rawTags=is_array($rawTags)?array_values(array_filter($rawTags,static fn($tag)=>is_string($tag)&&trim($tag)!=='')):[];
        $booking=(new AiBookingProposal())->prepare(
            ['sender'=>$sender,'number'=>$number,'date'=>$date,'currency'=>$text($amounts['waehrung']??'')?:'EUR','mode'=>'totals','accountId'=>'','items'=>[],
             'net'=>$text($amounts['netto']??''),'taxes'=>isset($amounts['mwst'])?[['rate'=>'','amount'=>$text($amounts['mwst'])]]:[]],
            $amounts,(new Documents($this->db,$this->root))->accounting($actor),is_array($ai['buchungskonten']??null)?$ai['buchungskonten']:[]);
        $aiTitle=$text($ai['dokument']['titel']??'')?:$text($ai['dokument']['beschreibung']??'');
        $reasons=[];
        if (empty($inspection['valid'])) $reasons[]='Gültige KI-JSON-Daten fehlen.';
        if ($aiTitle==='') $reasons[]='KI-Titel/Beschreibung fehlt.';
        elseif (mb_strlen($aiTitle)>255) $reasons[]='KI-Titel ist länger als 255 Zeichen.';
        if (!$this->validDate($date)) $reasons[]='Gültiges KI-Dokumentdatum fehlt.';
        if (in_array($item['ai_status'],['queued','running'],true)) $reasons[]='KI-Verarbeitung läuft noch.';
        $active=$this->db->prepare("SELECT 1 FROM inbound_ai_job_items WHERE tenant_id=? AND inbound_item_id=? AND status IN ('queued','running') LIMIT 1"); $active->execute([$actor->tenantId(),$id]);
        if ($active->fetchColumn()) $reasons[]='KI-Verarbeitung läuft noch.';
        $completeBooking=$booking['invoiceAvailable'] && $booking['invoiceWarning']==='';
        if ($completeBooking) {
            try { (new Documents($this->db,$this->root))->validateInvoiceInput($actor,$booking['invoice']); }
            catch (\RuntimeException $error) { $completeBooking=false; $booking['invoiceWarning']=$error->getMessage(); }
        }
        $totals=$booking['bookingTotals']??[];
        $partialBooking=!$completeBooking && count(array_filter([$totals['net']??null,$totals['tax']??null,$totals['gross']??null],static fn($value)=>$value!==null))>0
            ? ['sender'=>$sender,'number'=>$number,'date'=>$date,'currency'=>$booking['invoice']['currency'],'accountId'=>$booking['invoice']['accountId'],
                'net'=>$totals['net']??null,'tax'=>$totals['tax']??null,'gross'=>$totals['gross']??null] : null;
        foreach ([$sender,$number,$text($ai['referenzen']['vertragsnummer']??$ai['referenzen']['aktenzeichen']??'')] as $value) if (mb_strlen($value)>255) $reasons[]='Ein KI-Metadatenfeld ist länger als 255 Zeichen.';
        if (mb_strlen($text($ai['inhalt']['kurzzusammenfassung']??''))>10000) $reasons[]='KI-Zusammenfassung ist zu lang.';
        return ['id'=>(int)$item['id'],'revision'=>(int)$item['revision'],'ownerId'=>(int)$item['owner_id'],
            'originalName'=>$item['original_name'],'batchEligible'=>!$reasons,'batchReasons'=>array_values(array_unique($reasons)),
            'proposalToken'=>hash('sha256',json_encode([$item['json_text'],$item['text_content']],JSON_THROW_ON_ERROR)),
            'title'=>$aiTitle?:$item['original_name'],'sender'=>$sender,'date'=>$date,'documentType'=>$type,
            'reference'=>$number?:$text($ai['referenzen']['vertragsnummer']??$ai['referenzen']['aktenzeichen']??''),
            'memo'=>$text($ai['inhalt']['kurzzusammenfassung']??''),'matchedTags'=>$inspection['matchedTags']??[],'ignoredTags'=>$inspection['ignoredTags']??[],
            'warning'=>$inspection['error']??'', 'amounts'=>$amounts,'aiTags'=>$rawTags,...$booking,
            'invoiceComplete'=>$completeBooking,'partialInvoice'=>$partialBooking];
    }

    private function validDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$parts) && checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1]);
    }

    /** Read-only, bounded preflight. Ineligible items stay visible but cannot be selected. */
    public function batchPreview(Actor $actor,array $items): array
    {
        if (!$items || count($items)>25) throw new \RuntimeException('Bitte 1 bis 25 Eingangselemente je Prüfabruf angeben.');
        $rows=[]; $seen=[];
        foreach ($items as $item) {
            if (!is_array($item) || !is_scalar($item['id']??null) || !ctype_digit((string)$item['id']) || !is_scalar($item['revision']??null) || !ctype_digit((string)$item['revision'])) throw new \RuntimeException('Ungültige Eingangsauswahl.');
            $id=(int)$item['id']; if (isset($seen[$id])) throw new \RuntimeException('Doppelte Eingangsauswahl.'); $seen[$id]=true;
            try {
                $p=$this->proposal($actor,$id);
                if ((int)$p['revision']!==(int)$item['revision']) { $p['batchEligible']=false; $p['batchReasons'][]='Zwischenzeitlich geändert. Eingang neu laden.'; }
                $rows[]=array_intersect_key($p,array_flip(['id','revision','proposalToken','originalName','title','date','batchEligible','batchReasons','invoiceAvailable','invoiceComplete','partialInvoice','invoiceCalculations','matchedTags','ignoredTags','invoice','invoiceWarning','amounts','bookingTotals','bookingDerived']));
            } catch (\RuntimeException $error) {
                $rows[]=['id'=>$id,'revision'=>(int)$item['revision'],'batchEligible'=>false,'batchReasons'=>['Nicht verfügbar oder nicht lesbar. Bitte Eingang prüfen.']];
            }
        }
        return $rows;
    }

    /** Each progress request commits exactly one document using the existing atomic acceptance. */
    public function acceptBatchItem(Actor $actor,int $id,int $revision,string $token,array $shared,?callable $guard=null): int
    {
        $p=$this->proposal($actor,$id);
        if (!$p['batchEligible']) throw new \RuntimeException(implode(' ',$p['batchReasons']));
        if ($p['revision']!==$revision || !hash_equals($p['proposalToken'],$token)) throw new \RuntimeException('KI-Vorschlag wurde geändert. Bitte erneut prüfen.');
        // No client-provided titles, dates, owners or invoice bypasses in the batch endpoint.
        return $this->accept($actor,$id,$revision,[...$p,'invoice'=>$p['invoiceComplete']?$p['invoice']:null,
            'tags'=>$shared['tags']??[],'folders'=>$shared['folders']??[],'tagMode'=>$shared['tagMode']??'add'],$guard);
    }

    public function accept(Actor $actor,int $id,int $revision,array $input,?callable $guard=null): int
    {
        foreach (['title','sender','reference','date','memo','documentType','expired','notSearchable','ownerId'] as $field) if (isset($input[$field]) && !is_scalar($input[$field])) throw new \RuntimeException('Ungültiges Eingabeformat.');
        $workbench=new InboundWorkbench($this->db,$this->root); $item=$workbench->get($actor,$id);
        $folders=$input['folders']??[]; $tags=$input['tags']??[]; $mode=$input['tagMode']??'add';
        if (!is_array($folders) || count($folders)>100 || !is_array($tags) || count($tags)>100 || !in_array($mode,['add','replace'],true)) throw new \RuntimeException('Ungültige Ordner- oder Tagauswahl.');
        foreach ($folders as $folder) if (!is_scalar($folder) || !ctype_digit((string)$folder) || (int)$folder<1) throw new \RuntimeException('Ungültiger Zielordner.');
        $folders=array_values(array_unique(array_map('intval',$folders)));
        $sidecars=[];
        foreach (['json'=>'ai_source','txt'=>'ocr_text'] as $role=>$target) if ($item[$role==='json'?'has_json_sidecar':'has_text_sidecar']) {
            $file=$workbench->open($actor,$id,$role);
            try { $content=stream_get_contents($file['handle'],AiSidecar::MAX_BYTES+1); if ($content===false || strlen($content)>AiSidecar::MAX_BYTES) throw new \RuntimeException('Nebendatei nicht vollständig lesbar.'); $sidecars[$target]=$content; }
            finally { fclose($file['handle']); }
        }
        $snapshot=hash('sha256',json_encode(array_map(static fn($role)=>isset($sidecars[$role])?mb_scrub($sidecars[$role],'UTF-8'):null,['ai_source','ocr_text']),JSON_THROW_ON_ERROR));
        if (!is_string($input['proposalToken']??null) || !hash_equals($snapshot,$input['proposalToken'])) throw new \RuntimeException('KI-/OCR-Vorschlag wurde geändert. Bitte den Übernahmedialog erneut öffnen.');
        $temporary=tempnam(sys_get_temp_dir(),'o8-accept-'); if ($temporary===false) throw new \RuntimeException('Temporäre Ablage nicht verfügbar.');
        try {
            $file=$workbench->open($actor,$id); $out=fopen($temporary,'wb');
            try { if (!$out || stream_copy_to_stream($file['handle'],$out,Documents::MAX_BYTES+1)!==$file['size']) throw new \RuntimeException('Eingangsdatei unvollständig.'); }
            finally { fclose($file['handle']); if ($out) fclose($out); }
            $documents=new Documents($this->db,$this->root);
            return $documents->ingest($actor,$temporary,$item['original_name'],$item['source_kind'],function(int $document) use($actor,$id,$revision,$input,$item,$mode,$tags,$folders,$sidecars,$temporary,$workbench,$documents,$guard): void {
                if ($guard!==null) $guard();
                $lock=$this->db->prepare("SELECT revision,ai_status FROM inbound_items WHERE tenant_id=? AND id=? AND state='pending' FOR UPDATE"); $lock->execute([$actor->tenantId(),$id]); $current=$lock->fetch();
                if (!$current || (int)$current['revision']!==$revision) throw new \RuntimeException('Eingangselement wurde bereits übernommen oder geändert. Bitte neu laden.');
                $active=$this->db->prepare("SELECT 1 FROM inbound_ai_job_items WHERE tenant_id=? AND inbound_item_id=? AND status IN ('queued','running') LIMIT 1"); $active->execute([$actor->tenantId(),$id]);
                if (in_array($current['ai_status'],['queued','running'],true) || $active->fetchColumn()) throw new \RuntimeException('Bitte zuerst die laufende KI-Verarbeitung abschließen.');
                // Recheck owner and on-disk snapshots inside the same transaction as the document.
                $workbench->get($actor,$id);
                foreach (['original'=>null,'json'=>'ai_source','txt'=>'ocr_text'] as $role=>$key) {
                    if ($key!==null && !array_key_exists($key,$sidecars)) continue;
                    $file=$workbench->open($actor,$id,$role);
                    try { $hash=hash_init('sha256'); hash_update_stream($hash,$file['handle']); $actual=hash_final($hash); }
                    finally { fclose($file['handle']); }
                    $expected=$key===null?hash_file('sha256',$temporary):hash('sha256',$sidecars[$key]);
                    if (!hash_equals($expected,$actual)) throw new \RuntimeException('Eingangsdatei wurde geändert. Bitte neu laden.');
                }
                $inspection=isset($sidecars['ai_source'])?(new AiSidecar())->inspectText($sidecars['ai_source'],$documents->tags($actor)):null;
                $selected=$tags; if ($mode==='add') $selected=array_merge($selected,array_column($inspection['matchedTags']??[],'id'));
                $documents->save($actor,$document,1,[...$input,'tags'=>$selected]);
                $next=2;
                if (($input['invoice']??null)!==null && ($input['partialInvoice']??null)!==null) throw new \RuntimeException('Buchungsdaten sind widersprüchlich.');
                if (($input['invoice']??null)!==null) {
                    if (!is_array($input['invoice'])) throw new \RuntimeException('Ungültige Buchungsdaten.');
                    $documents->saveInvoice($actor,$document,$next++,$input['invoice']);
                } elseif (($input['partialInvoice']??null)!==null) {
                    if (!is_array($input['partialInvoice'])) throw new \RuntimeException('Ungültige unvollständige Buchungsdaten.');
                    $documents->savePartialInvoice($actor,$document,$next++,$input['partialInvoice']);
                }
                foreach ($folders as $folder) $documents->link($actor,$document,$next++,$folder,false);
                $owner=$input['ownerId']??$item['owner_id'];
                if (!is_scalar($owner) || !ctype_digit((string)$owner) || (int)$owner<1) throw new \RuntimeException('Ungültiger Besitzer.');
                if ((int)$owner!==$actor->id()) $documents->bulk($actor,['documents'=>[['id'=>$document,'revision'=>$next]],'ownerId'=>(string)$owner]);
                $update=$this->db->prepare('UPDATE documents SET ai_data=?,search_text=? WHERE tenant_id=? AND id=?');
                $update->execute([!empty($inspection['valid'])?$sidecars['ai_source']:null,isset($sidecars['ocr_text'])?mb_scrub($sidecars['ocr_text'],'UTF-8'):null,$actor->tenantId(),$document]);
                $update=$this->db->prepare("UPDATE inbound_items SET state='accepted',completed_at=UTC_TIMESTAMP(),revision=revision+1 WHERE tenant_id=? AND id=? AND revision=?"); $update->execute([$actor->tenantId(),$id,$revision]);
                if ($update->rowCount()!==1) throw new \RuntimeException('Eingangselement wurde geändert.');
                $update=$this->db->prepare("UPDATE source_items SET document_id=?,status='accepted' WHERE tenant_id=? AND id=? AND document_id IS NULL"); $update->execute([$document,$actor->tenantId(),$item['source_item_id']]);
                if ($update->rowCount()!==1) throw new \RuntimeException('Quelle wurde bereits übernommen.');
                $audit=$this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id) VALUES (?,?,'inbound.accepted','inbound_item',?)"); $audit->execute([$actor->tenantId(),$actor->id(),(string)$id]);
            },$sidecars);
        } finally { if (is_file($temporary)) unlink($temporary); }
    }
}
