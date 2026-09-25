<div class="tenant-section-heading"><div><h3 class="h4 mb-1"><?= h(tr('navigation.tenants')) ?></h3><p class="mb-0"><?= h(tr('admin.tenantsDescription')) ?></p></div><span class="tenant-count"><?= count($tenants) ?> <?= h(tr('admin.total')) ?></span></div>
<details class="tenant-create mb-4"><summary class="fw-semibold"><?= h(tr('admin.createTenant')) ?></summary>
<form method="post" class="tenant-create-form"><?php postFields('tenant_create','tenants'); ?>
<section class="o8-info-group o8-info-group--head">
<label class="form-label d-block"><?= h(tr('admin.tenantName')) ?><input class="form-control" name="tenant_name" required maxlength="190"></label>
<p class="small text-secondary"><?= h(tr('admin.tenantNameUnique')) ?></p>
<label class="form-label d-block"><?= h(tr('admin.contactEmailOptional')) ?><input class="form-control" name="contact_email" type="email" maxlength="254"></label>
</section><section class="o8-info-group o8-info-group--soft"><h4 class="h6"><?= h(tr('admin.firstTenantAdmin')) ?></h4><p class="small text-secondary"><?= h(tr('admin.tenantAdminRule')) ?></p>
<label class="form-label d-block"><?= h(tr('admin.account')) ?><select class="form-select" name="admin_mode" data-account-mode><option value="new"><?= h(tr('admin.createNewAccount')) ?></option><option value="existing"><?= h(tr('admin.assignExistingAccount')) ?></option></select></label>
<div data-new-account>
<?php require __DIR__.'/admin-new-user.php'; ?>
</div>
<div data-existing-account hidden><label class="form-label d-block"><?= h(tr('admin.existingLogin')) ?><input class="form-control" name="existing_login" maxlength="254" required disabled autocomplete="off"></label><p class="small"><?= h(tr('admin.newTenantAdminRights')) ?></p></div></section>
<button class="btn btn-primary"><?= h(tr('admin.createTenant')) ?></button></form></details>
<?php foreach ($tenants as $tenant): ?>
<article class="tenant-card <?= $tenant['deleting']?'is-deleting':($tenant['active']?'is-active':'is-inactive') ?>" data-tenant-id="<?= h($tenant['id']) ?>">
<div class="tenant-card-heading"><span class="tenant-monogram" aria-hidden="true"><?= h(mb_strtoupper(mb_substr($tenant['name'],0,1))) ?></span><div class="tenant-identity">
<h4 class="h5"><?= h($tenant['name']) ?> <span class="badge <?= $tenant['active']?'text-bg-success':'text-bg-secondary' ?>"><?= h(tr($tenant['active']?'admin.active':'admin.locked')) ?></span></h4>
<p class="small text-break tenant-uuid"><?= h($tenant['public_id']) ?></p>
<?php if ($tenant['contact_email']): ?><p class="tenant-contact"><?= h($tenant['contact_email']) ?></p><?php endif ?>
</div></div>
<?php if ($tenant['deleting']): ?>
<p class="text-danger"><?= h(tr('admin.deletionRunning')) ?></p>
<a class="btn btn-outline-danger" href="?section=tenant_delete&amp;id=<?= h($tenant['id']) ?>"><?= h(tr('admin.continueDeletion')) ?></a></article>
<?php continue; endif ?>
<?php if ($tenant['active']): ?><form method="post" class="mb-3"><?php postFields('tenant_login',''); ?><input type="hidden" name="tenant" value="<?= h($tenant['public_id']) ?>"><button class="btn btn-primary"><?= h(tr('admin.logoutToTenantLogin')) ?></button></form><?php endif ?>
<details class="tenant-edit"><summary><?= h(tr('admin.editTenant')) ?></summary><form method="post" class="mt-3"><?php postFields('tenant_update','tenants'); ?><input type="hidden" name="id" value="<?= h($tenant['id']) ?>">
<label class="form-label d-block"><?= h(tr('admin.name')) ?><input class="form-control" name="tenant_name" required maxlength="190" value="<?= h($tenant['name']) ?>"></label>
<label class="form-label d-block"><?= h(tr('admin.email')) ?><input class="form-control" type="email" name="contact_email" maxlength="254" value="<?= h($tenant['contact_email']) ?>"></label>
<label class="form-label d-block"><?= h(tr('admin.status')) ?><select name="active" class="form-select"><option value="1" <?= $tenant['active']?'selected':'' ?>><?= h(tr('admin.active')) ?></option><option value="0" <?= !$tenant['active']?'selected':'' ?>><?= h(tr('admin.locked')) ?></option></select></label>
<label class="d-block mb-3"><input type="checkbox" name="confirm" value="yes" required> <?= h(tr('admin.confirmTenantStatus')) ?></label>
<button class="btn btn-primary"><?= h(tr('admin.saveTenant')) ?></button></form></details>
<form method="post" class="tenant-danger-zone"><?php postFields('tenant_delete_review','tenants'); ?><input type="hidden" name="id" value="<?= h($tenant['id']) ?>"><span class="small"><?= h(tr('admin.removePermanently')) ?></span><button class="btn btn-outline-danger"><?= h(tr('admin.deleteTenant')) ?></button></form>
</article>
<?php endforeach ?>
<?php if (!$tenants): ?><div class="tenant-empty"><?= h(tr('admin.noTenants')) ?></div><?php endif ?>
