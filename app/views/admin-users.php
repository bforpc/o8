<h3 class="h5">Benutzer dieses Mandanten</h3>
<p class="small text-secondary">Anzeigename, Rolle und Sperre gelten nur in diesem Mandanten. Das gemeinsame Kontopasswort kann hier nicht geändert werden.</p>
<?php if ($inviteCode): ?><div class="alert alert-success" role="status"><label class="d-block">Einmaliger Einladungscode<input class="form-control font-monospace mt-2" readonly value="<?= h($inviteCode) ?>"></label><p class="mb-0 mt-2">Jetzt kopieren und der gewünschten Person sicher mitteilen. Der Code wird nur einmal angezeigt, ist 7 Tage gültig und kann einmal verwendet werden. Wer ihn einlöst, erhält die gewählte Rolle.</p></div><?php endif ?>
<details class="o8-info-group o8-info-group--soft mb-3"><summary class="fw-semibold">Bestehendes Benutzerkonto einladen</summary><form method="post" class="mt-3"><?php postFields('user_invite','users'); ?><label class="form-label d-block">Rolle<select name="role" class="form-select"><option value="user">User – eigene Dokumente</option><option value="admin">Admin – alle Dokumente dieses Mandanten</option></select></label><p class="small">Die Person meldet sich mit ihrem bestehenden Konto an und löst den Code unter „Mein Konto“ ein. Es wird kein zweites Konto erstellt.</p><button class="btn btn-outline-primary">Einladungscode erstellen</button></form></details>
<details class="o8-info-group o8-info-group--soft mb-3"><summary class="fw-semibold">Benutzer anlegen</summary><form method="post" class="mt-3"><?php postFields('user_create','users'); ?>
<?php require __DIR__.'/admin-new-user.php'; ?>
<label class="form-label d-block">Rolle<select name="role" class="form-select"><option value="user">User – eigene Dokumente</option><option value="admin">Admin – alle Dokumente dieses Mandanten</option></select></label>
<button class="btn btn-primary">Benutzer anlegen</button></form></details>
<form method="get" class="d-flex gap-2 mb-3"><input type="hidden" name="section" value="users"><?php if (($_GET['overlay']??'')==='1'): ?><input type="hidden" name="overlay" value="1"><?php endif ?><label class="flex-grow-1">Benutzer suchen<input class="form-control" name="q" value="<?= h(is_string($_GET['q']??null)?$_GET['q']:'') ?>" placeholder="Login, Name oder E-Mail" maxlength="190"></label><button class="btn btn-outline-primary align-self-end">Suchen</button></form>
<?php if (count($users)>200): ?><p role="status">Mehr als 200 Treffer. Bitte die Suche eingrenzen.</p><?php elseif (!$users): ?><p>Keine passenden Benutzer.</p><?php endif ?>
<?php foreach (array_slice($users,0,200) as $user): ?>
<article class="o8-info-group o8-info-group--head mb-3" data-user-id="<?= h($user['id']) ?>">
<h4 class="h6"><?= h($user['display_name']) ?> <span class="badge <?= $user['active']?'text-bg-success':'text-bg-secondary' ?>"><?= $user['active']?'Aktiv':'Gesperrt' ?></span> <span class="badge text-bg-light"><?= h($user['role']) ?></span></h4>
<p class="small text-break"><?= h($user['login']) ?> · <?= h($user['email']) ?><?= $user['must_change_password']?' · Passwortwechsel ausstehend':'' ?></p>
<details class="o8-info-group o8-info-group--soft"><summary>Benutzer bearbeiten</summary><form method="post" class="mt-3"><?php postFields('user_update','users'); ?>
<input type="hidden" name="id" value="<?= h($user['id']) ?>"><input type="hidden" name="version" value="<?= h($user['auth_version']) ?>">
<label class="form-label d-block">Anzeigename<input class="form-control" name="display_name" required maxlength="190" value="<?= h($user['display_name']) ?>"></label>
<label class="form-label d-block">Rolle<select name="role" class="form-select"><option value="user" <?= $user['role']==='user'?'selected':'' ?>>User</option><option value="admin" <?= $user['role']==='admin'?'selected':'' ?>>Admin</option></select></label>
<label class="form-label d-block">Status<select name="active" class="form-select"><option value="1" <?= $user['active']?'selected':'' ?>>Aktiv</option><option value="0" <?= !$user['active']?'selected':'' ?>>Gesperrt</option></select></label>
<label class="d-block mb-3"><input type="checkbox" name="confirm" value="yes" required> Änderungen bestätigen und den geöffneten Mandantenzugriff dieses Benutzers widerrufen. Andere Mandanten bleiben unverändert.</label>
<button class="btn btn-primary">Benutzer speichern</button></form></details>
</article><?php endforeach ?>
