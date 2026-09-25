<!doctype html>
<html lang="<?= h($language) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>o8 · <?= h(tr('setup.pageTitle')) ?></title><link rel="stylesheet" href="<?= h($publicPath) ?>/assets/vendor/bootstrap/bootstrap.min.css"><link rel="stylesheet" href="<?= h($publicPath) ?>/assets/css/app.css?v=<?= h(filemtime(dirname(__DIR__,2).'/public/assets/css/app.css')) ?>"><?php if ($section==='evaluation'): ?><link rel="stylesheet" href="<?= h($publicPath) ?>/assets/css/components.css?v=<?= h(filemtime(dirname(__DIR__,2).'/public/assets/css/components.css')) ?>"><?php endif ?><link rel="stylesheet" href="<?= h($publicPath) ?>/assets/css/foundation.css?v=<?= h(filemtime(dirname(__DIR__,2).'/public/assets/css/foundation.css')) ?>"></head>
<body class="foundation-page"<?php if ($appearancePreferences!==null): ?> data-o8-preferences="<?= h(json_encode($appearancePreferences,JSON_THROW_ON_ERROR)) ?>" data-o8-context="<?= h($_SESSION['actor']['context_token']??'') ?>" data-o8-csrf="<?= h($_SESSION['csrf']) ?>"<?php endif ?>><?php require __DIR__.'/icons.php'; ?>
<header class="app-header foundation-app-header">
    <a class="brand" href="?section=<?= $actor && $actor->kind==='tenant'?'documents':'choose' ?>"><span class="brand-mark">o<span>8</span></span></a>
    <div class="flex-grow-1 foundation-context"><strong><?= $actor ? h(tr('navigation.signedInAs',['name'=>$actor->row['display_name']])) : h(tr('navigation.product')) ?></strong><?php if ($actor && $actor->kind==='tenant'): ?><div class="small text-body-secondary"><?= h(tr('navigation.tenantRole',['tenant'=>$actor->row['tenant_name'],'role'=>$actor->row['role']])) ?></div><?php endif ?></div>
    <?php if ($actor): ?><nav class="header-tools" aria-label="<?= h(tr('navigation.main')) ?>">
        <?php if ($actor->kind==='tenant'): ?>
            <a class="btn btn-surface <?= $section==='documents'?'is-current':'' ?>" href="?section=documents"><svg class="icon small-icon" aria-hidden="true"><use href="#i-document"/></svg> <?= h(tr('navigation.documents')) ?></a>
            <?php if ($actor->row['role']==='admin'): ?>
            <a class="btn btn-surface <?= $section==='users'?'is-current':'' ?>" href="?section=users"><svg class="icon small-icon" aria-hidden="true"><use href="#i-mail"/></svg> <?= h(tr('navigation.users')) ?></a>
            <a class="btn btn-surface <?= $section==='accounting'?'is-current':'' ?>" href="?section=accounting"><svg class="icon small-icon" aria-hidden="true"><use href="#i-settings"/></svg> <?= h(tr('navigation.accounting')) ?></a>
            <a class="btn btn-surface <?= $section==='evaluation'?'is-current':'' ?>" href="?section=evaluation"><svg class="icon small-icon" aria-hidden="true"><use href="#i-chart"/></svg> <?= h(tr('navigation.evaluation')) ?></a>
            <?php endif ?>
        <?php elseif ($actor->kind==='operator'): ?>
            <a class="btn btn-surface <?= $section==='tenants'?'is-current':'' ?>" href="?section=tenants"><svg class="icon small-icon" aria-hidden="true"><use href="#i-folder"/></svg> <?= h(tr('navigation.tenants')) ?></a>
            <a class="btn btn-surface <?= $section==='storage'?'is-current':'' ?>" href="?section=storage"><svg class="icon small-icon" aria-hidden="true"><use href="#i-cloud"/></svg> <?= h(tr('navigation.storage')) ?></a>
        <?php else: ?>
            <a class="btn btn-surface <?= $section==='choose'?'is-current':'' ?>" href="?section=choose"><svg class="icon small-icon" aria-hidden="true"><use href="#i-folder"/></svg> <?= h(tr('navigation.chooseTenant')) ?></a>
        <?php endif ?>
        <details class="header-more"><summary class="btn btn-surface"><svg class="icon small-icon" aria-hidden="true"><use href="#i-menu"/></svg> <?= h(tr('navigation.menu')) ?></summary><div class="header-more-panel">
            <?php if ($actor->kind==='tenant' && count($choices)>1): ?><form method="post" class="header-tenant-switch" data-tenant-switch><?php postFields('tenant_switch',''); ?><label for="foundationTenant"><?= h(tr('navigation.switchTenant')) ?></label><select id="foundationTenant" name="tenant_id" class="form-select"><?php foreach ($choices as $choice): ?><option value="<?= h($choice['tenant_id']) ?>" <?= (int)$choice['tenant_id']===$actor->tenantId()?'selected':'' ?>><?= h($choice['name']) ?></option><?php endforeach ?></select><button class="btn btn-surface"><?= h(tr('navigation.switch')) ?></button></form><?php elseif ($actor->kind==='tenant'): ?><a class="btn btn-surface" href="?section=choose"><svg class="icon small-icon" aria-hidden="true"><use href="#i-folder"/></svg> <?= h(tr('navigation.chooseTenant')) ?></a><?php endif ?>
            <?php if ($actor->kind==='tenant'): ?><a class="btn btn-surface <?= $section==='settings'?'is-current':'' ?>" href="?section=settings"><svg class="icon small-icon" aria-hidden="true"><use href="#i-tag"/></svg> <?= h(tr('navigation.settings')) ?></a><?php endif ?>
            <a class="btn btn-surface <?= $section==='account'?'is-current':'' ?>" href="?section=account"><svg class="icon small-icon" aria-hidden="true"><use href="#i-info"/></svg> <?= h(tr('navigation.account')) ?></a>
        </div></details>
        <button class="btn btn-surface icon-btn" id="foundationThemeToggle" type="button" aria-label="<?= h(tr('navigation.theme')) ?>" title="<?= h(tr('navigation.theme')) ?>"><svg class="icon small-icon" aria-hidden="true"><use href="#i-moon"/></svg></button>
        <form method="post"><?php postFields('logout',''); ?><button class="btn btn-surface" type="submit"><?= h(tr('common.logout')) ?></button></form>
    </nav><?php endif ?>
