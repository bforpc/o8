<section class="evaluation-view" aria-labelledby="evaluationOverlayTitle">
<h1 class="h4" id="evaluationOverlayTitle"><?= h(tr('search.evaluationTitle')) ?></h1>
<?php $detailActive=false; foreach (['dateFrom','dateTo','amountFrom','amountTo','invoiceNumbers','accountCode','documentType','folder','tag','notTag','owner','includeExpired','includeNotSearchable'] as $key) if (($filter[$key]??'')!=='') $detailActive=true; if (($filter['folders']??[])!==[] || ($filter['accounts']??[])!==[] || $filter['resultView']!=='all' || $filter['sort']!=='newest' || $filter['size']!=='all') $detailActive=true; ?>
<form id="evaluationFilter" method="get" class="evaluation-filter">
<input type="hidden" name="section" value="evaluation">
<?php if (($_GET['overlay']??'')==='1'): ?><input type="hidden" name="overlay" value="1"><?php endif ?>
<input type="hidden" name="run" value="1">
<div class="workspace-tools"><label class="search-field flex-grow-1"><svg class="icon small-icon" aria-hidden="true"><use href="#i-search"/></svg><span class="visually-hidden"><?= h(tr('search.search')) ?></span><input type="search" name="query" maxlength="250" value="<?= h($filter['query']) ?>" placeholder="<?= h(tr('search.placeholder')) ?>"></label>
<button type="submit" class="btn btn-primary compact-mobile-action" aria-label="<?= h(tr('search.search')) ?>" title="<?= h(tr('search.search')) ?>"><svg class="icon small-icon" aria-hidden="true"><use href="#i-search"/></svg><span class="button-label"><?= h(tr('search.search')) ?></span></button>
<button class="btn btn-surface compact-mobile-action" type="button" data-bs-toggle="collapse" data-bs-target="#evaluationDetails" aria-expanded="<?= $detailActive?'true':'false' ?>" aria-controls="evaluationDetails" aria-label="<?= h(tr('search.details')) ?>" title="<?= h(tr('search.details')) ?>"><svg class="icon small-icon" aria-hidden="true"><use href="#i-filter"/></svg><span class="button-label"><?= h(tr('search.details')) ?></span></button>
<a class="btn btn-surface compact-mobile-action" href="?section=evaluation<?= (($_GET['overlay']??'')==='1')?'&overlay=1':'' ?>" aria-label="<?= h(tr('search.reset')) ?>" title="<?= h(tr('search.reset')) ?>"><svg class="icon small-icon" aria-hidden="true"><use href="#i-restore"/></svg><span class="button-label"><?= h(tr('search.reset')) ?></span></a></div>
<div class="collapse<?= $detailActive?' show':'' ?>" id="evaluationDetails"><div class="advanced-search-grid mb-3">
<label class="form-label"><?= h(tr('search.dateFrom')) ?><input class="form-control" type="date" name="dateFrom" value="<?= h($filter['dateFrom']) ?>"></label>
<label class="form-label"><?= h(tr('search.dateTo')) ?><input class="form-control" type="date" name="dateTo" value="<?= h($filter['dateTo']) ?>"></label>
<label class="form-label"><?= h(tr('search.amountFrom')) ?><input class="form-control" type="number" step="0.01" min="0" name="amountFrom" value="<?= h($filter['amountFrom']) ?>"></label>
<label class="form-label"><?= h(tr('search.amountTo')) ?><input class="form-control" type="number" step="0.01" min="0" name="amountTo" value="<?= h($filter['amountTo']) ?>"></label>
<label class="form-label"><?= h(tr('search.invoiceNumbers')) ?><input class="form-control" name="invoiceNumbers" value="<?= h($filter['invoiceNumbers']) ?>" placeholder="<?= h(tr('search.invoiceNumbersExample')) ?>"></label>
<label class="form-label"><?= h(tr('search.accountCode')) ?><input class="form-control" name="accountCode" maxlength="32" value="<?= h($filter['accountCode']) ?>"></label>
<label class="form-label"><?= h(tr('search.documentType')) ?><select class="form-select" name="documentType"><?php foreach ([''=>'allTypes','document'=>'document','invoice'=>'invoice','credit_note'=>'creditNote','contract'=>'contract','certificate'=>'certificate'] as $value=>$key): ?><option value="<?= h($value) ?>" <?= $filter['documentType']===$value?'selected':'' ?>><?= h(tr('search.'.$key)) ?></option><?php endforeach ?></select></label>
<?php
$folderIds=[]; foreach ($folders as $folder) $folderIds[(int)$folder['id']]=true;
$folderChildren=[];
foreach ($folders as $folder) {
    $parent=(int)($folder['parent_id']??0);
    if ($parent<1 || !isset($folderIds[$parent]) || $parent===(int)$folder['id']) $parent=0;
    $folderChildren[$parent][]=$folder;
}
$selectedFolders=[]; foreach ($filter['folders'] as $id) if (is_scalar($id) && ctype_digit((string)$id)) $selectedFolders[(int)$id]=true;
if ($filter['folder']!=='' && ctype_digit($filter['folder'])) $selectedFolders[(int)$filter['folder']]=true;
$renderFolderTree=function(int $parent) use (&$renderFolderTree,&$folderChildren,&$selectedFolders): void {
    foreach ($folderChildren[$parent]??[] as $folder) {
        $id=(int)$folder['id'];
        echo '<li><label for="evaluation-folder-'.$id.'"><input class="form-check-input" id="evaluation-folder-'.$id.'" type="checkbox" name="folders[]" value="'.$id.'"'.(isset($selectedFolders[$id])?' checked':'').'><span>'.h((string)$folder['name']).'</span></label>';
        if (!empty($folderChildren[$id])) { echo '<ul>'; $renderFolderTree($id); echo '</ul>'; }
        echo '</li>';
    }
};
?>
<fieldset class="evaluation-folder-filter"><legend><?= h(tr('search.folders')) ?></legend><div class="accept-folder-picker evaluation-folder-picker" role="group" aria-label="<?= h(tr('search.selectFolder')) ?>"><ul class="accept-folder-tree"><?php if ($folderChildren): $renderFolderTree(0); else: ?><li class="small text-body-secondary"><?= h(tr('search.noFolders')) ?></li><?php endif ?></ul></div></fieldset>
<label class="form-label"><?= h(tr('search.resultView')) ?><select class="form-select" name="resultView"><option value="all" <?= $filter['resultView']==='all'?'selected':'' ?>><?= h(tr('search.allMatching')) ?></option><option value="latest" <?= $filter['resultView']==='latest'?'selected':'' ?>><?= h(tr('search.latestAdded')) ?></option></select></label>
<label class="form-label"><?= h(tr('search.latestCount')) ?><input class="form-control" type="number" name="latestCount" min="1" max="10000" value="<?= h($filter['latestCount']) ?>"></label>
<label class="form-label"><?= h(tr('search.tagInclude')) ?><select class="form-select" name="tag"><option value=""><?= h(tr('search.allTags')) ?></option><?php foreach ($tags as $tag): ?><option value="<?= (int)$tag['id'] ?>" <?= $filter['tag']===(string)$tag['id']?'selected':'' ?>><?= h($tag['name']) ?></option><?php endforeach ?></select></label>
<label class="form-label"><?= h(tr('search.tagExclude')) ?><select class="form-select" name="notTag"><option value=""><?= h(tr('search.noTagRestriction')) ?></option><?php foreach ($tags as $tag): ?><option value="<?= (int)$tag['id'] ?>" <?= $filter['notTag']===(string)$tag['id']?'selected':'' ?>><?= h($tag['name']) ?></option><?php endforeach ?></select></label>
<label class="form-label"><?= h(tr('search.owner')) ?><select class="form-select" name="owner"><option value=""><?= h(tr('search.allOwners')) ?></option><?php foreach ($owners as $owner): ?><option value="<?= (int)$owner['id'] ?>" <?= $filter['owner']===(string)$owner['id']?'selected':'' ?>><?= h($owner['display_name']) ?></option><?php endforeach ?></select></label>
<label class="form-label"><?= h(tr('search.sort')) ?><select class="form-select" name="sort"><?php foreach (['newest'=>'dateNewest','oldest'=>'dateOldest','added'=>'sortAdded','title'=>'sortTitle'] as $value=>$key): ?><option value="<?= h($value) ?>" <?= $filter['sort']===$value?'selected':'' ?>><?= h(tr('search.'.$key)) ?></option><?php endforeach ?></select></label>
<label class="form-label"><?= h(tr('search.recordCount')) ?><select class="form-select" name="size"><?php foreach (['all'=>'sizeAll','10'=>'10','25'=>'25','50'=>'50','100'=>'100','250'=>'250'] as $value=>$key): ?><option value="<?= h($value) ?>" <?= $filter['size']===$value?'selected':'' ?>><?= h($value==='all'?tr('search.'.$key):$key) ?></option><?php endforeach ?></select></label>
<label class="form-label"><?= h(tr('search.accounts')) ?><select class="form-select" name="accounts[]" multiple size="3"><?php foreach ($accounts as $account): ?><option value="<?= (int)$account['id'] ?>" <?= in_array((string)$account['id'],$filter['accounts'],true)?'selected':'' ?>><?= h($account['code'].' · '.$account['name']) ?></option><?php endforeach ?></select></label>
<fieldset><legend><?= h(tr('search.includeStatus')) ?></legend><label class="d-block"><input type="checkbox" name="includeExpired" value="1" <?= $filter['includeExpired']==='1'?'checked':'' ?>> <?= h(tr('search.includeExpired')) ?></label><label class="d-block"><input type="checkbox" name="includeNotSearchable" value="1" <?= $filter['includeNotSearchable']==='1'?'checked':'' ?>> <?= h(tr('search.includeNotSearchable')) ?></label></fieldset>
</div></div>
</form>
<?php if ($result!==null): ?>
<p class="evaluation-count" role="status"><?= h(tr('search.documentsAffected',['selected'=>(int)$result['selectedTotal'],'total'=>(int)$result['matchingTotal']])) ?></p>
<?php if (!$result['months']): ?><p class="alert alert-info"><?= h(tr('search.noBookingData')) ?></p><?php else: ?>
<section class="evaluation-totals" aria-label="<?= h(tr('search.totals')) ?>"><h2 class="h6"><?= h(tr('search.totals')) ?></h2><div class="table-responsive"><table class="table table-sm"><thead><tr><th><?= h(tr('search.currency')) ?></th><th class="text-end"><?= h(tr('search.net')) ?></th><th class="text-end"><?= h(tr('search.tax')) ?></th><th class="text-end"><?= h(tr('search.gross')) ?></th></tr></thead><tbody><?php foreach ($result['currencies'] as $currency=>$totals): ?><tr><th><?= h($currency) ?></th><td class="text-end"><?= h(number_format($totals['net'],2,',','.')) ?></td><td class="text-end"><?= h(number_format($totals['tax'],2,',','.')) ?></td><td class="text-end fw-semibold"><?= h(number_format($totals['gross'],2,',','.')) ?></td></tr><?php endforeach ?></tbody></table></div></section>
<?php $months=$result['months']; ksort($months,SORT_STRING); ?>
<div class="evaluation-month-list">
<?php foreach ($months as $month=>$data): ?>
<section class="evaluation-month"><header><h2 class="h6"><?= h(substr($month,5,2).'/'.substr($month,0,4)) ?></h2><span class="evaluation-month-count"><?= (int)$data['document_count'] ?> <?= h(tr('search.monthDocuments')) ?></span></header><div class="table-responsive"><table class="table table-sm"><thead><tr><th><?= h(tr('search.account')) ?></th><?php foreach (array_keys($data['currencies']) as $currency): ?><th class="text-end"><?= h(tr('search.grossCurrency',['currency'=>$currency])) ?></th><?php endforeach ?></tr></thead><tbody>
<?php foreach (array_column($data['accounts'],null,'id') as $account): ?><tr><th><span><?= h($account['code']) ?></span><small class="d-block text-body-secondary"><?= h($account['name']) ?></small></th><?php foreach (array_keys($data['currencies']) as $currency): ?><td class="text-end"><?= h(number_format($data['gross'][$account['id'].'_'.$currency]??0,2,',','.')) ?></td><?php endforeach ?></tr><?php endforeach ?>
</tbody></table></div></section>
<?php endforeach ?>
</div>
<?php endif ?>
<?php endif ?>
</section>
