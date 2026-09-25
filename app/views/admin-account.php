<h3 class="h5"><?= h(tr('admin.myAccount')) ?></h3>
<?php require __DIR__.'/language-picker.php'; ?>
<section class="o8-info-group o8-info-group--head"><dl class="row mb-0"><dt class="col-sm-4"><?= h(tr('admin.user')) ?></dt><dd class="col-sm-8"><?= h($actor->row['login']) ?></dd><dt class="col-sm-4"><?= h(tr('admin.email')) ?></dt><dd class="col-sm-8 text-break"><?= h($actor->row['email']??$actor->row['email_normalized']) ?></dd></dl></section>
<p class="small text-secondary"><?= h(tr('admin.accountEmailInfo')) ?></p>
<?php if ($actor->kind!=='operator'): ?>
<p><?= h(tr('admin.accountScopeInfo')) ?></p>
<details class="o8-info-group o8-info-group--soft"><summary><?= h(tr('admin.acceptInvitation')) ?></summary><form method="post" class="mt-3"><?php postFields('invitation_accept','account'); ?><label class="form-label d-block"><?= h(tr('admin.inviteCode')) ?><input class="form-control" name="invitation" required pattern="[a-fA-F0-9]{48}" maxlength="48" autocomplete="off"></label><p class="small"><?= h(tr('admin.inviteCodeTrust')) ?></p><button class="btn btn-primary">Einladung annehmen</button></form></details>
<?php endif ?>
<?php if ($actor->kind==='tenant'): require __DIR__.'/admin-sources.php'; endif ?>
<?php if ($actor->kind==='tenant'): ?>
<section class="o8-info-group o8-info-group--soft" aria-labelledby="trashRetentionTitle"><h4 class="h6" id="trashRetentionTitle"><?= h(tr('admin.trashRetention')) ?></h4>
<form method="post"><?php postFields('trash_retention_save','account'); ?>
<label class="form-label d-block"><?= h(tr('admin.deleteOwnDocumentsAfter')) ?><input class="form-control" type="number" name="days" min="0" max="36500" step="1" value="<?= h($trashRetentionDays) ?>" required></label>
<p class="small text-secondary"><?= h(tr('admin.retentionInfo')) ?></p>
<label class="d-block mb-2"><input type="checkbox" name="retention_confirm" value="yes"> <?= h(tr('admin.retentionConfirm')) ?></label>
<button class="btn btn-primary" type="submit"><?= h(tr('admin.saveRetention')) ?></button></form>
<?php if ($trashRetentionDays>0): ?><div class="alert <?= $trashWorkerActive?'alert-success':'alert-warning' ?> mt-3 mb-0" role="status"><?= h(tr($trashWorkerActive?'admin.retentionWorkerOk':'admin.retentionWorkerMissing')) ?> <?= h(tr('admin.operatorNote',['path'=>$root.'/bin/trash-retention.php'])) ?></div><?php else: ?><p class="small text-secondary mt-2 mb-0"><?= h(tr('admin.retentionUnlimitedNoWorker')) ?></p><?php endif ?>
</section>
<?php endif ?>

<?php if ($actor->kind==='tenant' && $actor->row['role']==='admin'): ?>
<section class="o8-info-group"><h4 class="h6"><?= h(tr('admin.storageCheck')) ?></h4>
<?php
$storageCheck=null;
try {
    $storageCheck=(new O8\Storage\StorageRepair($db,$root))->check($actor);
} catch (Throwable $e) {
    $storageCheck=['status'=>'error','error'=>$e->getMessage()];
}
?>
<?php if ($storageCheck['status']==='ok'): ?>
<div class="alert alert-success">
    <p class="mb-0"><strong><?= h(tr('admin.storageOk')) ?></strong><br><?= h(tr('admin.path')) ?> <?= h($storageCheck['root_path']) ?>/<?= h($storageCheck['target']) ?></p>
</div>
<?php elseif ($storageCheck['status']==='broken'): ?>
<div class="alert alert-warning">
    <p class="mb-0"><strong><?= h(tr('admin.storageIdentityBroken')) ?></strong></p>
    <ul class="mb-2">
    <?php foreach ($storageCheck['issues'] as $issue): ?>
        <li><?= h($issue) ?></li>
    <?php endforeach; ?>
    </ul>
    <p class="small text-secondary mt-2 mb-0"><?= h(tr('admin.contactOperatorStorage')) ?></p>
</div>
<?php elseif ($storageCheck['status']==='missing'): ?>
<div class="alert alert-danger">
    <p class="mb-0"><strong><?= h(tr('admin.storageMissing')) ?></strong><br><?= h(tr('admin.contactOperator')) ?></p>
</div>
<?php else: ?>
<div class="alert alert-danger">
    <p class="mb-0"><strong><?= h(tr('admin.storageError')) ?></strong> <?= h(O8\Core\Languages::display($languageCatalog,$language,$storageCheck['error']??'Unbekannter Fehler')) ?></p>
</div>
<?php endif; ?>
</section>
<?php endif; ?>

<section class="o8-info-group o8-info-group--strong"><h4 class="h6"><?= h(tr('admin.changeOwnPassword')) ?></h4>
<form method="post"><?php postFields('password','account'); ?>
<?php foreach (['old_password'=>tr('auth.currentPassword'),'new_password'=>tr('auth.newPassword'),'repeat_password'=>tr('auth.repeatPassword')] as $key=>$label): ?>
<label class="form-label d-block"><?= h($label) ?><input type="password" class="form-control" name="<?= h($key) ?>" autocomplete="<?= $key==='old_password'?'current-password':'new-password' ?>" required></label>
<?php endforeach ?><p class="small"><?= h(tr('admin.minPasswordLength')) ?></p><button class="btn btn-primary"><?= h(tr('admin.savePassword')) ?></button></form></section>
