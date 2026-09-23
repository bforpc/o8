<h3 class="h5">Mein Konto</h3>
<dl class="row"><dt class="col-sm-4">Benutzer</dt><dd class="col-sm-8"><?= h($actor->row['login']) ?></dd><dt class="col-sm-4">E-Mail</dt><dd class="col-sm-8 text-break"><?= h($actor->row['email']??$actor->row['email_normalized']) ?></dd></dl>
<p class="small text-secondary">Änderung der E-Mail-Adresse und E-Mail-Verifikation folgen mit dem Mailversand. Betreiber- und Mandantenkonto haben unabhängige Passwörter.</p>
<?php if ($actor->kind!=='operator'): ?>
<p>Dieses Benutzerkonto und sein Passwort gelten für alle zugeordneten Mandanten. Rollen und Dokumentrechte gelten dagegen immer nur im jeweiligen Mandanten.</p>
<details class="border rounded p-3 mb-4"><summary>Einladung zu einem weiteren Mandanten annehmen</summary><form method="post" class="mt-3"><?php postFields('invitation_accept','account'); ?><label class="form-label d-block">Einmaliger Einladungscode<input class="form-control" name="invitation" required pattern="[a-fA-F0-9]{48}" maxlength="48" autocomplete="off"></label><p class="small">Nur einen Code annehmen, den du von einem vertrauenswürdigen Mandanten-Admin erhalten hast. Danach wird dieser Mandant geöffnet.</p><button class="btn btn-primary">Einladung annehmen</button></form></details>
<?php endif ?>
<?php if ($actor->kind==='tenant'): require __DIR__.'/admin-sources.php'; endif ?>
<h4 class="h6 mt-4">Eigenes Passwort ändern</h4>
<form method="post"><?php postFields('password','account'); ?>
<?php foreach (['old_password'=>'Aktuelles Passwort','new_password'=>'Neues Passwort','repeat_password'=>'Neues Passwort wiederholen'] as $key=>$label): ?>
<label class="form-label d-block"><?= h($label) ?><input type="password" class="form-control" name="<?= h($key) ?>" autocomplete="<?= $key==='old_password'?'current-password':'new-password' ?>" required></label>
<?php endforeach ?><p class="small">Mindestens 6 Zeichen, maximal 72 Bytes. Andere Anmeldungen dieses Kontos werden abgemeldet.</p><button class="btn btn-primary">Passwort speichern</button></form>
