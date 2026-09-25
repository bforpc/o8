<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>o8 · Einrichtung und Anmeldung</title><link rel="stylesheet" href="<?= h($publicPath) ?>/assets/vendor/bootstrap/bootstrap.min.css"><link rel="stylesheet" href="<?= h($publicPath) ?>/assets/css/app.css?v=<?= h(filemtime(dirname(__DIR__,2).'/public/assets/css/app.css')) ?>"><?php if ($section==='evaluation'): ?><link rel="stylesheet" href="<?= h($publicPath) ?>/assets/css/components.css?v=<?= h(filemtime(dirname(__DIR__,2).'/public/assets/css/components.css')) ?>"><?php endif ?><link rel="stylesheet" href="<?= h($publicPath) ?>/assets/css/foundation.css?v=<?= h(filemtime(dirname(__DIR__,2).'/public/assets/css/foundation.css')) ?>"></head>
<body class="foundation-page"<?php if ($appearancePreferences!==null): ?> data-o8-preferences="<?= h(json_encode($appearancePreferences,JSON_THROW_ON_ERROR)) ?>" data-o8-context="<?= h($_SESSION['actor']['context_token']??'') ?>" data-o8-csrf="<?= h($_SESSION['csrf']) ?>"<?php endif ?>><?php require __DIR__.'/icons.php'; ?>
<header class="app-header foundation-app-header">
    <a class="brand" href="?section=<?= $actor && $actor->kind==='tenant'?'documents':'choose' ?>"><span class="brand-mark">o<span>8</span></span></a>
    <div class="flex-grow-1 foundation-context"><strong><?= $actor ? 'Angemeldet als '.h($actor->row['display_name']) : 'o8 Dokumentenverwaltung' ?></strong><?php if ($actor && $actor->kind==='tenant'): ?><div class="small text-body-secondary">Mandant: <?= h($actor->row['tenant_name']) ?> · Rolle: <?= h($actor->row['role']) ?></div><?php endif ?></div>
    <?php if ($actor): ?><nav class="header-tools" aria-label="Hauptnavigation">
        <?php if ($actor->kind==='tenant'): ?>
            <a class="btn btn-surface <?= $section==='documents'?'is-current':'' ?>" href="?section=documents"><svg class="icon small-icon" aria-hidden="true"><use href="#i-document"/></svg> Dokumente</a>
            <?php if ($actor->row['role']==='admin'): ?>
            <a class="btn btn-surface <?= $section==='users'?'is-current':'' ?>" href="?section=users"><svg class="icon small-icon" aria-hidden="true"><use href="#i-mail"/></svg> Benutzer</a>
            <a class="btn btn-surface <?= $section==='accounting'?'is-current':'' ?>" href="?section=accounting"><svg class="icon small-icon" aria-hidden="true"><use href="#i-settings"/></svg> Buchhaltung</a>
            <a class="btn btn-surface <?= $section==='evaluation'?'is-current':'' ?>" href="?section=evaluation"><svg class="icon small-icon" aria-hidden="true"><use href="#i-chart"/></svg> Auswertung</a>
            <?php endif ?>
        <?php elseif ($actor->kind==='operator'): ?>
            <a class="btn btn-surface <?= $section==='tenants'?'is-current':'' ?>" href="?section=tenants"><svg class="icon small-icon" aria-hidden="true"><use href="#i-folder"/></svg> Mandanten</a>
            <a class="btn btn-surface <?= $section==='storage'?'is-current':'' ?>" href="?section=storage"><svg class="icon small-icon" aria-hidden="true"><use href="#i-cloud"/></svg> Storage</a>
        <?php else: ?>
            <a class="btn btn-surface <?= $section==='choose'?'is-current':'' ?>" href="?section=choose"><svg class="icon small-icon" aria-hidden="true"><use href="#i-folder"/></svg> Mandant auswählen</a>
        <?php endif ?>
        <details class="header-more"><summary class="btn btn-surface"><svg class="icon small-icon" aria-hidden="true"><use href="#i-menu"/></svg> Menü</summary><div class="header-more-panel">
            <?php if ($actor->kind==='tenant' && count($choices)>1): ?><form method="post" class="header-tenant-switch" data-tenant-switch><?php postFields('tenant_switch',''); ?><label for="foundationTenant">Mandant wechseln</label><select id="foundationTenant" name="tenant_id" class="form-select"><?php foreach ($choices as $choice): ?><option value="<?= h($choice['tenant_id']) ?>" <?= (int)$choice['tenant_id']===$actor->tenantId()?'selected':'' ?>><?= h($choice['name']) ?></option><?php endforeach ?></select><button class="btn btn-surface">Wechseln</button></form><?php elseif ($actor->kind==='tenant'): ?><a class="btn btn-surface" href="?section=choose"><svg class="icon small-icon" aria-hidden="true"><use href="#i-folder"/></svg> Mandant auswählen</a><?php endif ?>
            <?php if ($actor->kind==='tenant'): ?><a class="btn btn-surface <?= $section==='settings'?'is-current':'' ?>" href="?section=settings"><svg class="icon small-icon" aria-hidden="true"><use href="#i-tag"/></svg> Einstellungen</a><?php endif ?>
            <a class="btn btn-surface <?= $section==='account'?'is-current':'' ?>" href="?section=account"><svg class="icon small-icon" aria-hidden="true"><use href="#i-info"/></svg> Mein Konto</a>
        </div></details>
        <button class="btn btn-surface icon-btn" id="foundationThemeToggle" type="button" aria-label="Hell / Dunkel" title="Hell / Dunkel"><svg class="icon small-icon" aria-hidden="true"><use href="#i-moon"/></svg></button>
        <form method="post"><?php postFields('logout',''); ?><button class="btn btn-surface" type="submit">Abmelden</button></form>
    </nav><?php endif ?>
