<?php /* Shared fields; the caller owns the form and authorization. */ ?>
<p class="small text-secondary"><?= h(tr('admin.userAccountCreationHint')) ?></p>
<label class="form-label d-block"><?= h(tr('admin.login')) ?><input class="form-control" name="login" required maxlength="190" autocomplete="off" pattern="[a-zA-Z0-9][a-zA-Z0-9._@\-]{0,189}"></label>
<label class="form-label d-block"><?= h(tr('admin.displayName')) ?><input class="form-control" name="display_name" required maxlength="190" autocomplete="off"></label>
<label class="form-label d-block"><?= h(tr('admin.email')) ?><input type="email" class="form-control" name="email" required maxlength="254" autocomplete="off"></label>
<label class="form-label d-block"><?= h(tr('admin.initialPassword')) ?><input type="password" class="form-control" name="temporary_password" required autocomplete="new-password"></label>
<p class="small text-secondary"><?= h(tr('admin.passwordRule')) ?> <?= h(tr('admin.initialPasswordInfo')) ?></p>
