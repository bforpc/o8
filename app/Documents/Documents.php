<?php
declare(strict_types=1);
namespace O8\Documents;
use O8\Auth\{Actor,Access};
use O8\Storage\Storage;

final class Documents
{
    public const MAX_BYTES=26214400;
    private Storage $storage;
    private bool $composingIngest=false;
    public function __construct(private \PDO $db,string $root) { $this->storage=new Storage($db,$root); }
    private function transaction(Actor $actor,callable $action): mixed
    {
        // Only the internal ingest completion may compose existing document actions.
        if ($this->composingIngest && $this->db->inTransaction()) { (new Access($this->db))->tenant($actor); return $action(); }
        $this->db->beginTransaction();
        try { (new Access($this->db))->tenant($actor); $result=$action(); $this->db->commit(); return $result; }
        catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    private function audit(Actor $actor,int $id,string $action): void
    {
        $s=$this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id) VALUES (?,?,?,'document',?)"); $s->execute([$actor->tenantId(),$actor->id(),$action,(string)$id]);
    }
    public function upload(Actor $actor,array $file): int
    {
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null) || !is_uploaded_file($file['tmp_name'])) throw new \RuntimeException('Upload nicht vollständig. Dateigröße und PHP-Uploadlimits prüfen.');
        return $this->ingest($actor,$file['tmp_name'],(string)($file['name']??'Dokument'));
    }
    /** Internal ingestion boundary. HTTP callers must use upload(), never accept source paths. */
    public function ingest(Actor $actor,string $source,string $name,string $sourceType='upload',?callable $complete=null,array $sidecars=[]): int
    {
        (new Access($this->db))->tenant($actor);
        if (!in_array($sourceType,['upload','demo','inbound','imap','webdav'],true)) throw new \RuntimeException('Ungültige Dokumentquelle.');
        foreach ($sidecars as $role=>$content) if (!in_array($role,['ai_source','ocr_text'],true) || !is_string($content) || strlen($content)>1048576) throw new \RuntimeException('Ungültige Nebendatei.');
        if (is_link($source) || !is_file($source) || !is_readable($source)) throw new \RuntimeException('Quelldatei nicht lesbar.');
        $size=filesize($source);
        if (!$size || $size>self::MAX_BYTES) throw new \RuntimeException('Datei muss zwischen 1 Byte und 25 MiB groß sein.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($source);
        $ext=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'][$mime]??null;
        if (!$ext) throw new \RuntimeException('Für diesen Prüfabschnitt sind nur PDF, JPEG und PNG zugelassen.');
        if ($ext==='pdf' && file_get_contents($source,false,null,0,5)!=='%PDF-') throw new \RuntimeException('Ungültiger PDF-Dateianfang.');
        if ($ext!=='pdf') { $image=@getimagesize($source); if (!$image || $image[0]*$image[1]>40000000) throw new \RuntimeException('Bild ungültig oder größer als 40 Megapixel.'); }
        $name=mb_strcut(preg_replace('/[\x00-\x1f\x7f]/u','',basename(str_replace('\\','/',$name)))??'',0,250,'UTF-8');
        if ($name==='') $name='Dokument.'.$ext;
        $relative=bin2hex(random_bytes(24)).'.'.$ext; $path=null; $part=null; $commitStarted=false; $attachments=[];
        $this->db->beginTransaction();
        try {
            (new Access($this->db))->tenant($actor);
            $location=$this->storage->location($actor);
            if (!$location) throw new \RuntimeException('Noch keine geprüfte Ablage zugewiesen. Bitte den Betreiber kontaktieren.');
            [, $target]=$this->storage->paths($location);
            $path=$target.'/'.$relative; $part=$path.'.part';
            $in=@fopen($source,'rb'); $out=@fopen($part,'x+b');
            if (!$in || !$out) { if ($in) fclose($in); if ($out) fclose($out); throw new \RuntimeException('Datei kann nicht sicher in die Ablage geschrieben werden.'); }
            try {
                $copied=stream_copy_to_stream($in,$out,self::MAX_BYTES+1);
                if ($copied!==$size || !fflush($out) || !fsync($out)) throw new \RuntimeException('Dateikopie unvollständig.');
                $this->storage->permissions($part,$location);
            } finally { fclose($in); fclose($out); }
            $hash=hash_file('sha256',$part);
            if (!$hash || !hash_equals($hash,hash_file('sha256',$source))) throw new \RuntimeException('Prüfsumme der Dateikopie stimmt nicht.');
            $this->storage->paths($location);
            if (file_exists($path) || !@rename($part,$path)) throw new \RuntimeException('Datei konnte nicht abgeschlossen werden.');
            $part=null;
            $s=$this->db->prepare("INSERT INTO documents (tenant_id,owner_id,title,document_date,source_type,in_inbox) VALUES (?,?,?,UTC_DATE(),?,1)"); $s->execute([$actor->tenantId(),$actor->id(),$name,$sourceType]); $id=(int)$this->db->lastInsertId();
            $s=$this->db->prepare("INSERT INTO document_files (tenant_id,document_id,role,storage_key,relative_path,original_name,mime_type,sha256,size_bytes) VALUES (?,?,'original','main',?,?,?,?,?)");
            $s->execute([$actor->tenantId(),$id,$relative,$name,$mime,$hash,$size]); $this->audit($actor,$id,'document.uploaded');
            foreach ($sidecars as $role=>$content) {
                $suffix=$role==='ai_source'?'json':'txt'; $key=bin2hex(random_bytes(24)).'.'.$suffix; $attachment=$target.'/'.$key;
                $handle=@fopen($attachment,'x+b'); if (!$handle) throw new \RuntimeException('Nebendatei konnte nicht gesichert werden.'); $attachments[]=$attachment;
                try { if (fwrite($handle,$content)!==strlen($content) || !fflush($handle) || !fsync($handle)) throw new \RuntimeException('Nebendatei unvollständig.'); $this->storage->permissions($attachment,$location); }
                finally { fclose($handle); }
                $s=$this->db->prepare('INSERT INTO document_files (tenant_id,document_id,role,storage_key,relative_path,original_name,mime_type,sha256,size_bytes) VALUES (?,?,?,\'main\',?,?,?,?,?)');
                $s->execute([$actor->tenantId(),$id,$role,$key,'KI-OCR.'.$suffix,$suffix==='json'?'application/json':'text/plain',hash('sha256',$content),strlen($content)]);
            }
            if ($complete) { $this->composingIngest=true; try { $complete($id); } finally { $this->composingIngest=false; } }
            $commitStarted=true; $this->db->commit(); return $id;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($part && is_file($part)) @unlink($part);
            // An uncertain COMMIT must never cause deletion of a referenced original.
            if (!$commitStarted && $path && is_file($path)) @unlink($path);
            if (!$commitStarted) foreach ($attachments as $attachment) if (is_file($attachment)) @unlink($attachment);
            throw $e;
        }
    }
    public function get(Actor $actor,int $id): array
    {
        (new Access($this->db))->tenant($actor);
        [$scope,$params]=$actor->documentScope(); $params[]=$id;
        $s=$this->db->prepare("SELECT d.*,f.original_name,f.mime_type,f.size_bytes FROM documents d LEFT JOIN document_files f ON f.tenant_id=d.tenant_id AND f.document_id=d.id AND f.role='original' WHERE $scope AND d.id=?");
        $s->execute($params); $doc=$s->fetch();
        if (!$doc) throw new \RuntimeException('Dokument nicht verfügbar.');
        $s=$this->db->prepare('SELECT tag_id FROM document_tags WHERE tenant_id=? AND document_id=?'); $s->execute([$actor->tenantId(),$id]); $doc['tags']=array_map('intval',$s->fetchAll(\PDO::FETCH_COLUMN));
        $s=$this->db->prepare('SELECT fd.folder_id FROM folder_documents fd JOIN folders f ON f.tenant_id=fd.tenant_id AND f.id=fd.folder_id WHERE fd.tenant_id=? AND fd.document_id=?'.($actor->row['role']==='admin'?'':' AND f.owner_id=?'));
        $s->execute($actor->row['role']==='admin'?[$actor->tenantId(),$id]:[$actor->tenantId(),$id,$actor->id()]); $doc['folders']=array_map('intval',$s->fetchAll(\PDO::FETCH_COLUMN));
        $doc['invoice']=$this->invoiceData($actor->tenantId(),$id);
        return $doc;
    }
    /** The M1 accounting model is document-owned; it is never duplicated by folder links. */
    public function accounting(Actor $actor): array
    {
        (new Access($this->db))->tenant($actor);
        $s=$this->db->prepare('SELECT id,code,name FROM accounting_accounts WHERE tenant_id=? AND active=1 ORDER BY code,id'); $s->execute([$actor->tenantId()]); $accounts=$s->fetchAll();
        $s=$this->db->prepare('SELECT rate FROM accounting_vat_rates WHERE tenant_id=? AND active=1 ORDER BY rate'); $s->execute([$actor->tenantId()]); $rates=array_map(static fn($rate):string=>(string)(float)$rate,$s->fetchAll(\PDO::FETCH_COLUMN));
        // The prepared M2 default is usable before the later settings section persists it.
        return ['accounts'=>$accounts,'vatRates'=>$rates?:['7','19']];
    }
    /** Tenant-wide accounting catalogue. Only admins may change it. */
    public function accountingSettings(Actor $actor): array
    {
        $actor->requireAdmin(); (new Access($this->db))->tenant($actor);
        $s=$this->db->prepare('SELECT framework,code,name,active FROM accounting_accounts WHERE tenant_id=? ORDER BY code,id'); $s->execute([$actor->tenantId()]); $accounts=$s->fetchAll();
        $s=$this->db->prepare('SELECT rate,active FROM accounting_vat_rates WHERE tenant_id=? ORDER BY rate'); $s->execute([$actor->tenantId()]); $rates=$s->fetchAll();
        return ['framework'=>$accounts[0]['framework']??'','accounts'=>$accounts,'vatRates'=>$rates?:[['rate'=>'7.00','active'=>1],['rate'=>'19.00','active'=>1]]];
    }
    public function saveAccountingSettings(Actor $actor,array $input): void
    {
        $actor->requireAdmin();
        $this->transaction($actor,function() use($actor,$input): void {
            $framework=trim((string)($input['framework']??'')); if (mb_strlen($framework)>100) throw new \RuntimeException('Kontorahmen maximal 100 Zeichen.');
            $rates=$input['vatRates']??[]; $accounts=$input['accounts']??[];
            if (!is_array($rates) || !is_array($accounts) || !$rates || count($rates)>100 || count($accounts)>1000) throw new \RuntimeException('Mindestens ein MWSt-Satz sowie gültige Kontenangaben erforderlich.');
            $rateValues=[]; foreach ($rates as $rate) { $basis=$this->money((string)$rate,2,'MWSt-Satz',false); if ($basis>10000) throw new \RuntimeException('MWSt-Satz muss zwischen 0 und 100 % liegen.'); $rateValues[$basis]=$this->decimal($basis,2); }
            if (count($rateValues)!==count($rates)) throw new \RuntimeException('MWSt-Sätze dürfen nicht doppelt vorkommen.');
            $accountValues=[]; foreach ($accounts as $account) { if (!is_array($account)) throw new \RuntimeException('Ungültige Kontenangabe.'); $code=trim((string)($account['code']??'')); $name=trim((string)($account['name']??'')); if (!preg_match('/^[A-Za-z0-9_.-]{1,32}$/D',$code) || $name==='' || mb_strlen($name)>190) throw new \RuntimeException('Jedes Konto braucht Nummer und Bezeichnung.'); $accountValues[$code]=$name; }
            if (count($accountValues)!==count($accounts)) throw new \RuntimeException('Kontonummern dürfen nicht doppelt vorkommen.');
            $this->accountingDefaults($actor->tenantId());
            $s=$this->db->prepare('SELECT rate FROM accounting_vat_rates WHERE tenant_id=?'); $s->execute([$actor->tenantId()]); $existingRates=array_map(static fn($rate):int=>(int)round((float)$rate*100),$s->fetchAll(\PDO::FETCH_COLUMN));
            foreach ($existingRates as $rate) if (!isset($rateValues[$rate])) { $s=$this->db->prepare('SELECT 1 FROM invoice_items WHERE tenant_id=? AND tax_rate=? UNION SELECT 1 FROM invoice_tax_totals WHERE tenant_id=? AND tax_rate=? LIMIT 1'); $s->execute([$actor->tenantId(),$this->decimal($rate,2),$actor->tenantId(),$this->decimal($rate,2)]); if ($s->fetchColumn()) throw new \RuntimeException('Verwendete MWSt-Sätze können nicht deaktiviert werden.'); $s=$this->db->prepare('UPDATE accounting_vat_rates SET active=0 WHERE tenant_id=? AND rate=?'); $s->execute([$actor->tenantId(),$this->decimal($rate,2)]); }
            $s=$this->db->prepare('INSERT INTO accounting_vat_rates (tenant_id,rate,active) VALUES (?,?,1) ON DUPLICATE KEY UPDATE active=1'); foreach ($rateValues as $rate) $s->execute([$actor->tenantId(),$rate]);
            $s=$this->db->prepare('SELECT id,code FROM accounting_accounts WHERE tenant_id=?'); $s->execute([$actor->tenantId()]); $existingAccounts=$s->fetchAll();
            foreach ($existingAccounts as $account) if (!array_key_exists($account['code'],$accountValues)) { $s=$this->db->prepare('SELECT 1 FROM document_invoices WHERE tenant_id=? AND account_id=? UNION SELECT 1 FROM invoice_items WHERE tenant_id=? AND account_id=? LIMIT 1'); $s->execute([$actor->tenantId(),$account['id'],$actor->tenantId(),$account['id']]); if ($s->fetchColumn()) throw new \RuntimeException('Verwendete Konten können nicht deaktiviert werden.'); $s=$this->db->prepare('UPDATE accounting_accounts SET active=0 WHERE tenant_id=? AND id=?'); $s->execute([$actor->tenantId(),$account['id']]); }
            $update=$this->db->prepare('UPDATE accounting_accounts SET framework=?,name=?,active=1 WHERE tenant_id=? AND code=?'); $insert=$this->db->prepare('INSERT INTO accounting_accounts (tenant_id,framework,code,name,active) VALUES (?,?,?,?,1)'); foreach ($accountValues as $code=>$name) { $update->execute([$framework,$name,$actor->tenantId(),$code]); if (!$update->rowCount()) $insert->execute([$actor->tenantId(),$framework,$code,$name]); }
            $s=$this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id) VALUES (?,?,'accounting.settings.updated','accounting','settings')"); $s->execute([$actor->tenantId(),$actor->id()]);
        });
    }
    private function invoiceData(int $tenant,int $id): ?array
    {
        $s=$this->db->prepare('SELECT sender,invoice_number,invoice_date,entry_mode,account_id,currency,net,tax,gross FROM document_invoices WHERE tenant_id=? AND document_id=?'); $s->execute([$tenant,$id]); $invoice=$s->fetch();
        if (!$invoice) return null;
        $invoice=['sender'=>$invoice['sender'],'number'=>$invoice['invoice_number'],'date'=>$invoice['invoice_date'],'mode'=>$invoice['entry_mode'],'accountId'=>$invoice['account_id']===null?'':(string)$invoice['account_id'],'currency'=>$invoice['currency'],'net'=>(string)$invoice['net'],'tax'=>(string)$invoice['tax'],'gross'=>(string)$invoice['gross'],'taxes'=>[],'items'=>[]];
        $s=$this->db->prepare('SELECT tax_rate,tax FROM invoice_tax_totals WHERE tenant_id=? AND document_id=? ORDER BY tax_rate'); $s->execute([$tenant,$id]); foreach ($s->fetchAll() as $row) $invoice['taxes'][]=['rate'=>(string)(float)$row['tax_rate'],'amount'=>(string)$row['tax']];
        $s=$this->db->prepare('SELECT description,quantity,unit_net,unit_gross,price_basis,account_id,tax_rate FROM invoice_items WHERE tenant_id=? AND document_id=? ORDER BY position_number'); $s->execute([$tenant,$id]); foreach ($s->fetchAll() as $row) $invoice['items'][]=['description'=>$row['description'],'quantity'=>(string)$row['quantity'],'unitNet'=>(string)$row['unit_net'],'unitGross'=>(string)$row['unit_gross'],'priceBasis'=>$row['price_basis'],'accountId'=>$row['account_id']===null?'':(string)$row['account_id'],'vatRate'=>(string)(float)$row['tax_rate']];
        return $invoice;
    }
    private function money(string $value,int $digits,string $label,bool $negative=true): int
    {
        $value=str_replace(',','.',trim($value));
        if (!preg_match('/^-?\d+(?:\.\d{1,'. $digits .'})?$/D',$value)) throw new \RuntimeException($label.' muss höchstens '.$digits.' Nachkommastellen haben.');
        $minus=str_starts_with($value,'-'); if ($minus && !$negative) throw new \RuntimeException($label.' darf nicht negativ sein.');
        [$whole,$fraction]=array_pad(explode('.',ltrim($value,'-'),2),2,'');
        if (strlen($whole)>12) throw new \RuntimeException($label.' ist zu groß.');
        $result=((int)$whole)*(10**$digits)+(int)str_pad($fraction,$digits,'0'); return $minus?-$result:$result;
    }
    private function decimal(int $value,int $digits): string
    {
        $sign=$value<0?'-':''; $value=abs($value); if (!$digits) return $sign.(string)$value;
        $scale=10**$digits; return $sign.intdiv($value,$scale).'.'.str_pad((string)($value%$scale),$digits,'0',STR_PAD_LEFT);
    }
    private function rounded(int $numerator,int $denominator): int
    {
        $sign=$numerator<0?-1:1; $value=abs($numerator); return $sign*intdiv($value+intdiv($denominator,2),$denominator);
    }
    private function currencyDigits(string $currency): int
    {
        if (!preg_match('/^[A-Z]{3}$/D',$currency)) throw new \RuntimeException('Ungültige Währung.');
        if (in_array($currency,['BHD','JOD','KWD','OMR','TND'],true)) return 3;
        if (in_array($currency,['CLP','JPY','KRW','VND'],true)) return 0;
        return 2;
    }
    private function accountingDefaults(int $tenant): void
    {
        $s=$this->db->prepare('INSERT IGNORE INTO accounting_vat_rates (tenant_id,rate,active) VALUES (?,7,1),(?,19,1)'); $s->execute([$tenant,$tenant]);
    }
    private function accountingIds(int $tenant,array $ids): void
    {
        $ids=array_values(array_unique(array_filter($ids,static fn($id)=>$id!==null)));
        if (!$ids) return;
        $s=$this->db->prepare('SELECT id FROM accounting_accounts WHERE tenant_id=? AND active=1 AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')'); $s->execute([$tenant,...$ids]);
        if (count($s->fetchAll(\PDO::FETCH_COLUMN))!==count($ids)) throw new \RuntimeException('Mindestens ein Buchungskonto ist nicht verfügbar.');
    }
    public function saveInvoice(Actor $actor,int $id,int $revision,array $input): void
    {
        $this->transaction($actor,function() use($actor,$id,$revision,$input): void {
            $doc=$this->get($actor,$id); if ($doc['deleted_at']) throw new \RuntimeException('Dokument zuerst wiederherstellen.');
            $this->accountingDefaults($actor->tenantId());
            extract($this->validateInvoiceInput($actor,$input),EXTR_SKIP);
            $s=$this->db->prepare('UPDATE documents SET sender=?,reference=?,document_date=?,in_inbox=0,revision=revision+1 WHERE tenant_id=? AND id=? AND revision=?'); $s->execute([$sender,$number,$date,$actor->tenantId(),$id,$revision]); if ($s->rowCount()!==1) throw new \RuntimeException('Dokument wurde zwischenzeitlich geändert. Bitte neu laden.');
            $s=$this->db->prepare('INSERT INTO document_invoices (tenant_id,document_id,sender,invoice_number,invoice_date,entry_mode,account_id,currency,net,tax,gross) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE sender=VALUES(sender),invoice_number=VALUES(invoice_number),invoice_date=VALUES(invoice_date),entry_mode=VALUES(entry_mode),account_id=VALUES(account_id),currency=VALUES(currency),net=VALUES(net),tax=VALUES(tax),gross=VALUES(gross)'); $s->execute([$actor->tenantId(),$id,$sender,$number,$date,$mode,$account,$currency,$this->decimal($net,$digits),$this->decimal($tax,$digits),$this->decimal($gross,$digits)]);
            $s=$this->db->prepare('DELETE FROM invoice_items WHERE tenant_id=? AND document_id=?'); $s->execute([$actor->tenantId(),$id]); $s=$this->db->prepare('INSERT INTO invoice_items (tenant_id,document_id,position_number,description,quantity,unit_net,unit_gross,price_basis,account_id,tax_rate,net,tax,gross) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'); foreach ($savedItems as $item) $s->execute([$actor->tenantId(),$id,...$item]);
            $s=$this->db->prepare('DELETE FROM invoice_tax_totals WHERE tenant_id=? AND document_id=?'); $s->execute([$actor->tenantId(),$id]); $s=$this->db->prepare('INSERT INTO invoice_tax_totals (tenant_id,document_id,tax_rate,tax) VALUES (?,?,?,?)'); foreach ($taxTotals as $rate=>$value) $s->execute([$actor->tenantId(),$id,$this->decimal((int)$rate,2),$this->decimal($value,$digits)]);
            $this->audit($actor,$id,'document.invoice.updated');
        });
    }
    /** Shared read-only validation for booking writes and entrance preflight. */
    public function validateInvoiceInput(Actor $actor,array $input): array
    {
        (new Access($this->db))->tenant($actor);
        $sender=trim((string)($input['sender']??'')); $number=trim((string)($input['number']??'')); $date=$this->date((string)($input['date']??'')); $mode=(string)($input['mode']??''); $currency=(string)($input['currency']??'EUR');
        if ($sender==='' || $date===null || mb_strlen($sender)>255 || mb_strlen($number)>255 || !in_array($mode,['items','totals'],true)) throw new \RuntimeException('Absender, Belegdatum und Erfassungsart sind erforderlich.');
        $digits=$this->currencyDigits($currency);
        $account=$input['accountId']??null; $account=$account===null || $account===''?null:(ctype_digit((string)$account)?(int)$account:null); if (($input['accountId']??'')!=='' && !$account) throw new \RuntimeException('Ungültiges Buchungskonto.');
        $items=$input['items']??[]; $taxes=$input['taxes']??[]; if (!is_array($items) || !is_array($taxes) || count($items)>200 || count($taxes)>20) throw new \RuntimeException('Zu viele Positionen oder Steuersummen.');
        $accountIds=[$account]; $taxTotals=[]; $savedItems=[]; $net=0;
        $rates=$this->db->prepare('SELECT rate FROM accounting_vat_rates WHERE tenant_id=? AND active=1'); $rates->execute([$actor->tenantId()]); $validRates=array_flip(array_map(static fn($rate):int=>(int)round((float)$rate*100),$rates->fetchAll(\PDO::FETCH_COLUMN)));
        if (!$validRates) { $count=$this->db->prepare('SELECT COUNT(*) FROM accounting_vat_rates WHERE tenant_id=?'); $count->execute([$actor->tenantId()]); if (!(int)$count->fetchColumn()) $validRates=[700=>0,1900=>1]; }
        if ($mode==='items') {
            if (!$items) throw new \RuntimeException('Bitte mindestens eine Position erfassen.');
            foreach ($items as $position=>$item) {
                if (!is_array($item)) throw new \RuntimeException('Ungültige Position.'); $description=trim((string)($item['description']??'')); if (mb_strlen($description)>500) throw new \RuntimeException('Positionsbezeichnung maximal 500 Zeichen.');
                $quantity=$this->money((string)($item['quantity']??''),3,'Menge',false); if ($quantity<1) throw new \RuntimeException('Menge muss größer als null sein.');
                $rate=$this->money((string)($item['vatRate']??''),2,'MWSt-Satz',false); if ($rate>10000 || !isset($validRates[$rate])) throw new \RuntimeException('MWSt-Satz ist im Mandanten nicht aktiv.');
                $basis=(string)($item['priceBasis']??'net'); if (!in_array($basis,['net','gross'],true)) throw new \RuntimeException('Ungültige Preisgrundlage.');
                $unit=$this->money((string)($basis==='gross'?($item['unitGross']??''):($item['unitNet']??'')),$digits,$basis==='gross'?'Brutto-Einzelpreis':'Netto-Einzelpreis');
                if ($basis==='gross') { $gross=$this->rounded($quantity*$unit,1000); $lineNet=$this->rounded($gross*10000,10000+$rate); $tax=$gross-$lineNet; $unitGross=$unit; $unitNet=$this->rounded($unit*10000,10000+$rate); }
                else { $lineNet=$this->rounded($quantity*$unit,1000); $tax=$this->rounded($lineNet*$rate,10000); $gross=$lineNet+$tax; $unitNet=$unit; $unitGross=$this->rounded($unit*(10000+$rate),10000); }
                $itemAccount=$item['accountId']??null; $itemAccount=$itemAccount===null || $itemAccount===''?null:(ctype_digit((string)$itemAccount)?(int)$itemAccount:null); if (($item['accountId']??'')!=='' && !$itemAccount) throw new \RuntimeException('Ungültiges Positionskonto.');
                $accountIds[]=$itemAccount; $net+=$lineNet; $taxTotals[$rate]=($taxTotals[$rate]??0)+$tax;
                $savedItems[]=[$position+1,$description,$this->decimal($quantity,3),$this->decimal($unitNet,$digits),$this->decimal($unitGross,$digits),$basis,$itemAccount,$this->decimal($rate,2),$this->decimal($lineNet,$digits),$this->decimal($tax,$digits),$this->decimal($gross,$digits)];
            }
        } else {
            $net=$this->money((string)($input['net']??''),$digits,'Nettosumme');
            foreach ($taxes as $row) { if (!is_array($row)) throw new \RuntimeException('Ungültige Steuersumme.'); $rate=$this->money((string)($row['rate']??''),2,'MWSt-Satz',false); $tax=$this->money((string)($row['amount']??''),$digits,'MWSt-Betrag'); if ($rate>10000 || !isset($validRates[$rate]) || ($rate===0 && $tax!==0)) throw new \RuntimeException('Ungültige Steuersumme.'); $taxTotals[$rate]=($taxTotals[$rate]??0)+$tax; }
        }
        $this->accountingIds($actor->tenantId(),$accountIds); $tax=array_sum($taxTotals); $gross=$net+$tax;
        return compact('sender','number','date','mode','account','currency','digits','net','tax','gross','savedItems','taxTotals');
    }
    public function listing(Actor $actor,array $input): array
    {
        (new Access($this->db))->tenant($actor);
        [$where,$params]=$actor->documentScope(); $scope=(string)($input['scope']??'inbox');
        if (!in_array($scope,['inbox','all','unfiled','trash'],true) && !ctype_digit($scope)) throw new \RuntimeException('Ungültiger Suchbereich.');
        $query=trim((string)($input['query']??''));
        if (mb_strlen($query)>250) throw new \RuntimeException('Suchtext maximal 250 Zeichen.');
        $searchKeys=['dateFrom','dateTo','amountFrom','amountTo','invoiceNumbers','accountCode','documentType','tag','notTag','owner','includeExpired','includeNotSearchable'];
        $searching=$query!=='' || ($input['resultView']??'')==='latest';
        foreach ($searchKeys as $searchKey) if (($input[$searchKey]??'')!=='') { $searching=true; break; }
        if (!$searching) $scopeRestriction=$scope==='trash'?' AND d.deleted_at IS NOT NULL':' AND d.deleted_at IS NULL';
        elseif ($scope==='trash') $scopeRestriction=' AND d.deleted_at IS NOT NULL';
        elseif ($scope==='inbox') $scopeRestriction=' AND d.deleted_at IS NULL AND d.in_inbox=1';
        elseif ($scope==='all') $scopeRestriction=' AND (d.in_inbox=0 OR d.deleted_at IS NOT NULL)';
        else $scopeRestriction=' AND d.deleted_at IS NULL AND d.in_inbox=0';
        $where.=$scopeRestriction;
        $latest=null;
        if (($input['resultView']??'')==='latest') { $latest=(int)($input['latestCount']??25); if ($latest<1 || $latest>10000) throw new \RuntimeException('Ungültige Anzahl für letzte Dokumente.'); }
        elseif (($input['resultView']??'')!=='' && ($input['resultView']??'')!=='all') throw new \RuntimeException('Ungültige Ergebnisansicht.');
        if (!$searching && $scope!=='trash') $where.=$scope==='inbox'?' AND d.in_inbox=1':' AND d.in_inbox=0';
        if (($input['includeExpired']??'')!=='1') $where.=' AND d.expired=0';
        if (($input['includeNotSearchable']??'')!=='1') $where.=' AND d.searchable=1';
        if ($scope==='unfiled') $where.=' AND NOT EXISTS (SELECT 1 FROM folder_documents fd WHERE fd.tenant_id=d.tenant_id AND fd.document_id=d.id)';
        if (ctype_digit($scope)) {
            $this->folder($actor,(int)$scope);
            $where.=' AND EXISTS (SELECT 1 FROM folder_documents fd WHERE fd.tenant_id=d.tenant_id AND fd.document_id=d.id AND fd.folder_id=?)';
            $params[]=(int)$scope;
        }
        foreach (preg_split('/\s+/u',$query,-1,PREG_SPLIT_NO_EMPTY) as $term) {
            if (preg_match('/^D([0-9]+)$/iD',$term,$m)) { $where.=' AND d.id=?'; $params[]=$m[1]; continue; }
            $where.=" AND (LOCATE(?,CONCAT_WS(' ',d.title,d.sender,d.reference,d.memo,d.search_text,CAST(d.ai_data AS CHAR),d.id) COLLATE utf8mb4_unicode_ci)>0 OR EXISTS (SELECT 1 FROM document_files sf WHERE sf.tenant_id=d.tenant_id AND sf.document_id=d.id AND sf.role='original' AND LOCATE(?,sf.original_name COLLATE utf8mb4_unicode_ci)>0) OR EXISTS (SELECT 1 FROM document_tags dt JOIN tags st ON st.tenant_id=dt.tenant_id AND st.id=dt.tag_id WHERE dt.tenant_id=d.tenant_id AND dt.document_id=d.id AND LOCATE(?,st.name COLLATE utf8mb4_unicode_ci)>0) OR EXISTS (SELECT 1 FROM document_invoices si WHERE si.tenant_id=d.tenant_id AND si.document_id=d.id AND LOCATE(?,si.invoice_number COLLATE utf8mb4_unicode_ci)>0))";
            array_push($params,$term,$term,$term,$term);
        }
        foreach (['dateFrom'=>'>=','dateTo'=>'<='] as $key=>$op) if (($input[$key]??'')!=='') { $date=$this->date((string)$input[$key]); $where.=" AND d.document_date $op ?"; $params[]=$date; }
        if (($input['dateFrom']??'')!=='' && ($input['dateTo']??'')!=='' && $input['dateFrom']>$input['dateTo']) throw new \RuntimeException('Datumsbereich ist umgekehrt.');
        if (($input['documentType']??'')!=='') { if (!preg_match('/^[a-z_]{1,32}$/D',(string)$input['documentType'])) throw new \RuntimeException('Ungültige Dokumentart.'); $where.=' AND d.document_type=?'; $params[]=$input['documentType']; }
        foreach (['amountFrom'=>'>=','amountTo'=>'<='] as $key=>$op) if (($input[$key]??'')!=='') { if (!is_numeric($input[$key]) || (float)$input[$key]<0) throw new \RuntimeException('Ungültiger Betragsfilter.'); $where.=" AND EXISTS (SELECT 1 FROM document_invoices ai WHERE ai.tenant_id=d.tenant_id AND ai.document_id=d.id AND ai.gross $op ?)"; $params[]=(float)$input[$key]; }
        if (($input['amountFrom']??'')!=='' && ($input['amountTo']??'')!=='' && (float)$input['amountFrom']>(float)$input['amountTo']) throw new \RuntimeException('Betragsbereich ist umgekehrt.');
        if (($input['invoiceNumbers']??'')!=='') { $numbers=array_values(array_filter(array_map('trim',preg_split('/[,;]+/u',(string)$input['invoiceNumbers'])))); if (count($numbers)>25) throw new \RuntimeException('Zu viele Rechnungsnummern.'); $parts=[]; foreach ($numbers as $number) { if (mb_strlen($number)>255) throw new \RuntimeException('Rechnungsnummer zu lang.'); $parts[]='ai.invoice_number LIKE ?'; $params[]='%'.$number.'%'; } if ($parts) $where.=' AND EXISTS (SELECT 1 FROM document_invoices ai WHERE ai.tenant_id=d.tenant_id AND ai.document_id=d.id AND ('.implode(' OR ',$parts).'))'; }
        if (($input['accountCode']??'')!=='') { $code=trim((string)$input['accountCode']); if ($code==='' || mb_strlen($code)>32) throw new \RuntimeException('Ungültiges Buchungskonto.'); $where.=' AND (EXISTS (SELECT 1 FROM document_invoices ai JOIN accounting_accounts aa ON aa.tenant_id=ai.tenant_id AND aa.id=ai.account_id WHERE ai.tenant_id=d.tenant_id AND ai.document_id=d.id AND aa.code=?) OR EXISTS (SELECT 1 FROM invoice_items ii JOIN accounting_accounts ia ON ia.tenant_id=ii.tenant_id AND ia.id=ii.account_id WHERE ii.tenant_id=d.tenant_id AND ii.document_id=d.id AND ia.code=?))'; array_push($params,$code,$code); }
        if (($input['owner']??'')!=='') {
            if ($actor->row['role']!=='admin' || !ctype_digit((string)$input['owner'])) throw new \RuntimeException('Besitzerfilter nur für Admins.');
            $where.=' AND d.owner_id=?'; $params[]=$input['owner'];
        }
        foreach (['tag'=>false,'notTag'=>true] as $key=>$not) if (($input[$key]??'')!=='') {
            if (!ctype_digit((string)$input[$key])) throw new \RuntimeException('Ungültiger Tagfilter.');
            $where.=' AND '.($not?'NOT ':'').'EXISTS (SELECT 1 FROM document_tags dt WHERE dt.tenant_id=d.tenant_id AND dt.document_id=d.id AND dt.tag_id=?)'; $params[]=$input[$key];
        }
        if (($input['tag']??'')!=='' && ($input['tag']??'')===($input['notTag']??'')) throw new \RuntimeException('Derselbe Tag kann nicht zugleich verlangt und ausgeschlossen werden.');
        $sort=['newest'=>'d.document_date DESC,d.id DESC','oldest'=>'d.document_date ASC,d.id ASC','added'=>'d.created_at DESC,d.id DESC','title'=>'d.title ASC,d.id ASC'][(string)($input['sort']??'newest')]??null;
        if (!$sort) throw new \RuntimeException('Ungültige Sortierung.');
        $size=(int)($input['size']??50); if (!in_array($size,[10,25,50,100,250],true)) throw new \RuntimeException('Ungültige Seitengröße.');
        // Count the fully filtered set first. "Latest N" is deliberately applied only afterwards.
        $s=$this->db->prepare("SELECT COUNT(*) FROM documents d WHERE $where"); $s->execute($params); $matchingTotal=(int)$s->fetchColumn();
        $total=$latest===null?$matchingTotal:min($matchingTotal,$latest);
        $page=max(1,min((int)($input['page']??1),max(1,(int)ceil($total/$size)))); $offset=($page-1)*$size;
        if ($latest===null) {
            $sql="SELECT d.id,d.title,d.sender,d.document_date,d.revision,d.in_inbox FROM documents d WHERE $where ORDER BY $sort LIMIT $size OFFSET $offset";
        } else {
            // The derived table materializes the latest matching IDs before presentation sorting/pagination.
            $sql="SELECT d.id,d.title,d.sender,d.document_date,d.revision,d.in_inbox FROM documents d JOIN (SELECT d.id FROM documents d WHERE $where ORDER BY d.created_at DESC,d.id DESC LIMIT $latest) latest ON latest.id=d.id ORDER BY $sort LIMIT $size OFFSET $offset";
        }
        $s=$this->db->prepare($sql); $s->execute($params); $rows=$s->fetchAll();
        $filteredFolderCounts=null; $filteredTrashCount=null; $filteredInboxCount=null; $filteredUnfiledCount=null;
        if (($input['folderCounts']??'')==='1') {
            $filteredFolderCounts=$this->filteredFolderCounts($actor,$where,$params,$latest,$scope,$total);
            $trashWhere=$where.' AND d.deleted_at IS NOT NULL';
            if ($latest===null) {
                $s=$this->db->prepare("SELECT COUNT(*) FROM documents d WHERE $trashWhere"); $s->execute($params);
            } else {
                $s=$this->db->prepare("SELECT COUNT(*) FROM documents d JOIN (SELECT d.id FROM documents d WHERE $where ORDER BY d.created_at DESC,d.id DESC LIMIT $latest) latest ON latest.id=d.id WHERE d.deleted_at IS NOT NULL"); $s->execute($params);
            }
            $filteredTrashCount=(int)$s->fetchColumn();
            $unfiledRestriction='d.deleted_at IS NULL AND d.in_inbox=0 AND NOT EXISTS (SELECT 1 FROM folder_documents fd WHERE fd.tenant_id=d.tenant_id AND fd.document_id=d.id)';
            if ($latest===null) {
                $s=$this->db->prepare("SELECT COUNT(*) FROM documents d WHERE $where AND $unfiledRestriction");
            } else {
                $s=$this->db->prepare("SELECT COUNT(*) FROM documents d JOIN (SELECT d.id FROM documents d WHERE $where ORDER BY d.created_at DESC,d.id DESC LIMIT $latest) latest ON latest.id=d.id WHERE $unfiledRestriction");
            }
            $s->execute($params); $filteredUnfiledCount=(int)$s->fetchColumn();
            $inboxWhere=str_replace($scopeRestriction,' AND d.deleted_at IS NULL AND d.in_inbox=1',$where);
            if ($latest===null) {
                $s=$this->db->prepare("SELECT COUNT(*) FROM documents d WHERE $inboxWhere"); $s->execute($params);
                $filteredInboxCount=(int)$s->fetchColumn();
            } else {
                $s=$this->db->prepare("SELECT COUNT(*) FROM (SELECT d.id FROM documents d WHERE $inboxWhere ORDER BY d.created_at DESC,d.id DESC LIMIT $latest) inbox_latest"); $s->execute($params);
                $filteredInboxCount=(int)$s->fetchColumn();
            }
        }
        if ($rows) {
            $ids=array_column($rows,'id'); $marks=implode(',',array_fill(0,count($ids),'?')); $byId=[];
            foreach ($rows as &$row) { $row['folder_count']=0; $row['folder_ids']=[]; $row['tag_names']=[]; $row['gross_amount']=null; $row['currency']=null; $byId[(int)$row['id']]=&$row; } unset($row);
            $folderSql='SELECT fd.document_id,fd.folder_id FROM folder_documents fd JOIN folders f ON f.tenant_id=fd.tenant_id AND f.id=fd.folder_id WHERE fd.tenant_id=? AND fd.document_id IN ('.$marks.')'.($actor->row['role']==='admin'?'':' AND f.owner_id=?').' ORDER BY f.name,f.id';
            $s=$this->db->prepare($folderSql); $s->execute($actor->row['role']==='admin'?[$actor->tenantId(),...$ids]:[$actor->tenantId(),...$ids,$actor->id()]);
            foreach ($s->fetchAll() as $item) { $byId[(int)$item['document_id']]['folder_ids'][]=(int)$item['folder_id']; ++$byId[(int)$item['document_id']]['folder_count']; }
            $s=$this->db->prepare('SELECT dt.document_id,t.name FROM document_tags dt JOIN tags t ON t.tenant_id=dt.tenant_id AND t.id=dt.tag_id WHERE dt.tenant_id=? AND dt.document_id IN ('.$marks.') ORDER BY t.name,t.id'); $s->execute([$actor->tenantId(),...$ids]);
            foreach ($s->fetchAll() as $item) $byId[(int)$item['document_id']]['tag_names'][]=$item['name'];
            $s=$this->db->prepare('SELECT document_id,gross,currency FROM document_invoices WHERE tenant_id=? AND document_id IN ('.$marks.')'); $s->execute([$actor->tenantId(),...$ids]);
            foreach ($s->fetchAll() as $item) { $byId[(int)$item['document_id']]['gross_amount']=(string)$item['gross']; $byId[(int)$item['document_id']]['currency']=$item['currency']; }
        }
        return ['rows'=>$rows,'total'=>$total,'matchingTotal'=>$matchingTotal,'latestLimit'=>$latest,'page'=>$page,'pages'=>max(1,(int)ceil($total/$size)),'folderCounts'=>$filteredFolderCounts,'trashCount'=>$filteredTrashCount,'inboxCount'=>$filteredInboxCount,'unfiledCount'=>$filteredUnfiledCount];
    }
    private function date(string $value): ?string
    {
        if ($value==='') return null;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$date || $date->format('Y-m-d')!==$value) throw new \RuntimeException('Ungültiges Datum.');
        return $value;
    }
    public function save(Actor $actor,int $id,int $revision,array $input): void
    {
        $this->transaction($actor,function() use($actor,$id,$revision,$input): void {
            $doc=$this->get($actor,$id); if ($doc['deleted_at']) throw new \RuntimeException('Dokument zuerst wiederherstellen.');
            $title=trim((string)($input['title']??'')); $sender=trim((string)($input['sender']??'')); $reference=trim((string)($input['reference']??'')); $memo=(string)($input['memo']??''); $type=(string)($input['documentType']??$doc['document_type']);
            if (!in_array($type,['document','invoice','credit_note','contract','certificate'],true)) throw new \RuntimeException('Ungültige Dokumentart.');
            if ($title==='' || mb_strlen($title)>255 || mb_strlen($sender)>255 || mb_strlen($reference)>255 || mb_strlen($memo)>10000) throw new \RuntimeException('Titel erforderlich; kurze Felder maximal 255, Notiz maximal 10000 Zeichen.');
            $tags=$input['tags']??[]; if (!is_array($tags) || count($tags)>100) throw new \RuntimeException('Ungültige Tagauswahl.');
            foreach ($tags as $tag) if (!(is_int($tag) || is_string($tag)) || !ctype_digit((string)$tag) || (int)$tag<1) throw new \RuntimeException('Ungültige Tag-ID.');
            $tags=array_values(array_unique(array_map('intval',$tags)));
            foreach ($tags as $tag) { $s=$this->db->prepare('SELECT 1 FROM tags WHERE tenant_id=? AND id=? AND active=1'); $s->execute([$actor->tenantId(),$tag]); if (!$s->fetchColumn()) throw new \RuntimeException('Tag nicht verfügbar.'); }
            $s=$this->db->prepare('UPDATE documents SET title=?,sender=?,reference=?,document_date=?,memo=?,document_type=?,expired=?,searchable=?,in_inbox=0,revision=revision+1 WHERE tenant_id=? AND id=? AND revision=?');
            $s->execute([$title,$sender,$reference,$this->date((string)($input['date']??'')),$memo,$type,($input['expired']??'')==='1'?1:0,($input['notSearchable']??'')==='1'?0:1,$actor->tenantId(),$id,$revision]);
            if ($s->rowCount()!==1) throw new \RuntimeException('Dokument wurde zwischenzeitlich geändert. Neu laden; nichts überschrieben.');
            $s=$this->db->prepare('DELETE FROM document_tags WHERE tenant_id=? AND document_id=?'); $s->execute([$actor->tenantId(),$id]);
            $s=$this->db->prepare('INSERT INTO document_tags (tenant_id,document_id,tag_id) VALUES (?,?,?)'); foreach ($tags as $tag) $s->execute([$actor->tenantId(),$id,$tag]);
            $this->audit($actor,$id,'document.updated');
        });
    }
    public function status(Actor $actor,int $id,int $revision,string $action): void
    {
        $this->transaction($actor,function() use($actor,$id,$revision,$action): void {
            $doc=$this->get($actor,$id);
            $change=match($action) { 'trash'=>'deleted_at=UTC_TIMESTAMP()', 'restore'=>'deleted_at=NULL', 'accept'=>'in_inbox=0', default=>throw new \RuntimeException('Unbekannte Dokumentaktion.') };
            if ($action==='accept' && $doc['deleted_at']) throw new \RuntimeException('Dokument zuerst wiederherstellen.');
            $s=$this->db->prepare("UPDATE documents SET $change,revision=revision+1 WHERE tenant_id=? AND id=? AND revision=?"); $s->execute([$actor->tenantId(),$id,$revision]);
            if ($s->rowCount()!==1) throw new \RuntimeException('Dokument zwischenzeitlich geändert. Bitte neu laden.');
            $this->audit($actor,$id,'document.'.$action);
        });
    }
    public function folders(Actor $actor): array
    {
        (new Access($this->db))->tenant($actor);
        $s=$this->db->prepare('SELECT id,name,parent_id,owner_id FROM folders WHERE tenant_id=?'.($actor->row['role']==='admin'?'':' AND owner_id=?').' ORDER BY name,id');
        $s->execute($actor->row['role']==='admin'?[$actor->tenantId()]:[$actor->tenantId(),$actor->id()]); return $s->fetchAll();
    }
    public function folderCounts(Actor $actor): array
    {
        $sql='SELECT fd.folder_id,fd.document_id FROM folder_documents fd JOIN documents d ON d.tenant_id=fd.tenant_id AND d.id=fd.document_id WHERE fd.tenant_id=? AND d.deleted_at IS NULL AND d.in_inbox=0'.($actor->row['role']==='admin'?'':' AND d.owner_id=?');
        $s=$this->db->prepare($sql); $s->execute($actor->row['role']==='admin'?[$actor->tenantId()]:[$actor->tenantId(),$actor->id()]);
        return $this->countFolderLinks($actor,$s->fetchAll());
    }
    public function allCount(Actor $actor): int
    {
        (new Access($this->db))->tenant($actor);
        [$scope,$params]=$actor->documentScope();
        $s=$this->db->prepare("SELECT COUNT(*) FROM documents d WHERE $scope AND d.deleted_at IS NULL AND d.in_inbox=0 AND d.expired=0 AND d.searchable=1");
        $s->execute($params);
        return (int)$s->fetchColumn();
    }
    public function unfiledCount(Actor $actor): int
    {
        (new Access($this->db))->tenant($actor);
        [$scope,$params]=$actor->documentScope();
        $s=$this->db->prepare("SELECT COUNT(*) FROM documents d WHERE $scope AND d.deleted_at IS NULL AND d.in_inbox=0 AND d.expired=0 AND d.searchable=1 AND NOT EXISTS (SELECT 1 FROM folder_documents fd WHERE fd.tenant_id=d.tenant_id AND fd.document_id=d.id)");
        $s->execute($params);
        return (int)$s->fetchColumn();
    }
    public function inboxCount(Actor $actor): int
    {
        (new Access($this->db))->tenant($actor);
        [$scope,$params]=$actor->documentScope();
        $s=$this->db->prepare("SELECT COUNT(*) FROM documents d WHERE $scope AND d.deleted_at IS NULL AND d.in_inbox=1 AND d.expired=0 AND d.searchable=1");
        $s->execute($params);
        return (int)$s->fetchColumn();
    }
    private function filteredFolderCounts(Actor $actor,string $where,array $params,?int $latest,string $scope,int $total): array
    {
        if ($scope==='trash' || $total===0) return $this->countFolderLinks($actor,[]);
        if ($latest===null) {
            $sql="SELECT fd.folder_id,fd.document_id FROM documents d JOIN folder_documents fd ON fd.tenant_id=d.tenant_id AND fd.document_id=d.id JOIN folders f ON f.tenant_id=fd.tenant_id AND f.id=fd.folder_id WHERE $where AND d.deleted_at IS NULL AND d.in_inbox=0";
        } else {
            $sql="SELECT fd.folder_id,fd.document_id FROM (SELECT d.tenant_id,d.id FROM documents d WHERE $where AND d.deleted_at IS NULL AND d.in_inbox=0 ORDER BY d.created_at DESC,d.id DESC LIMIT $latest) matched JOIN folder_documents fd ON fd.tenant_id=matched.tenant_id AND fd.document_id=matched.id JOIN folders f ON f.tenant_id=fd.tenant_id AND f.id=fd.folder_id WHERE 1=1";
        }
        if ($actor->row['role']!=='admin') { $sql.=' AND f.owner_id=?'; $params[]=$actor->id(); }
        $s=$this->db->prepare($sql); $s->execute($params);
        return $this->countFolderLinks($actor,$s->fetchAll());
    }
    private function countFolderLinks(Actor $actor,array $links): array
    {
        $folders=$this->folders($actor);
        $sets=[]; foreach ($folders as $folder) $sets[(int)$folder['id']]=[];
        foreach ($links as $link) {
            $folderId=(int)$link['folder_id']; $documentId=(int)$link['document_id'];
            if (isset($sets[$folderId])) $sets[$folderId][$documentId]=true;
        }
        return array_map('count',$sets);
    }
    public function trashCount(Actor $actor): int
    {
        (new Access($this->db))->tenant($actor);
        [$scope,$params]=$actor->documentScope();
        $s=$this->db->prepare("SELECT COUNT(*) FROM documents d WHERE $scope AND d.deleted_at IS NOT NULL");
        $s->execute($params);
        return (int)$s->fetchColumn();
    }
    private function folder(Actor $actor,int $id): array
    {
        foreach ($this->folders($actor) as $folder) if ((int)$folder['id']===$id) return $folder;
        throw new \RuntimeException('Ordner nicht verfügbar.');
    }
    private function folderIdsIncludingChildren(Actor $actor,int $id): array
    {
        $this->folder($actor,$id);
        $children=[];
        foreach ($this->folders($actor) as $folder) {
            $parent=$folder['parent_id']===null?'root':(string)(int)$folder['parent_id'];
            $children[$parent][]=(int)$folder['id'];
        }
        $result=[]; $queue=[$id]; $seen=[];
        while ($queue) {
            $current=(int)array_shift($queue);
            if (isset($seen[$current])) continue;
            $seen[$current]=true; $result[]=$current;
            foreach ($children[(string)$current]??[] as $child) $queue[]=$child;
        }
        return $result;
    }
    public function folderWrite(Actor $actor,string $action,int $id,string $name,?int $parentId=null): void
    {
        $this->transaction($actor,function() use($actor,$action,$id,$name,$parentId): void {
            if ($action!=='create') $this->folder($actor,$id);
            $name=trim($name);
            if ($action==='delete') {
                $s=$this->db->prepare('SELECT 1 FROM folder_documents fd JOIN documents d ON d.tenant_id=fd.tenant_id AND d.id=fd.document_id WHERE fd.tenant_id=? AND fd.folder_id=? AND d.deleted_at IS NULL LIMIT 1'); $s->execute([$actor->tenantId(),$id]);
                if ($s->fetchColumn()) throw new \RuntimeException('Ordner enthält aktive Dokumente.');
                $s=$this->db->prepare('SELECT 1 FROM folders WHERE tenant_id=? AND parent_id=? LIMIT 1'); $s->execute([$actor->tenantId(),$id]);
                if ($s->fetchColumn()) throw new \RuntimeException('Ordner enthält Unterordner.');
                $s=$this->db->prepare('DELETE FROM folders WHERE tenant_id=? AND id=?'); $s->execute([$actor->tenantId(),$id]);
            } else {
                if (!in_array($action,['create','rename','move'],true) || $name==='' || mb_strlen($name)>190) throw new \RuntimeException('Gültigen Ordnernamen angeben (maximal 190 Zeichen).');
                $effectiveParent=$parentId;
                if ($action==='rename') {
                    $existing=$this->folder($actor,$id); $effectiveParent=$existing['parent_id']===null?null:(int)$existing['parent_id'];
                } elseif ($effectiveParent!==null) {
                    $this->folder($actor,$effectiveParent);
                }
                if ($action==='move') {
                    if ($effectiveParent!==null && $effectiveParent===$id) throw new \RuntimeException('Ordner kann nicht in sich selbst verschoben werden.');
                    if ($effectiveParent!==null && in_array($effectiveParent,$this->folderIdsIncludingChildren($actor,$id),true)) throw new \RuntimeException('Ordner kann nicht in einen eigenen Unterordner verschoben werden.');
                }
                $s=$this->db->prepare('SELECT 1 FROM folders WHERE tenant_id=? AND name=? AND id<>? AND ((parent_id IS NULL AND ? IS NULL) OR parent_id=?)');
                $s->execute([$actor->tenantId(),$name,$id,$effectiveParent,$effectiveParent]); if ($s->fetchColumn()) throw new \RuntimeException('Ordnername bereits vorhanden.');
                if ($action==='create') { $s=$this->db->prepare('INSERT INTO folders (tenant_id,owner_id,parent_id,name) VALUES (?,?,?,?)'); $s->execute([$actor->tenantId(),$actor->id(),$effectiveParent,$name]); $id=(int)$this->db->lastInsertId(); }
                elseif ($action==='move') { $s=$this->db->prepare('UPDATE folders SET parent_id=?,name=? WHERE tenant_id=? AND id=?'); $s->execute([$effectiveParent,$name,$actor->tenantId(),$id]); }
                else { $s=$this->db->prepare('UPDATE folders SET name=? WHERE tenant_id=? AND id=?'); $s->execute([$name,$actor->tenantId(),$id]); }
            }
            $s=$this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id) VALUES (?,?,?,'folder',?)"); $s->execute([$actor->tenantId(),$actor->id(),'folder.'.$action,(string)$id]);
        });
    }
    public function link(Actor $actor,int $id,int $revision,int $folder,bool $remove): void
    {
        $this->transaction($actor,function() use($actor,$id,$revision,$folder,$remove): void {
            $doc=$this->get($actor,$id); $this->folder($actor,$folder);
            if ($doc['deleted_at']) throw new \RuntimeException('Dokument zuerst wiederherstellen.');
            $s=$this->db->prepare('UPDATE documents SET revision=revision+1'.($remove?'':',in_inbox=0').' WHERE tenant_id=? AND id=? AND revision=?'); $s->execute([$actor->tenantId(),$id,$revision]);
            if ($s->rowCount()!==1) throw new \RuntimeException('Dokument zwischenzeitlich geändert. Bitte neu laden.');
            $s=$this->db->prepare($remove?'DELETE FROM folder_documents WHERE tenant_id=? AND document_id=? AND folder_id=?':'INSERT IGNORE INTO folder_documents (tenant_id,document_id,folder_id) VALUES (?,?,?)'); $s->execute([$actor->tenantId(),$id,$folder]);
            $this->audit($actor,$id,$remove?'document.unlinked':'document.linked');
        });
    }
    /** Atomically applies the same permitted change to a revision-pinned document selection. */
    public function bulk(Actor $actor,array $input): int
    {
        $items=$input['documents']??null;
        if (!is_array($items) || !$items || count($items)>250) throw new \RuntimeException('Bitte zwischen 1 und 250 Dokumente auswählen.');
        $revisions=[];
        foreach ($items as $item) {
            if (!is_array($item) || !ctype_digit((string)($item['id']??null)) || !ctype_digit((string)($item['revision']??null)) || (int)$item['id']<1) throw new \RuntimeException('Ungültige Dokumentauswahl.');
            $id=(int)$item['id']; if (isset($revisions[$id])) throw new \RuntimeException('Ein Dokument wurde mehrfach ausgewählt.');
            $revisions[$id]=(int)$item['revision'];
        }
        $folderAction=(string)($input['folderAction']??'keep');
        $tagAction=(string)($input['tagAction']??'keep');
        $status=(string)($input['status']??'keep');
        if (!in_array($folderAction,['keep','remove-all','remove','add','move'],true) || !in_array($tagAction,['keep','add','remove','set'],true) || !in_array($status,['keep','trash','restore','accept'],true)) throw new \RuntimeException('Ungültige Massenaktion.');
        $folderId=$input['folderId']??null;
        $folderId=$folderId===null || $folderId===''?null:(ctype_digit((string)$folderId)?(int)$folderId:null);
        $sourceFolderId=$input['sourceFolderId']??null;
        $sourceFolderId=$sourceFolderId===null || $sourceFolderId===''?null:(ctype_digit((string)$sourceFolderId)?(int)$sourceFolderId:null);
        if (in_array($folderAction,['add','remove','move'],true) && !$folderId) throw new \RuntimeException('Bitte einen Ordner auswählen.');
        if ($folderAction==='move' && (!$sourceFolderId || $sourceFolderId===$folderId)) throw new \RuntimeException('Ungültiger Quellordner.');
        if ($folderAction!=='move' && $sourceFolderId!==null) throw new \RuntimeException('Quellordner passt nicht zur ausgewählten Aktion.');
        if (!in_array($folderAction,['add','remove','move'],true) && $folderId!==null) throw new \RuntimeException('Ordnerziel passt nicht zur ausgewählten Aktion.');
        $tagIds=$input['tags']??[];
        if (!is_array($tagIds) || count($tagIds)>100) throw new \RuntimeException('Ungültige Tagauswahl.');
        foreach ($tagIds as $tag) if (!ctype_digit((string)$tag) || (int)$tag<1) throw new \RuntimeException('Ungültige Tag-ID.');
        $tagIds=array_values(array_unique(array_map('intval',$tagIds)));
        if (in_array($tagAction,['add','remove'],true) && !$tagIds) throw new \RuntimeException('Bitte mindestens einen Tag auswählen.');
        $ownerId=$input['ownerId']??null;
        $ownerId=$ownerId===null || $ownerId===''?null:(ctype_digit((string)$ownerId)?(int)$ownerId:null);
        if (($input['ownerId']??'')!=='' && !$ownerId) throw new \RuntimeException('Ungültiger Besitzer.');
        if ($ownerId!==null) $actor->requireAdmin();
        $hasChange=$folderAction!=='keep' || $tagAction!=='keep' || $ownerId!==null || $status!=='keep';
        if (!$hasChange) throw new \RuntimeException('Bitte mindestens eine Änderung auswählen.');
        if ($status==='trash' && ($folderAction!=='keep' || $tagAction!=='keep' || $ownerId!==null)) throw new \RuntimeException('Papierkorb kann nicht mit anderen Massenänderungen kombiniert werden.');
        if ($status==='restore' && ($folderAction!=='keep' || $tagAction!=='keep' || $ownerId!==null)) throw new \RuntimeException('Wiederherstellen kann nicht mit anderen Massenänderungen kombiniert werden.');

        return $this->transaction($actor,function() use($actor,$revisions,$folderAction,$folderId,$sourceFolderId,$tagAction,$tagIds,$ownerId,$status): int {
            $ids=array_keys($revisions); $marks=implode(',',array_fill(0,count($ids),'?'));
            [$scope,$scopeParams]=$actor->documentScope();
            $s=$this->db->prepare("SELECT d.id,d.revision,d.deleted_at FROM documents d WHERE $scope AND d.id IN ($marks) FOR UPDATE");
            $s->execute([...$scopeParams,...$ids]); $documents=[];
            foreach ($s->fetchAll() as $document) $documents[(int)$document['id']]=$document;
            if (count($documents)!==count($ids)) throw new \RuntimeException('Mindestens ein ausgewähltes Dokument ist nicht verfügbar. Keine Änderung wurde vorgenommen.');
            foreach ($revisions as $id=>$revision) if ((int)$documents[$id]['revision']!==$revision) throw new \RuntimeException('Mindestens ein Dokument wurde zwischenzeitlich geändert. Bitte Suche neu laden.');

            if ($folderId!==null) $this->folder($actor,$folderId);
            if ($sourceFolderId!==null) $this->folder($actor,$sourceFolderId);
            if ($tagIds) {
                $s=$this->db->prepare('SELECT id FROM tags WHERE tenant_id=? AND active=1 AND id IN ('.implode(',',array_fill(0,count($tagIds),'?')).')');
                $s->execute([$actor->tenantId(),...$tagIds]);
                if (count($s->fetchAll(\PDO::FETCH_COLUMN))!==count($tagIds)) throw new \RuntimeException('Mindestens ein Tag ist nicht verfügbar.');
            }
            if ($ownerId!==null) {
                $s=$this->db->prepare('SELECT 1 FROM users WHERE tenant_id=? AND id=? AND active=1'); $s->execute([$actor->tenantId(),$ownerId]);
                if (!$s->fetchColumn()) throw new \RuntimeException('Der neue Besitzer ist nicht verfügbar.');
            }
            $deleted=array_filter($documents,fn(array $document):bool=>$document['deleted_at']!==null);
            if ($status==='trash' && $deleted) throw new \RuntimeException('Dokumente im Papierkorb können nicht erneut gelöscht werden.');
            if ($status==='restore' && count($deleted)!==count($documents)) throw new \RuntimeException('Wiederherstellen ist nur für Dokumente im Papierkorb möglich.');
            if (($folderAction!=='keep' || $tagAction!=='keep' || $ownerId!==null || $status==='accept') && $deleted) throw new \RuntimeException('Dokumente im Papierkorb bitte zuerst wiederherstellen.');

            $changed=0;
            $onlyUnlink=$folderAction==='remove' && $tagAction==='keep' && $ownerId===null && $status==='keep';
            foreach ($ids as $id) {
                $revision=$revisions[$id];
                if ($status==='trash') {
                    $s=$this->db->prepare('UPDATE documents SET deleted_at=UTC_TIMESTAMP(),revision=revision+1 WHERE tenant_id=? AND id=? AND revision=?'); $s->execute([$actor->tenantId(),$id,$revision]);
                } elseif ($status==='restore') {
                    $s=$this->db->prepare('UPDATE documents SET deleted_at=NULL,revision=revision+1 WHERE tenant_id=? AND id=? AND revision=?'); $s->execute([$actor->tenantId(),$id,$revision]);
                } else {
                    if ($folderAction==='remove-all') { $s=$this->db->prepare('DELETE FROM folder_documents WHERE tenant_id=? AND document_id=?'); $s->execute([$actor->tenantId(),$id]); }
                    elseif ($folderAction==='remove') {
                        $s=$this->db->prepare('DELETE FROM folder_documents WHERE tenant_id=? AND document_id=? AND folder_id=?'); $s->execute([$actor->tenantId(),$id,$folderId]);
                        if ($onlyUnlink && $s->rowCount()===0) continue;
                    }
                    elseif ($folderAction==='add') { $s=$this->db->prepare('INSERT IGNORE INTO folder_documents (tenant_id,folder_id,document_id) VALUES (?,?,?)'); $s->execute([$actor->tenantId(),$folderId,$id]); }
                    elseif ($folderAction==='move') {
                        $s=$this->db->prepare('DELETE FROM folder_documents WHERE tenant_id=? AND document_id=? AND folder_id=?'); $s->execute([$actor->tenantId(),$id,$sourceFolderId]);
                        $s=$this->db->prepare('INSERT IGNORE INTO folder_documents (tenant_id,folder_id,document_id) VALUES (?,?,?)'); $s->execute([$actor->tenantId(),$folderId,$id]);
                    }
                    if ($tagAction==='set') { $s=$this->db->prepare('DELETE FROM document_tags WHERE tenant_id=? AND document_id=?'); $s->execute([$actor->tenantId(),$id]); }
                    if (in_array($tagAction,['add','set'],true)) { $s=$this->db->prepare('INSERT IGNORE INTO document_tags (tenant_id,document_id,tag_id) VALUES (?,?,?)'); foreach ($tagIds as $tagId) $s->execute([$actor->tenantId(),$id,$tagId]); }
                    elseif ($tagAction==='remove') { $s=$this->db->prepare('DELETE FROM document_tags WHERE tenant_id=? AND document_id=? AND tag_id IN ('.implode(',',array_fill(0,count($tagIds),'?')).')'); $s->execute([$actor->tenantId(),$id,...$tagIds]); }
                    $sql='UPDATE documents SET in_inbox=0,revision=revision+1'.($ownerId===null?'':',owner_id=?').' WHERE tenant_id=? AND id=? AND revision=?';
                    $s=$this->db->prepare($sql); $parameters=$ownerId===null?[$actor->tenantId(),$id,$revision]:[$ownerId,$actor->tenantId(),$id,$revision]; $s->execute($parameters);
                }
                if ($s->rowCount()!==1 && $status!=='keep') throw new \RuntimeException('Dokument wurde zwischenzeitlich geändert. Keine Änderung wurde vorgenommen.');
                $this->audit($actor,$id,'document.bulk.'.$status);
                ++$changed;
            }
            if ($onlyUnlink && $changed===0) throw new \RuntimeException('Keine direkte Verknüpfung zu diesem Ordner vorhanden. Bitte die Dokumentauswahl neu laden oder den verknüpften Ordner auswählen. Es wurde nichts geändert.');
            return $changed;
        });
    }
    public function tags(Actor $actor): array
    {
        (new Access($this->db))->tenant($actor); $s=$this->db->prepare('SELECT id,name FROM tags WHERE tenant_id=? AND active=1 ORDER BY name,id'); $s->execute([$actor->tenantId()]); return $s->fetchAll();
    }
    public function createTag(Actor $actor,string $name): void
    {
        $actor->requireAdmin(); $name=trim($name);
        if ($name==='' || mb_strlen($name)>190) throw new \RuntimeException('Tagname erforderlich, maximal 190 Zeichen.');
        $this->transaction($actor,function() use($actor,$name): void {
            $s=$this->db->prepare('INSERT INTO tags (tenant_id,name,normalized_name) VALUES (?,?,?)'); $s->execute([$actor->tenantId(),$name,mb_strtolower($name)]);
            $s=$this->db->prepare("INSERT INTO audit_events (tenant_id,actor_id,action,entity_type,entity_id) VALUES (?,?,'tag.created','tag',?)"); $s->execute([$actor->tenantId(),$actor->id(),(string)$this->db->lastInsertId()]);
        });
    }
    public function preferences(Actor $actor,?array $input=null): array
    {
        (new Access($this->db))->tenant($actor);
        if ($input!==null) {
            $widths=$input['widths']??[]; $mode=$input['mode']??'system';
            if (!is_array($widths) || count($widths)!==4 || count(array_filter($widths,fn($n)=>is_numeric($n) && $n>=8 && $n<=65))!==4 || abs(array_sum($widths)-100)>.1 || !in_array($mode,['system','light','dark'],true)) throw new \RuntimeException('Ungültige Anzeigeeinstellungen.');
            $theme=$input['theme']??null;
            if ($theme!==null) {
                $validModes=['light','dark']; $validFamilies=['system','sans','serif','mono']; $validSizes=[100,110,125,150,175,200];
                if (!is_array($theme) || !in_array($theme['mode']??'', ['light','dark','system'],true) || !in_array($theme['density']??'', ['comfortable','compact'],true) || !in_array($theme['fontFamily']??'', $validFamilies,true) || !in_array((int)($theme['fontSize']??0),$validSizes,true)) throw new \RuntimeException('Ungültige Darstellungseinstellungen.');
                foreach ($validModes as $themeMode) foreach (['accent','background','surface'] as $color) if (!is_string($theme[$themeMode][$color]??null) || !preg_match('/^#[0-9a-f]{6}$/i',$theme[$themeMode][$color])) throw new \RuntimeException('Ungültige Darstellungsfarbe.');
                $theme['fontSize']=(int)$theme['fontSize'];
            }
            $this->transaction($actor,function() use($actor,$widths,$mode,$theme):void {
                $value=['widths'=>$widths,'mode'=>$mode]; if ($theme!==null) $value['theme']=$theme;
                $s=$this->db->prepare("INSERT INTO user_settings (tenant_id,user_id,setting_key,value_json) VALUES (?,?,'workspace',?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json)"); $s->execute([$actor->tenantId(),$actor->id(),json_encode($value,JSON_THROW_ON_ERROR)]);
            });
        }
        $s=$this->db->prepare("SELECT value_json FROM user_settings WHERE tenant_id=? AND user_id=? AND setting_key='workspace'"); $s->execute([$actor->tenantId(),$actor->id()]);
        $result=json_decode($s->fetchColumn()?:'{}',true)?:[]; $result['widths']=$result['widths']??[18,27,29,26]; $result['mode']=$result['mode']??'system';
        $result['theme']=$result['theme']??['mode'=>$result['mode'],'density'=>'comfortable','fontFamily'=>'system','fontSize'=>125,'light'=>['accent'=>'#326d62','background'=>'#f3f4f0','surface'=>'#ffffff'],'dark'=>['accent'=>'#8fc6b2','background'=>'#141b1a','surface'=>'#1d2725']];
        return $result;
    }
    /** Returns an already-open handle; path stays entirely server-side. */
    public function open(Actor $actor,int $id): array
    {
        return $this->transaction($actor,function() use($actor,$id): array {
            $doc=$this->get($actor,$id);
            $s=$this->db->prepare("SELECT f.*,s.root_path,s.linux_owner,s.linux_group,s.identity_json,t.public_id FROM document_files f JOIN storage_locations s ON s.tenant_id=f.tenant_id AND s.storage_key=f.storage_key JOIN tenants t ON t.id=f.tenant_id WHERE f.tenant_id=? AND f.document_id=? AND f.role='original' AND s.active=1");
            $s->execute([$actor->tenantId(),$id]); $f=$s->fetch(); if (!$f) throw new \RuntimeException('Original nicht verfügbar.');
            [, $target]=$this->storage->paths($f);
            if (!preg_match('/^[a-f0-9]{48}\.(pdf|jpg|png)$/D',$f['relative_path'])) throw new \RuntimeException('Dateipfad nicht freigegeben.');
            $path=$target.'/'.$f['relative_path']; clearstatcache(true,$path);
            $stat=lstat($path);
            if (!$stat || is_link($path) || !is_file($path) || $stat['size']!=(int)$f['size_bytes'] || $stat['dev']!==stat($target)['dev']) throw new \RuntimeException('Original fehlt oder wurde verändert.');
            $handle=@fopen($path,'rb'); if (!$handle) throw new \RuntimeException('Original nicht lesbar.');
            $opened=fstat($handle);
            if (!$opened || $opened['ino']!==$stat['ino'] || $opened['dev']!==$stat['dev']) { fclose($handle); throw new \RuntimeException('Datei während Zugriff geändert.'); }
            $hash=hash_init('sha256'); hash_update_stream($hash,$handle);
            if (!hash_equals($f['sha256']??'',hash_final($hash))) { fclose($handle); throw new \RuntimeException('Prüfsumme des Originals stimmt nicht. Datei wurde verändert.'); }
            rewind($handle);
            return ['handle'=>$handle,'name'=>$f['original_name'],'mime'=>$f['mime_type'],'size'=>(int)$f['size_bytes']];
        });
    }
}
