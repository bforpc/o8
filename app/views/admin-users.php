<h3 class="h5"><?= h(tr('admin.tenantUsers')) ?></h3>
<p class="small text-secondary"><?= h(tr('admin.userInfo')) ?></p>
<?php if ($inviteCode): ?><div class="alert alert-success" role="status"><label class="d-block"><?= h(tr('admin.inviteCode')) ?><input class="form-control font-monospace mt-2" readonly value="<?= h($inviteCode) ?>"></label><p class="mb-0 mt-2"><?= h(tr('admin.inviteCodeInfo')) ?></p></div><?php endif ?>
<details class="o8-info-group o8-info-group--soft mb-3"><summary class="fw-semibold"><?= h(tr('admin.inviteExisting')) ?></summary><form method="post" class="mt-3"><?php postFields('user_invite','users'); ?><label class="form-label d-block"><?= h(tr('admin.role')) ?><select name="role" class="form-select"><option value="user"><?= h(tr('admin.userRole')) ?></option><option value="admin"><?= h(tr('admin.adminRole')) ?></option></select></label><p class="small"><?= h(tr('admin.invitationInstructions')) ?></p><button class="btn btn-outline-primary"><?= h(tr('admin.createInvite')) ?></button></form></details>
<details class="o8-info-group o8-info-group--soft mb-3"><summary class="fw-semibold"><?= h(tr('admin.createUser')) ?></summary><form method="post" class="mt-3"><?php postFields('user_create','users'); ?>
<?php require __DIR__.'/admin-new-user.php'; ?>
<label class="form-label d-block"><?= h(tr('admin.role')) ?><select name="role" class="form-select"><option value="user"><?= h(tr('admin.userRole')) ?></option><option value="admin"><?= h(tr('admin.adminRole')) ?></option></select></label>
<button class="btn btn-primary"><?= h(tr('admin.createUser')) ?></button></form></details>
<form method="get" class="d-flex gap-2 mb-3"><input type="hidden" name="section" value="users"><?php if (($_GET['overlay']??'')==='1'): ?><input type="hidden" name="overlay" value="1"><?php endif ?><label class="flex-grow-1"><?= h(tr('admin.searchUsers')) ?><input class="form-control" name="q" value="<?= h(is_string($_GET['q']??null)?$_GET['q']:'') ?>" placeholder="<?= h(tr('admin.loginNameEmail')) ?>" maxlength="190"></label><button class="btn btn-outline-primary align-self-end"><?= h(tr('common.search')) ?></button></form>
<?php if (count($users)>200): ?><p role="status"><?= h(tr('admin.tooManyUsers')) ?></p><?php elseif (!$users): ?><p><?= h(tr('admin.noMatchingUsers')) ?></p><?php endif ?>
<?php foreach (array_slice($users,0,200) as $user): ?>
<article class="o8-info-group o8-info-group--head mb-3" data-user-id="<?= h($user['id']) ?>">
<h4 class="h6"><?= h($user['display_name']) ?> <span class="badge <?= $user['active']?'text-bg-success':'text-bg-secondary' ?>"><?= h(tr($user['active']?'admin.active':'admin.locked')) ?></span> <span class="badge text-bg-light"><?= h(tr($user['role']==='admin'?'navigation.adminRole':'navigation.userRole')) ?></span></h4>
<p class="small text-break"><?= h($user['login']) ?> · <?= h($user['email']) ?><?= $user['must_change_password']?' · '.h(tr('admin.pendingPasswordChange')):'' ?></p>
<details class="o8-info-group o8-info-group--soft"><summary><?= h(tr('admin.editUser')) ?></summary><form method="post" class="mt-3"><?php postFields('user_update','users'); ?>
<input type="hidden" name="id" value="<?= h($user['id']) ?>"><input type="hidden" name="version" value="<?= h($user['auth_version']) ?>">
<label class="form-label d-block"><?= h(tr('admin.displayName')) ?><input class="form-control" name="display_name" required maxlength="190" value="<?= h($user['display_name']) ?>"></label>
<label class="form-label d-block"><?= h(tr('admin.role')) ?><select name="role" class="form-select"><option value="user" <?= $user['role']==='user'?'selected':'' ?>><?= h(tr('navigation.userRole')) ?></option><option value="admin" <?= $user['role']==='admin'?'selected':'' ?>><?= h(tr('navigation.adminRole')) ?></option></select></label>
<label class="form-label d-block"><?= h(tr('admin.status')) ?><select name="active" class="form-select"><option value="1" <?= $user['active']?'selected':'' ?>><?= h(tr('admin.active')) ?></option><option value="0" <?= !$user['active']?'selected':'' ?>><?= h(tr('admin.locked')) ?></option></select></label>
<label class="d-block mb-3"><input type="checkbox" name="confirm" value="yes" required> <?= h(tr('admin.confirmUserChanges')) ?></label>
<button class="btn btn-primary"><?= h(tr('admin.saveUser')) ?></button></form></details>
</article><?php endforeach ?>
