<?php
declare(strict_types=1);
namespace O8\Inbound;

/** Map AI totals to the existing booking model, without inventing catalogue entries. */
final class AiBookingProposal
{
    public function prepare(array $invoice,array $amounts,array $accounting,array $accounts=[],int $depth=0): array
    {
        $available=false;
        foreach (['netto','mwst','brutto'] as $key) if (isset($amounts[$key]) && $amounts[$key]!=='') $available=true;
        if (!empty($amounts['steuern'])) $available=true;
        $digits=in_array($invoice['currency'],['BHD','JOD','KWD','OMR','TND'],true)?3:(in_array($invoice['currency'],['CLP','JPY','KRW','VND'],true)?0:2);
        $parse=static function($value) use($digits): ?int {
            if (!is_scalar($value)) return null;
            $value=trim((string)$value);
            if (preg_match('/^-?\d{1,3}(?:\.\d{3})+,\d+$/D',$value)) $value=str_replace('.','',$value);
            elseif (preg_match('/^-?\d{1,3}(?:,\d{3})+\.\d+$/D',$value)) $value=str_replace(',','',$value);
            $value=str_replace(',','.',$value);
            if (!preg_match('/^(-?)(\d{1,10})(?:\.(\d+))?$/D',$value,$parts)) return null;
            $fraction=rtrim($parts[3]??'','0'); if (strlen($fraction)>$digits) return null;
            return ($parts[1]==='-'?-1:1)*((int)$parts[2]*10**$digits+(int)str_pad($fraction,$digits,'0'));
        };
        $format=static fn(int $value):string=>($value<0?'-':'').intdiv(abs($value),10**$digits).($digits?'.'.str_pad((string)(abs($value)%10**$digits),$digits,'0',STR_PAD_LEFT):'');
        $taxForRate=static fn(int $value,$rate):int=>self::rounded($value*(int)round((float)$rate*100),10000);
        $notes=[]; $rateWarning=''; $explicit=null;
        foreach (['mwstsatz','mwst_satz','steuersatz','satz'] as $key) if (isset($amounts[$key]) && $amounts[$key]!=='') {
            $raw=is_scalar($amounts[$key])?str_replace(',','.',rtrim(trim((string)$amounts[$key])," %")):'';
            if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D',$raw) || (float)$raw>100 || !in_array((float)$raw,array_map('floatval',$accounting['vatRates']),true)) $rateWarning='Der angegebene KI-Steuersatz ist ungültig oder nicht aktiv.';
            elseif ($explicit!==null && $explicit!==(float)$raw) $rateWarning='KI-Steuersätze widersprechen sich.';
            else $explicit=(float)$raw;
        }
        $net=$parse($amounts['netto']??null); $tax=$parse($amounts['mwst']??null); $gross=$parse($amounts['brutto']??null);
        $before=[$net,$tax,$gross];
        if ($net===null && $gross!==null && $tax!==null) $net=$gross-$tax;
        if ($tax===null && $gross!==null && $net!==null) $tax=$gross-$net;
        if ($explicit!==null && $rateWarning==='') {
            if ($net===null && $gross!==null) $net=self::rounded($gross*10000,10000+(int)round($explicit*100));
            if ($net===null && $tax!==null && $explicit>0) $net=self::rounded($tax*10000,(int)round($explicit*100));
            if ($tax===null && $net!==null) $tax=$gross!==null?$gross-$net:$taxForRate($net,$explicit);
        }
        if ($gross===null && $net!==null && $tax!==null) $gross=$net+$tax;
        foreach ([$net,$tax,$gross] as $value) if ($value!==null && abs($value)>=10**(10+$digits)) return ['invoice'=>$invoice,'invoiceAvailable'=>$available,'invoiceWarning'=>'Berechneter KI-Betrag ist zu groß. Bitte prüfen.','invoiceCalculations'=>[]];
        foreach (['Netto','Steuer','Brutto'] as $i=>$label) if ($before[$i]===null && [$net,$tax,$gross][$i]!==null) $notes[]=$label.' aus vorhandenen Werten berechnet.';
        $warning='';
        if ($available && ($net===null || $tax===null)) $warning='KI-Summen sind unvollständig. Buchungsdaten bitte ergänzen.';
        elseif ($available && $gross!==null && $net+$tax!==$gross) $warning='KI-Netto, Steuer und Brutto stimmen nicht überein. Bitte prüfen.';
        if ($net!==null) $invoice['net']=$format($net);
        if ($tax!==null) {
            $matches=[];
            foreach ($accounting['vatRates'] as $rate) {
                if ($net!==null && $net!==0 && $taxForRate($net,$rate)===$tax) $matches[]=(string)$rate;
            }
            if (!$matches && $explicit===null && $net!==null && $net!==0 && $tax!==0 && ($net>0)===($tax>0)) {
                foreach ($accounting['vatRates'] as $rate) if ((float)$rate>0 && abs($taxForRate($net,$rate)-$tax)<=1) $matches[]=(string)$rate;
                if (count($matches)===1) $notes[]='Eine Rundungsabweichung von einer kleinsten Währungseinheit ist berücksichtigt; der ausgewiesene Steuerbetrag bleibt unverändert.';
            }
            $rate=$explicit!==null?(string)$explicit:(count($matches)===1?$matches[0]:'');
            if ($explicit!==null && $net!==null && (abs($taxForRate($net,$explicit)-$tax)>1 || ($net!==0 && $tax!==0 && ($net>0)!==($tax>0)))) $rateWarning='KI-Steuersatz und Steuerbetrag stimmen nicht überein.';
            if ($explicit===null && $rate!=='' && $tax!==0) $notes[]='Steuersatz '.$rate.' % aus Netto und Steuer ermittelt.';
            $invoice['taxes']=$tax===0?[]:[['rate'=>$rate,'amount'=>$format($tax)]];
            if ($tax!==0 && $rate==='') $warning='KI-Steuerbetrag ist vorhanden, aber keinem eindeutigen aktiven Steuersatz zuzuordnen. Bitte aufteilen bzw. auswählen.';
        }
        // An explicit per-rate breakdown can resolve mixed VAT; aggregate amounts alone cannot.
        if ($depth===0 && isset($amounts['steuern']) && is_array($amounts['steuern']) && $amounts['steuern'] && count($amounts['steuern'])<=20) {
            $rowNet=0; $rowTax=0; $taxRows=[]; $rowWarning='';
            foreach ($amounts['steuern'] as $row) {
                if (!is_array($row)) { $rowWarning='Ungültige KI-Steueraufteilung.'; break; }
                $part=$this->prepare([...$invoice,'net'=>'','taxes'=>[]],$row,$accounting,[],1);
                if (!$part['invoiceAvailable'] || $part['invoiceWarning']!=='') { $rowWarning='KI-Steueraufteilung ist unvollständig oder widersprüchlich.'; break; }
                $rowNet+=$parse($part['invoice']['net']);
                foreach ($part['invoice']['taxes'] as $taxRow) { $value=$parse($taxRow['amount']); $rowTax+=$value; $taxRows[$taxRow['rate']]=($taxRows[$taxRow['rate']]??0)+$value; }
            }
            if ($rowWarning==='') {
                foreach ([$rowNet,$rowTax,$rowNet+$rowTax] as $i=>$value) if ($before[$i]!==null && $before[$i]!==$value) $rowWarning='KI-Steueraufteilung passt nicht zu den Gesamtsummen.';
            }
            if ($rowWarning==='') {
                $net=$rowNet; $tax=$rowTax; $gross=$net+$tax; $invoice['net']=$format($net); $invoice['taxes']=[];
                foreach ($taxRows as $rate=>$value) $invoice['taxes'][]=['rate'=>(string)$rate,'amount'=>$format($value)];
                if ($explicit!==null) foreach ($taxRows as $rate=>$value) if ((float)$rate!==$explicit) $rateWarning='KI-Gesamtsteuersatz und Steueraufteilung widersprechen sich.';
                $available=true; $warning=''; $notes[]='Gesamtsummen aus der ausgewiesenen Steueraufteilung berechnet.';
            } else $warning=$rowWarning;
        }
        if (count($accounts)===1 && is_array($accounts[0])) {
            $code=$accounts[0]['konto']??null;
            foreach ($accounting['accounts'] as $account) if (is_scalar($code) && (string)$account['code']===(string)$code) $invoice['accountId']=(string)$account['id'];
        }
        foreach (['netto','mwst','brutto'] as $key) if (isset($amounts[$key]) && $amounts[$key]!=='' && $parse($amounts[$key])===null) $warning='Ein KI-Betrag hat ein ungültiges Zahlenformat. Bitte prüfen.';
        if (isset($amounts['steuern']) && (!is_array($amounts['steuern']) || !array_is_list($amounts['steuern']) || count($amounts['steuern'])>20)) $warning='Ungültige oder zu umfangreiche KI-Steueraufteilung.';
        if ($rateWarning!=='') $warning=$rateWarning;
        return ['invoice'=>$invoice,'invoiceAvailable'=>$available,'invoiceWarning'=>$warning,'invoiceCalculations'=>$notes,
            'bookingTotals'=>['net'=>$net===null?null:$format($net),'tax'=>$tax===null?null:$format($tax),'gross'=>$net!==null&&$tax!==null?$format($net+$tax):($gross===null?null:$format($gross))],
            'bookingDerived'=>['net'=>$before[0]===null&&$net!==null,'tax'=>$before[1]===null&&$tax!==null,'gross'=>$net!==null&&$tax!==null&&$before[2]!==$net+$tax]];
    }
    private static function rounded(int $numerator,int $denominator): int
    {
        return ($numerator<0?-1:1)*intdiv(abs($numerator)+intdiv($denominator,2),$denominator);
    }
}