</header><main class="foundation-main <?= $section==='evaluation'?'evaluation-page':'' ?>"><div class="foundation-content">
<header class="foundation-header">
<h1>o8</h1>
</header>
<?php if (!$actor && !$fatal && !$installed) require __DIR__.'/language-picker.php'; ?>
<?php if ($error): ?><div role="alert" class="alert alert-danger"><?= h($error) ?></div><?php endif ?>
<?php if ($fatal && $setupDiagnostics): ?><section class="card foundation-panel mb-3"><div class="card-body p-4"><h2 class="h5"><?= h(tr('setup.diagnosticsTitle')) ?></h2><p class="small text-body-secondary"><?= h(tr('setup.diagnosticsPrivacy')) ?></p><dl class="mb-0"><?php foreach ($setupDiagnostics as $diagnostic): ?><div class="mb-2"><dt class="small text-body-secondary"><?= h(O8\Core\Languages::display($languageCatalog,$language,$diagnostic['Prüfung'])) ?></dt><dd class="mb-0"><?= h(O8\Core\Languages::display($languageCatalog,$language,$diagnostic['Ergebnis'])) ?></dd></div><?php endforeach ?></dl></div></section><?php endif ?>
<?php if ($message): ?><div role="status" class="alert alert-success"><?= h($message) ?></div><?php endif ?>
<?php if (!$fatal): ?>
<section class="card foundation-panel"><div class="card-body p-4">
<?php if (!$installed): ?>
<h2 class="h4"><?= h(tr('setup.title')) ?></h2><p><?= h(tr('setup.description')) ?></p>
<p><?= h(tr('setup.tokenInfo')) ?></p>
<form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="install">
<?php if ($config): ?><p class="alert alert-info"><?= h(tr('setup.installLocalConfig')) ?> <?= h(tr('setup.databaseLine',['name'=>$config['database']['name'],'user'=>tr('auth.operatorUser'),'username'=>$config['database']['user']])) ?> <?= h(tr('setup.passwordNotShown')) ?></p>
<?php else: foreach (['host'=>['setup.host','localhost'],'port'=>['setup.port','3306'],'name'=>['setup.database','o8'],'user'=>['setup.dbUser','o8'],'password'=>['setup.dbPassword','o8']] as $key=>$spec): ?>
<label class="form-label d-block"><?= h(tr($spec[0])) ?><input class="form-control" name="<?= h($key) ?>" type="<?= $key==='password'?'password':'text' ?>" value="<?= h($spec[1]) ?>" required></label>
<?php endforeach; endif ?>
<label class="form-label d-block"><?= h(tr('setup.token')) ?><input class="form-control" name="setup_token" type="password" autocomplete="off" required></label>
<button class="btn btn-primary mt-2"><?= h(tr('setup.start')) ?></button></form>
<?php elseif (!$actor): ?>
<h2 class="h4"><?= h(tr($operatorLogin?'auth.operatorLogin':'auth.loginTitle')) ?></h2>
<p><?= h(tr($operatorLogin?'auth.operatorDescription':'auth.accountDescription')) ?></p>
<form method="post" id="foundationLoginForm"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="login"><input type="hidden" name="kind" value="<?= $operatorLogin?'operator':'account' ?>">
<label class="form-label d-block"><?= h(tr($operatorLogin?'auth.operatorUser':'auth.username')) ?><input class="form-control" name="login" autocomplete="username" required></label>
<label class="form-label d-block"><?= h(tr('auth.password')) ?><input class="form-control" type="password" name="password" autocomplete="current-password" required></label>
<?php if ($operatorLogin): ?><details class="mb-3"><summary><?= h(tr('auth.firstSetupCode')) ?></summary><label class="form-label d-block mt-2"><?= h(tr('auth.setupCodeOnly')) ?><input class="form-control" type="password" name="setup_token" autocomplete="off"></label></details><?php endif ?></form>
<?php $languagePickerInline=true; require __DIR__.'/language-picker.php'; unset($languagePickerInline); ?>
<button class="btn btn-primary mt-2" type="submit" form="foundationLoginForm"><?= h(tr('common.login')) ?></button>
<p class="mt-4 mb-0"><a href="<?= $operatorLogin?'?':'?login=operator' ?>"><?= h(tr($operatorLogin?'auth.normalLink':'auth.operatorLink')) ?></a></p>
<?php elseif ($actor->row['must_change_password']): ?>
<h2 class="h4"><?= h(tr('auth.changePasswordTitle')) ?></h2><p><?= h(tr('auth.changePasswordIntro')) ?></p>
<form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="password">
<?php foreach (['old_password'=>tr('auth.currentPassword'),'new_password'=>tr('auth.newPassword'),'repeat_password'=>tr('auth.repeatPassword')] as $key=>$label): ?>
<label class="form-label d-block"><?= h($label) ?><input type="password" class="form-control" name="<?= h($key) ?>" autocomplete="<?= $key==='old_password'?'current-password':'new-password' ?>" required></label>
<?php endforeach ?><button class="btn btn-primary mt-2"><?= h(tr('common.save')) ?></button></form>
<?php elseif ($actor->row['bootstrap_pending']??false): ?>
<h2 class="h4"><?= h(tr('setup.firstAdminTitle')) ?></h2><p><?= h(tr('setup.firstAdminDescription')) ?></p>
<form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="bootstrap">
<?php foreach (['display_name'=>'setup.yourName','email'=>'setup.emailAddress','tenant_name'=>'setup.firstTenantName'] as $key=>$label): ?>
<label class="form-label d-block"><?= h(tr($label)) ?><input class="form-control" name="<?= h($key) ?>" type="<?= $key==='email'?'email':'text' ?>" required maxlength="<?= $key==='email'?254:190 ?>"></label>
<?php endforeach ?><button class="btn btn-primary mt-2"><?= h(tr('setup.completeSetup')) ?></button></form>
<?php else: ?>
<?php
if ($section === 'evaluation' && $actor->row['role'] === 'admin') {
    require __DIR__.'/admin-evaluation.php';
} else {
    require __DIR__.'/admin-'.$section.'.php';
}
?>
<?php endif ?>
</div></section>
<?php endif ?>
<script src="<?= h($publicPath) ?>/assets/vendor/bootstrap/bootstrap.bundle.min.js" defer></script><script type="module" src="<?= h($publicPath) ?>/assets/js/i18n.js?v=<?= h(filemtime(dirname(__DIR__,2).'/public/assets/js/i18n.js')) ?>"></script><script type="module" src="<?= h($publicPath) ?>/assets/js/account-context.js?v=<?= h(filemtime(dirname(__DIR__,2).'/public/assets/js/account-context.js')) ?>"></script><script type="module" src="<?= h($publicPath) ?>/assets/js/foundation-theme.js?v=<?= h(filemtime(dirname(__DIR__,2).'/public/assets/js/foundation-theme.js')) ?>"></script>
</div></main></body></html>
