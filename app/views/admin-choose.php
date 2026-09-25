<h3 class="h4"><?= h(tr('navigation.chooseTenant')) ?></h3>
<?php if (!$choices): ?><div class="alert alert-info"><?= h(tr('admin.tenantNone')) ?></div>
<?php else: ?><p><?= h(tr('admin.tenantChoiceHint')) ?></p>
<?php foreach ($choices as $choice): ?><form method="post" class="tenant-card" data-tenant-switch><?php postFields('tenant_switch',''); ?><input type="hidden" name="tenant_id" value="<?= h($choice['tenant_id']) ?>"><h4 class="h5"><?= h($choice['name']) ?></h4><p><?= h(tr('admin.yourRole',['role'=>tr($choice['role']==='admin'?'admin.adminRole':'admin.userRole')])) ?></p><button class="btn btn-primary"><?= h(tr('admin.openTenant')) ?></button></form><?php endforeach ?>
<?php endif ?>
