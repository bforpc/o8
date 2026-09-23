<section aria-labelledby="accountingTitle">
<h1 class="h4" id="accountingTitle">Buchhaltung</h1>
<p class="text-secondary">MWSt-Sätze und Buchungskonten gelten für diesen Mandanten. Bereits in Buchungsdaten verwendete Werte bleiben geschützt und können nicht deaktiviert werden.</p>
<form method="post">
<?php postFields('accounting_save','accounting'); ?>
<label class="form-label d-block">Kontorahmen / Bezeichnung<input class="form-control" name="framework" maxlength="100" value="<?= h($accountingSettings['framework']) ?>" placeholder="z. B. SKR 03"></label>
<div class="row g-3 mt-1"><div class="col-md-4"><label class="form-label d-block">MWSt-Sätze in Prozent<textarea class="form-control" name="vat_rates" rows="6" required><?= h(implode("\n",array_map(static fn($row)=>(string)(float)$row['rate'],array_filter($accountingSettings['vatRates'],static fn($row)=>(bool)$row['active'])))) ?></textarea></label><p class="small text-secondary">Ein Satz pro Zeile, ohne %-Zeichen. Nicht mehr enthaltene, unbenutzte Sätze werden deaktiviert.</p></div><div class="col-md-8"><label class="form-label d-block">Buchungskonten<textarea class="form-control" name="accounts" rows="9" placeholder="4980; Sonstige betriebliche Aufwendungen"><?php foreach (array_filter($accountingSettings['accounts'],static fn($row)=>(bool)$row['active']) as $account): ?><?= h($account['code'].'; '.$account['name'])."\n" ?><?php endforeach ?></textarea></label><p class="small text-secondary">Ein Konto pro Zeile: Nummer; Bezeichnung. Nicht mehr enthaltene, unbenutzte Konten werden deaktiviert.</p></div></div>
<button class="btn btn-primary mt-2">Buchhaltungs-Setup speichern</button>
</form>
</section>
