<h1 class="h4"><?= h(tr('navigation.settings')) ?></h1>
<?php if ($actor->row['role']==='admin'): ?><section class="o8-info-group o8-info-group--head mb-3" aria-labelledby="sessionSettingsTitle">
<h2 class="h5" id="sessionSettingsTitle"><?= h(tr('admin.tenantSession')) ?></h2>
<form method="post" class="d-flex flex-wrap align-items-end gap-2"><?php postFields('session_timeout_save','settings'); ?>
<label class="form-label mb-0"><?= h(tr('admin.idleMinutes')) ?><input class="form-control" type="number" name="minutes" min="5" max="10080" required value="<?= (int)$sessionTimeoutMinutes ?>"></label>
<button class="btn btn-primary" type="submit"><?= h(tr('common.save')) ?></button></form>
<p class="small text-secondary mt-2 mb-0"><?= h(tr('admin.sessionInfo')) ?></p>
</section><?php endif ?>
<?php require __DIR__.'/admin-tags.php'; ?>