</header><main class="foundation-main <?= $section==='evaluation'?'evaluation-page':'' ?>"><div class="foundation-content">
<header class="foundation-header">
<h1>o8</h1>
</header>
<?php if ($error): ?><div role="alert" class="alert alert-danger"><?= h($error) ?></div><?php endif ?>
<?php if ($fatal && $setupDiagnostics): ?><section class="card foundation-panel mb-3"><div class="card-body p-4"><h2 class="h5">Installationsdiagnose</h2><p class="small text-body-secondary">Diese Angaben enthalten keine Datenbankkennwörter oder anderen Zugangsdaten.</p><dl class="mb-0"><?php foreach ($setupDiagnostics as $diagnostic): ?><div class="mb-2"><dt class="small text-body-secondary"><?= h($diagnostic['Prüfung']) ?></dt><dd class="mb-0"><?= h($diagnostic['Ergebnis']) ?></dd></div><?php endforeach ?></dl></div></section><?php endif ?>
<?php if ($message): ?><div role="status" class="alert alert-success"><?= h($message) ?></div><?php endif ?>
<?php if (!$fatal): ?>
<section class="card foundation-panel"><div class="card-body p-4">
<?php if (!$installed): ?>
<h2 class="h4">Datenbank einrichten</h2><p>Nur eine eigene, leere Datenbank verwenden. Vorhandene Fremddaten werden nicht überschrieben.</p>
<p>Auf Debian/Apache einmal als Web-PHP-Benutzer ausführen: <code>sudo -u www-data php bin/setup.php token</code>. Nicht als root ausführen: Der Code muss in derselben lokalen Systemablage wie die Webanwendung gespeichert werden. Der Code gilt eine Stunde und schützt auch die erste Anmeldung.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="install">
<?php if ($config): ?><p class="alert alert-info">Lokale config.php wird verwendet. Datenbank: <?= h($config['database']['name']) ?> · Benutzer: <?= h($config['database']['user']) ?>. Das Passwort wird nicht angezeigt.</p>
<?php else: foreach (['host'=>['Host','localhost'],'port'=>['Port','3306'],'name'=>['Datenbank','o8'],'user'=>['DB-Benutzer','o8'],'password'=>['DB-Passwort','o8']] as $key=>$spec): ?>
<label class="form-label d-block"><?= h($spec[0]) ?><input class="form-control" name="<?= h($key) ?>" type="<?= $key==='password'?'password':'text' ?>" value="<?= h($spec[1]) ?>" required></label>
<?php endforeach; endif ?>
<label class="form-label d-block">Einrichtungscode<input class="form-control" name="setup_token" type="password" autocomplete="off" required></label>
<button class="btn btn-primary mt-2">Installation starten</button></form>
<?php elseif (!$actor): ?>
<h2 class="h4"><?= $operatorLogin?'Betreiber-Anmeldung':'Anmelden' ?></h2>
<p><?= $operatorLogin?'Mandanten und Installation verwalten. Dieser Zugang gewährt keine Dokumentrechte.':'Mit Ihrem Benutzerkonto anmelden. Ihre verfügbaren Mandanten werden erst danach angezeigt.' ?></p>
<form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="login"><input type="hidden" name="kind" value="<?= $operatorLogin?'operator':'account' ?>">
<label class="form-label d-block"><?= $operatorLogin?'Betreiber-Benutzer':'Benutzername oder E-Mail' ?><input class="form-control" name="login" autocomplete="username" required></label>
<label class="form-label d-block">Passwort<input class="form-control" type="password" name="password" autocomplete="current-password" required></label>
<?php if ($operatorLogin): ?><details class="mb-3"><summary>Ersteinrichtung: Einrichtungscode</summary><label class="form-label d-block mt-2">Einrichtungscode (nur bis Abschluss der Ersteinrichtung)<input class="form-control" type="password" name="setup_token" autocomplete="off"></label></details><?php endif ?>
<button class="btn btn-primary mt-2">Anmelden</button></form>
<p class="mt-4 mb-0"><a href="<?= $operatorLogin?'?':'?login=operator' ?>"><?= $operatorLogin?'Zur normalen Benutzer-Anmeldung':'Betreiber-Anmeldung – Mandantenverwaltung' ?></a></p>
<?php elseif ($actor->row['must_change_password']): ?>
<h2 class="h4">Startpasswort ändern</h2><p>Vor der weiteren Einrichtung ist ein eigenes Passwort erforderlich (mindestens 6 Zeichen, maximal 72 Bytes).</p>
<form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="password">
<?php foreach (['old_password'=>'Aktuelles Passwort','new_password'=>'Neues Passwort','repeat_password'=>'Neues Passwort wiederholen'] as $key=>$label): ?>
<label class="form-label d-block"><?= h($label) ?><input type="password" class="form-control" name="<?= h($key) ?>" autocomplete="<?= $key==='old_password'?'current-password':'new-password' ?>" required></label>
<?php endforeach ?><button class="btn btn-primary mt-2">Passwort speichern</button></form>
<?php elseif ($actor->row['bootstrap_pending']??false): ?>
<h2 class="h4">Administrator &amp; erster Mandant</h2><p>Es wird ausdrücklich ein separates Admin-Konto für diesen ersten Mandanten angelegt. Der Betreiber-Zugang selbst hat keinen Dokumentzugriff. Das neue Mandanten-Admin-Konto verwendet den Login <code>admin</code> (oder Ihre unten angegebene E-Mail-Adresse) und zunächst dasselbe Passwort, das Sie gerade für den Betreiber festgelegt haben. Danach normal ab- und mit diesem Konto wieder anmelden.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="bootstrap">
<?php foreach (['display_name'=>'Ihr Name','email'=>'E-Mail-Adresse','tenant_name'=>'Name des ersten Mandanten'] as $key=>$label): ?>
<label class="form-label d-block"><?= h($label) ?><input class="form-control" name="<?= h($key) ?>" type="<?= $key==='email'?'email':'text' ?>" required maxlength="<?= $key==='email'?254:190 ?>"></label>
<?php endforeach ?><button class="btn btn-primary mt-2">Ersteinrichtung abschließen</button></form>
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
<script src="<?= h($publicPath) ?>/assets/vendor/bootstrap/bootstrap.bundle.min.js" defer></script><script type="module" src="<?= h($publicPath) ?>/assets/js/account-context.js?v=<?= h(filemtime(dirname(__DIR__,2).'/public/assets/js/account-context.js')) ?>"></script><script type="module" src="<?= h($publicPath) ?>/assets/js/foundation-theme.js?v=<?= h(filemtime(dirname(__DIR__,2).'/public/assets/js/foundation-theme.js')) ?>"></script>
</div></main></body></html>
