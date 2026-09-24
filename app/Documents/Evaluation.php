<?php
declare(strict_types=1);
namespace O8\Documents;

use O8\Auth\{Access,Actor};

/** Booking evaluation with monthly breakdown, separated by accounts. */
final class Evaluation
{
    public function __construct(private \PDO $db) {}

    /** Aggregate the selected DMS bookings after applying document-search filters. */
    public function bookingSummary(Actor $actor, array $input): array
    {
        (new Access($this->db))->tenant($actor);
        $dateFrom = $this->date((string)($input['dateFrom'] ?? ''));
        $dateTo = $this->date((string)($input['dateTo'] ?? ''));
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) throw new \RuntimeException('Datumsbereich ist umgekehrt.');
        if (!is_array($input['accounts'] ?? [])) throw new \RuntimeException('Ungültige Kontenauswahl.');
        $accountIds = $this->accountIds($actor, $input['accounts'] ?? []);
        [$scope, $params] = $actor->documentScope('d');
        $where=$scope.' AND d.deleted_at IS NULL AND d.in_inbox=0';
        $folderIds=$this->folderIds($actor,$input);
        if ($folderIds) {
            $marks=implode(',',array_fill(0,count($folderIds),'?'));
            $where.=" AND EXISTS (SELECT 1 FROM folder_documents fd WHERE fd.tenant_id=d.tenant_id AND fd.document_id=d.id AND fd.folder_id IN ($marks))";
            array_push($params,...$folderIds);
        }
        if (($input['includeExpired']??'')!=='1') $where.=' AND d.expired=0';
        if (($input['includeNotSearchable']??'')!=='1') $where.=' AND d.searchable=1';
        $query=trim((string)($input['query']??''));
        if (mb_strlen($query)>250) throw new \RuntimeException('Suchtext maximal 250 Zeichen.');
        foreach (preg_split('/\s+/u',$query,-1,PREG_SPLIT_NO_EMPTY) as $term) {
            if (preg_match('/^D([0-9]+)$/iD',$term,$m)) { $where.=' AND d.id=?'; $params[]=$m[1]; continue; }
            $where.=" AND (LOCATE(?,CONCAT_WS(' ',d.title,d.sender,d.reference,d.memo,d.search_text,CAST(d.ai_data AS CHAR),d.id) COLLATE utf8mb4_unicode_ci)>0 OR EXISTS (SELECT 1 FROM document_files sf WHERE sf.tenant_id=d.tenant_id AND sf.document_id=d.id AND sf.role='original' AND LOCATE(?,sf.original_name COLLATE utf8mb4_unicode_ci)>0) OR EXISTS (SELECT 1 FROM document_tags dt JOIN tags st ON st.tenant_id=dt.tenant_id AND st.id=dt.tag_id WHERE dt.tenant_id=d.tenant_id AND dt.document_id=d.id AND LOCATE(?,st.name COLLATE utf8mb4_unicode_ci)>0) OR EXISTS (SELECT 1 FROM document_invoices si WHERE si.tenant_id=d.tenant_id AND si.document_id=d.id AND LOCATE(?,si.invoice_number COLLATE utf8mb4_unicode_ci)>0))";
            array_push($params,$term,$term,$term,$term);
        }
        if ($dateFrom!==null) { $where.=' AND d.document_date>=?'; $params[]=$dateFrom; }
        if ($dateTo!==null) { $where.=' AND d.document_date<=?'; $params[]=$dateTo; }
        if (($input['documentType']??'')!=='') {
            if (!preg_match('/^[a-z_]{1,32}$/D',(string)$input['documentType'])) throw new \RuntimeException('Ungültige Dokumentart.');
            $where.=' AND d.document_type=?'; $params[]=$input['documentType'];
        }
        foreach (['amountFrom'=>'>=','amountTo'=>'<='] as $key=>$op) if (($input[$key]??'')!=='') {
            if (!is_numeric($input[$key]) || (float)$input[$key]<0) throw new \RuntimeException('Ungültiger Betragsfilter.');
            $where.=" AND EXISTS (SELECT 1 FROM document_invoices bi WHERE bi.tenant_id=d.tenant_id AND bi.document_id=d.id AND bi.gross $op ?)";
            $params[]=(float)$input[$key];
        }
        if (($input['amountFrom']??'')!=='' && ($input['amountTo']??'')!=='' && (float)$input['amountFrom']>(float)$input['amountTo']) throw new \RuntimeException('Betragsbereich ist umgekehrt.');
        if (($input['invoiceNumbers']??'')!=='') {
            $numbers=array_values(array_filter(array_map('trim',preg_split('/[,;]+/u',(string)$input['invoiceNumbers']))));
            if (count($numbers)>25) throw new \RuntimeException('Zu viele Rechnungsnummern.');
            $parts=[]; foreach ($numbers as $number) { if (mb_strlen($number)>255) throw new \RuntimeException('Rechnungsnummer zu lang.'); $parts[]='bi.invoice_number LIKE ?'; $params[]='%'.$number.'%'; }
            if ($parts) $where.=' AND EXISTS (SELECT 1 FROM document_invoices bi WHERE bi.tenant_id=d.tenant_id AND bi.document_id=d.id AND ('.implode(' OR ',$parts).'))';
        }
        if (($input['accountCode']??'')!=='') {
            $code=trim((string)$input['accountCode']); if ($code==='' || mb_strlen($code)>32) throw new \RuntimeException('Ungültiges Buchungskonto.');
            $where.=' AND (EXISTS (SELECT 1 FROM document_invoices bi JOIN accounting_accounts ba ON ba.tenant_id=bi.tenant_id AND ba.id=bi.account_id WHERE bi.tenant_id=d.tenant_id AND bi.document_id=d.id AND ba.code=?) OR EXISTS (SELECT 1 FROM invoice_items ii JOIN accounting_accounts ia ON ia.tenant_id=ii.tenant_id AND ia.id=ii.account_id WHERE ii.tenant_id=d.tenant_id AND ii.document_id=d.id AND ia.code=?))';
            array_push($params,$code,$code);
        }
        if ($accountIds) {
            $marks = implode(',', array_fill(0, count($accountIds), '?'));
            $where.=" AND EXISTS (SELECT 1 FROM document_invoices bi WHERE bi.tenant_id=d.tenant_id AND bi.document_id=d.id AND bi.account_id IN ($marks))";
            array_push($params,...$accountIds);
        }
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
        $view=(string)($input['resultView']??'all');
        if (!in_array($view,['all','latest'],true)) throw new \RuntimeException('Ungültige Ergebnisansicht.');
        $latest=null;
        if ($view==='latest') {
            $count=(string)($input['latestCount']??25);
            if (!ctype_digit($count) || (int)$count<1 || (int)$count>10000) throw new \RuntimeException('Ungültige Anzahl für letzte Dokumente.');
            $latest=(int)$count;
        }
        $size=(string)($input['size']??'all');
        if (!in_array($size,['all','10','25','50','100','250'],true)) throw new \RuntimeException('Ungültige Anzahl Datensätze.');
        $s=$this->db->prepare("SELECT COUNT(*) FROM documents d JOIN document_invoices ai ON ai.tenant_id=d.tenant_id AND ai.document_id=d.id WHERE $where"); $s->execute($params); $matchingTotal=(int)$s->fetchColumn();
        $recent=$latest===null?'':" JOIN (SELECT d.id FROM documents d JOIN document_invoices ai ON ai.tenant_id=d.tenant_id AND ai.document_id=d.id WHERE $where ORDER BY d.created_at DESC,d.id DESC LIMIT $latest) recent ON recent.id=d.id";
        $selectedWhere=$latest===null?" WHERE $where":'';
        $limit=$size==='all'?'':' LIMIT '.(int)$size;
        $selected="SELECT d.id FROM documents d JOIN document_invoices ai ON ai.tenant_id=d.tenant_id AND ai.document_id=d.id$recent$selectedWhere ORDER BY $sort$limit";
        $s=$this->db->prepare("SELECT DATE_FORMAT(ai.invoice_date,'%Y-%m') AS month,aa.id AS account_id,aa.code AS account_code,aa.name AS account_name,ai.currency,SUM(ai.net) AS total_net,SUM(ai.tax) AS total_tax,SUM(ai.gross) AS total_gross,COUNT(DISTINCT ai.document_id) AS document_count FROM ($selected) selected JOIN document_invoices ai ON ai.tenant_id=? AND ai.document_id=selected.id LEFT JOIN accounting_accounts aa ON aa.tenant_id=ai.tenant_id AND aa.id=ai.account_id GROUP BY DATE_FORMAT(ai.invoice_date,'%Y-%m'),aa.id,ai.currency ORDER BY month DESC,aa.code,ai.currency");
        $s->execute([...$params,$actor->tenantId()]);
        $result=$this->aggregate($s->fetchAll());
        $result['matchingTotal']=$matchingTotal;
        $result['selectedTotal']=min($matchingTotal,$latest??PHP_INT_MAX,$size==='all'?PHP_INT_MAX:(int)$size);
        return $result;
    }

    /**
     * Returns account totals for a given period (without monthly breakdown).
     */
    public function accountTotals(Actor $actor, array $input): array
    {
        (new Access($this->db))->tenant($actor);
        
        $dateFrom = $this->date((string)($input['dateFrom'] ?? ''));
        $dateTo = $this->date((string)($input['dateTo'] ?? ''));
        if ($dateFrom === null || $dateTo === null) {
            throw new \RuntimeException('Bitte einen gültigen Zeitraum angeben (von/bis).');
        }

        $accountIds = $this->accountIds($actor, $input['accounts'] ?? []);
        [$scope, $params] = $actor->documentScope('d');
        $params[] = $dateFrom;
        $params[] = $dateTo;

        $accountFilter = '';
        if ($accountIds) {
            $marks = implode(',', array_fill(0, count($accountIds), '?'));
            $accountFilter = " AND ai.account_id IN ($marks)";
            $params = [...$params, ...$accountIds];
        }

        $sql = "
            SELECT 
                aa.id AS account_id,
                aa.code AS account_code,
                aa.name AS account_name,
                ai.currency,
                SUM(ai.net) AS total_net,
                SUM(ai.tax) AS total_tax,
                SUM(ai.gross) AS total_gross,
                COUNT(DISTINCT d.id) AS document_count
            FROM document_invoices ai
            JOIN documents d ON d.tenant_id=ai.tenant_id AND d.id=ai.document_id
            LEFT JOIN accounting_accounts aa ON aa.tenant_id=ai.tenant_id AND aa.id=ai.account_id
            WHERE $scope
                AND ai.invoice_date >= ?
                AND ai.invoice_date <= ?
                AND d.deleted_at IS NULL
                $accountFilter
            GROUP BY aa.id, ai.currency
            ORDER BY aa.code, ai.currency
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return $this->aggregateAccounts($rows);
    }

    private function aggregate(array $rows): array
    {
        $months = [];
        $accounts = [];
        $currencies = [];
        $totals = ['net' => 0, 'tax' => 0, 'gross' => 0];

        foreach ($rows as $row) {
            $month = $row['month'];
            $accountId = (int)$row['account_id'];
            $accountCode = $row['account_code'] ?? '';
            $accountName = $row['account_name'] ?? '(ohne Konto)';
            $currency = $row['currency'] ?? 'EUR';
            $net = (float)$row['total_net'];
            $tax = (float)$row['total_tax'];
            $gross = (float)$row['total_gross'];
            $docCount = (int)$row['document_count'];

            if (!isset($months[$month])) {
                $months[$month] = ['net' => [], 'tax' => [], 'gross' => [], 'currencies' => [], 'document_count' => 0];
            }
            if (!isset($accounts[$accountId])) {
                $accounts[$accountId] = ['code' => $accountCode, 'name' => $accountName, 'totals' => []];
            }
            if (!isset($currencies[$currency])) {
                $currencies[$currency] = ['net' => 0, 'tax' => 0, 'gross' => 0];
            }

            $key = $accountId . '_' . $currency;
            if (!isset($months[$month]['net'][$key])) {
                $months[$month]['net'][$key] = 0;
                $months[$month]['tax'][$key] = 0;
                $months[$month]['gross'][$key] = 0;
                $months[$month]['currencies'][$currency] = true;
            }

            $months[$month]['net'][$key] += $net;
            $months[$month]['tax'][$key] += $tax;
            $months[$month]['gross'][$key] += $gross;
            $months[$month]['accounts'][$key] = ['id' => $accountId, 'code' => $accountCode, 'name' => $accountName];
            $months[$month]['document_count'] += $docCount;

            if (!isset($accounts[$accountId]['totals'][$currency])) {
                $accounts[$accountId]['totals'][$currency] = ['net' => 0, 'tax' => 0, 'gross' => 0, 'docCount' => 0];
            }
            $accounts[$accountId]['totals'][$currency]['net'] += $net;
            $accounts[$accountId]['totals'][$currency]['tax'] += $tax;
            $accounts[$accountId]['totals'][$currency]['gross'] += $gross;
            $accounts[$accountId]['totals'][$currency]['docCount'] += $docCount;

            $currencies[$currency]['net'] += $net;
            $currencies[$currency]['tax'] += $tax;
            $currencies[$currency]['gross'] += $gross;
        }

        return [
            'months' => $months,
            'accounts' => $accounts,
            'totals' => $totals,
            'currencies' => $currencies,
        ];
    }

    private function aggregateAccounts(array $rows): array
    {
        $accounts = [];
        $currencies = [];

        foreach ($rows as $row) {
            $accountId = (int)$row['account_id'];
            $accountCode = $row['account_code'] ?? '';
            $accountName = $row['account_name'] ?? '(ohne Konto)';
            $currency = $row['currency'] ?? 'EUR';
            $net = (float)$row['total_net'];
            $tax = (float)$row['total_tax'];
            $gross = (float)$row['total_gross'];
            $docCount = (int)$row['document_count'];

            if (!isset($accounts[$accountId])) {
                $accounts[$accountId] = ['code' => $accountCode, 'name' => $accountName, 'totals' => []];
            }
            if (!isset($currencies[$currency])) {
                $currencies[$currency] = ['net' => 0, 'tax' => 0, 'gross' => 0];
            }

            if (!isset($accounts[$accountId]['totals'][$currency])) {
                $accounts[$accountId]['totals'][$currency] = ['net' => 0, 'tax' => 0, 'gross' => 0, 'docCount' => 0];
            }
            $accounts[$accountId]['totals'][$currency]['net'] += $net;
            $accounts[$accountId]['totals'][$currency]['tax'] += $tax;
            $accounts[$accountId]['totals'][$currency]['gross'] += $gross;
            $accounts[$accountId]['totals'][$currency]['docCount'] += $docCount;

            $currencies[$currency]['net'] += $net;
            $currencies[$currency]['tax'] += $tax;
            $currencies[$currency]['gross'] += $gross;
        }

        return [
            'accounts' => $accounts,
            'currencies' => $currencies,
        ];
    }

    private function date(string $value): ?string
    {
        if ($value === '') return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new \RuntimeException('Ungültiges Datum.');
        return $value;
    }

    private function accountIds(Actor $actor, array $ids): array
    {
        if (!$ids) return [];
        foreach ($ids as $id) if (!ctype_digit((string)$id)) throw new \RuntimeException('Ungültiges Buchungskonto.');
        $ids = array_values(array_unique($ids));
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT id FROM accounting_accounts WHERE tenant_id=? AND id IN ($marks)");
        $stmt->execute([$actor->tenantId(), ...$ids]);
        $found=array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        if (count($found)!==count($ids)) throw new \RuntimeException('Buchungskonto nicht verfügbar.');
        return $found;
    }

    /** @return list<int> */
    private function folderIds(Actor $actor,array $input): array
    {
        $values=$input['folders']??[];
        if ($values==='' || $values===null) $values=[];
        if (!is_array($values)) throw new \RuntimeException('Ungültige Ordnerauswahl.');
        // Keep the former single-folder parameter compatible with existing saved URLs/API calls.
        if (($input['folder']??'')!=='') $values[]=$input['folder'];
        if (!$values) return [];
        if (count($values)>10000) throw new \RuntimeException('Zu viele Ordner ausgewählt.');
        $ids=[];
        foreach ($values as $value) {
            if (!is_scalar($value) || !ctype_digit((string)$value) || (int)$value<1) throw new \RuntimeException('Ungültige Ordnerauswahl.');
            $ids[(int)$value]=(int)$value;
        }
        $ids=array_values($ids);
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $s=$this->db->prepare("SELECT id,owner_id FROM folders WHERE tenant_id=? AND id IN ($marks)");
        $s->execute([$actor->tenantId(),...$ids]);
        $folders=$s->fetchAll();
        if (count($folders)!==count($ids)) throw new \RuntimeException('Ordner nicht verfügbar.');
        foreach ($folders as $folder) if ($actor->row['role']!=='admin' && (int)$folder['owner_id']!==$actor->id()) throw new \RuntimeException('Ordner nicht verfügbar.');
        return $ids;
    }

    private function documentTypes(array $types): array
    {
        $valid = ['document', 'invoice', 'credit_note', 'contract', 'certificate'];
        return array_values(array_filter($types, static fn($t) => in_array($t, $valid, true)));
    }

    private function tagId(Actor $actor, mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        if (!ctype_digit((string)$value)) return null;
        $id = (int)$value;
        $stmt = $this->db->prepare('SELECT 1 FROM tags WHERE tenant_id=? AND id=? AND active=1');
        $stmt->execute([$actor->tenantId(), $id]);
        return $stmt->fetchColumn() ? $id : null;
    }

    private function ownerId(Actor $actor, mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        if (!ctype_digit((string)$value)) return null;
        if ($actor->row['role'] !== 'admin') return null;
        $id = (int)$value;
        $stmt = $this->db->prepare('SELECT 1 FROM users WHERE tenant_id=? AND id=?');
        $stmt->execute([$actor->tenantId(), $id]);
        return $stmt->fetchColumn() ? $id : null;
    }
}
