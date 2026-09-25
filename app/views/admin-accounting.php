<section aria-labelledby="accountingTitle">
<h1 class="h4" id="accountingTitle"><?= h(tr('admin.accountingTitle')) ?></h1>
<p class="text-secondary"><?= h(tr('admin.accountingDescription')) ?></p>

        <form method="post">
        <?php postFields('accounting_save','accounting'); ?>
        <section class="o8-info-group o8-info-group--head"><label class="form-label d-block"><?= h(tr('admin.framework')) ?><input class="form-control" name="framework" maxlength="100" value="<?= h($accountingSettings['framework']) ?>" placeholder="z. B. SKR 03"></label></section>
        <div class="row g-3 mt-1"><div class="col-md-4"><section class="o8-info-group o8-info-group--soft h-100"><label class="form-label d-block"><?= h(tr('admin.vatRates')) ?><textarea class="form-control" name="vat_rates" rows="6" required><?= h(implode("\n",array_map(static fn($row)=>(string)(float)$row['rate'],array_filter($accountingSettings['vatRates'],static fn($row)=>(bool)$row['active'])))) ?></textarea></label><p class="small text-secondary"><?= h(tr('admin.vatRateHint')) ?></p></section></div><div class="col-md-8"><section class="o8-info-group o8-info-group--strong h-100"><label class="form-label d-block"><?= h(tr('admin.accounts')) ?><textarea class="form-control" name="accounts" rows="9" placeholder="<?= h(tr('admin.accountExample')) ?>"><?php foreach (array_filter($accountingSettings['accounts'],static fn($row)=>(bool)$row['active']) as $account): ?><?= h($account['code'].'; '.$account['name'])."\n" ?><?php endforeach ?></textarea></label><p class="small text-secondary"><?= h(tr('admin.accountHint')) ?></p></section></div></div>
        <button class="btn btn-primary mt-2"><?= h(tr('admin.saveAccounting')) ?></button>
        </form>
</section>
