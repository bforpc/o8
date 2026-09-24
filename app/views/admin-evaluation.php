<section class="evaluation-view" aria-labelledby="evaluationOverlayTitle">
<h1 class="h4" id="evaluationOverlayTitle">Buchhaltungsauswertung</h1>
<?php $detailActive=false; foreach (['dateFrom','dateTo','amountFrom','amountTo','invoiceNumbers','accountCode','documentType','folder','tag','notTag','owner','includeExpired','includeNotSearchable'] as $key) if (($filter[$key]??'')!=='') $detailActive=true; if (($filter['accounts']??[])!==[] || $filter['resultView']!=='all' || $filter['sort']!=='newest' || $filter['size']!=='all') $detailActive=true; ?>
<form id="evaluationFilter" method="get" class="evaluation-filter">
<input type="hidden" name="section" value="evaluation">
<?php if (($_GET['overlay']??'')==='1'): ?><input type="hidden" name="overlay" value="1"><?php endif ?>
<input type="hidden" name="run" value="1">
<div class="workspace-tools"><label class="search-field flex-grow-1"><svg class="icon small-icon" aria-hidden="true"><use href="#i-search"/></svg><span class="visually-hidden">Suche</span><input type="search" name="query" maxlength="250" value="<?= h($filter['query']) ?>" placeholder="Titel, Absender, Referenz, Notiz oder D123"></label>
<button type="submit" class="btn btn-primary compact-mobile-action" aria-label="Suchen" title="Suchen"><svg class="icon small-icon" aria-hidden="true"><use href="#i-search"/></svg><span class="button-label">Suchen</span></button>
<button class="btn btn-surface compact-mobile-action" type="button" data-bs-toggle="collapse" data-bs-target="#evaluationDetails" aria-expanded="<?= $detailActive?'true':'false' ?>" aria-controls="evaluationDetails" aria-label="Detailsuche" title="Detailsuche"><svg class="icon small-icon" aria-hidden="true"><use href="#i-filter"/></svg><span class="button-label">Detailsuche</span></button>
<a class="btn btn-surface compact-mobile-action" href="?section=evaluation<?= (($_GET['overlay']??'')==='1')?'&overlay=1':'' ?>" aria-label="Suche zurücksetzen" title="Suche zurücksetzen"><svg class="icon small-icon" aria-hidden="true"><use href="#i-restore"/></svg><span class="button-label">Zurücksetzen</span></a></div>
<div class="collapse<?= $detailActive?' show':'' ?>" id="evaluationDetails"><div class="advanced-search-grid mb-3">
<label class="form-label">Datum von<input class="form-control" type="date" name="dateFrom" value="<?= h($filter['dateFrom']) ?>"></label>
<label class="form-label">Datum bis<input class="form-control" type="date" name="dateTo" value="<?= h($filter['dateTo']) ?>"></label>
<label class="form-label">Rechnungssumme von<input class="form-control" type="number" step="0.01" min="0" name="amountFrom" value="<?= h($filter['amountFrom']) ?>"></label>
<label class="form-label">Rechnungssumme bis<input class="form-control" type="number" step="0.01" min="0" name="amountTo" value="<?= h($filter['amountTo']) ?>"></label>
<label class="form-label">Rechnungsnummern<input class="form-control" name="invoiceNumbers" value="<?= h($filter['invoiceNumbers']) ?>" placeholder="z. B. RE-2026; 0842"></label>
<label class="form-label">Buchungskonto<input class="form-control" name="accountCode" maxlength="32" value="<?= h($filter['accountCode']) ?>"></label>
<label class="form-label">Dokumentart<select class="form-select" name="documentType"><?php foreach ([''=>'Alle Arten','document'=>'Dokument','invoice'=>'Rechnung','credit_note'=>'Gutschrift','contract'=>'Vertrag','certificate'=>'Bescheinigung'] as $value=>$label): ?><option value="<?= h($value) ?>" <?= $filter['documentType']===$value?'selected':'' ?>><?= h($label) ?></option><?php endforeach ?></select></label>
<label class="form-label">Ordner<select class="form-select" name="folder"><option value="">Alle Ordner</option><?php foreach ($folders as $folder): ?><option value="<?= (int)$folder['id'] ?>" <?= $filter['folder']===(string)$folder['id']?'selected':'' ?>><?= h($folderLabels[(int)$folder['id']]) ?></option><?php endforeach ?></select></label>
<label class="form-label">Ergebnisansicht<select class="form-select" name="resultView"><option value="all" <?= $filter['resultView']==='all'?'selected':'' ?>>Alle passenden Dokumente</option><option value="latest" <?= $filter['resultView']==='latest'?'selected':'' ?>>Zuletzt hinzugefügt</option></select></label>
<label class="form-label">Die letzten … Dokumente<input class="form-control" type="number" name="latestCount" min="1" max="10000" value="<?= h($filter['latestCount']) ?>"></label>
<label class="form-label">Tag enthalten<select class="form-select" name="tag"><option value="">Alle</option><?php foreach ($tags as $tag): ?><option value="<?= (int)$tag['id'] ?>" <?= $filter['tag']===(string)$tag['id']?'selected':'' ?>><?= h($tag['name']) ?></option><?php endforeach ?></select></label>
<label class="form-label">Tag nicht enthalten<select class="form-select" name="notTag"><option value="">Keine Einschränkung</option><?php foreach ($tags as $tag): ?><option value="<?= (int)$tag['id'] ?>" <?= $filter['notTag']===(string)$tag['id']?'selected':'' ?>><?= h($tag['name']) ?></option><?php endforeach ?></select></label>
<label class="form-label">Besitzer<select class="form-select" name="owner"><option value="">Alle Benutzer</option><?php foreach ($owners as $owner): ?><option value="<?= (int)$owner['id'] ?>" <?= $filter['owner']===(string)$owner['id']?'selected':'' ?>><?= h($owner['display_name']) ?></option><?php endforeach ?></select></label>
<label class="form-label">Sortierung<select class="form-select" name="sort"><?php foreach (['newest'=>'Datum: neueste zuerst','oldest'=>'Datum: älteste zuerst','added'=>'Zuletzt hinzugefügt','title'=>'Titel A–Z'] as $value=>$label): ?><option value="<?= h($value) ?>" <?= $filter['sort']===$value?'selected':'' ?>><?= h($label) ?></option><?php endforeach ?></select></label>
<label class="form-label">Datensätze<select class="form-select" name="size"><?php foreach (['all'=>'Alle','10'=>'10','25'=>'25','50'=>'50','100'=>'100','250'=>'250'] as $value=>$label): ?><option value="<?= h($value) ?>" <?= $filter['size']===$value?'selected':'' ?>><?= h($label) ?></option><?php endforeach ?></select></label>
<label class="form-label">Konten<select class="form-select" name="accounts[]" multiple size="3"><?php foreach ($accounts as $account): ?><option value="<?= (int)$account['id'] ?>" <?= in_array((string)$account['id'],$filter['accounts'],true)?'selected':'' ?>><?= h($account['code'].' · '.$account['name']) ?></option><?php endforeach ?></select></label>
<fieldset><legend>Status einschließen</legend><label class="d-block"><input type="checkbox" name="includeExpired" value="1" <?= $filter['includeExpired']==='1'?'checked':'' ?>> Auch abgelaufene</label><label class="d-block"><input type="checkbox" name="includeNotSearchable" value="1" <?= $filter['includeNotSearchable']==='1'?'checked':'' ?>> Auch nicht suchbare</label></fieldset>
</div></div>
</form>
<?php if ($result!==null): ?>
<p class="evaluation-count" role="status"><?= (int)$result['selectedTotal'] ?> von <?= (int)$result['matchingTotal'] ?> passenden Buchungen berücksichtigt.</p>
<?php if (!$result['months']): ?><p class="alert alert-info">Keine Buchungsdaten gefunden.</p><?php else: ?>
<section class="evaluation-totals" aria-label="Gesamtsummen"><h2 class="h6">Gesamtsummen</h2><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Währung</th><th class="text-end">Netto</th><th class="text-end">Steuer</th><th class="text-end">Brutto</th></tr></thead><tbody><?php foreach ($result['currencies'] as $currency=>$totals): ?><tr><th><?= h($currency) ?></th><td class="text-end"><?= h(number_format($totals['net'],2,',','.')) ?></td><td class="text-end"><?= h(number_format($totals['tax'],2,',','.')) ?></td><td class="text-end fw-semibold"><?= h(number_format($totals['gross'],2,',','.')) ?></td></tr><?php endforeach ?></tbody></table></div></section>
<div class="evaluation-month-grid">
<?php foreach ($result['months'] as $month=>$data): ?>
<section class="evaluation-month"><h2 class="h6"><?= h(substr($month,5,2).'/'.substr($month,0,4)) ?></h2><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Konto</th><?php foreach (array_keys($data['currencies']) as $currency): ?><th class="text-end"><?= h($currency) ?> Brutto</th><?php endforeach ?></tr></thead><tbody>
<?php foreach (array_column($data['accounts'],null,'id') as $account): ?><tr><th><span><?= h($account['code']) ?></span><small class="d-block text-body-secondary"><?= h($account['name']) ?></small></th><?php foreach (array_keys($data['currencies']) as $currency): ?><td class="text-end"><?= h(number_format($data['gross'][$account['id'].'_'.$currency]??0,2,',','.')) ?></td><?php endforeach ?></tr><?php endforeach ?>
</tbody></table></div></section>
<?php endforeach ?>
</div>
<?php endif ?>
<?php endif ?>
</section>
