<h3 class="h5">Mein Konto</h3>
<section class="o8-info-group o8-info-group--head"><dl class="row mb-0"><dt class="col-sm-4">Benutzer</dt><dd class="col-sm-8"><?= h($actor->row['login']) ?></dd><dt class="col-sm-4">E-Mail</dt><dd class="col-sm-8 text-break"><?= h($actor->row['email']??$actor->row['email_normalized']) ?></dd></dl></section>
<p class="small text-secondary">Änderung der E-Mail-Adresse und E-Mail-Verifikation folgen mit dem Mailversand. Betreiber- und Mandantenkonto haben unabhängige Passwörter.</p>
<?php if ($actor->kind!=='operator'): ?>
<p>Dieses Benutzerkonto und sein Passwort gelten für alle zugeordneten Mandanten. Rollen und Dokumentrechte gelten dagegen immer nur im jeweiligen Mandanten.</p>
<details class="o8-info-group o8-info-group--soft"><summary>Einladung zu einem weiteren Mandanten annehmen</summary><form method="post" class="mt-3"><?php postFields('invitation_accept','account'); ?><label class="form-label d-block">Einmaliger Einladungscode<input class="form-control" name="invitation" required pattern="[a-fA-F0-9]{48}" maxlength="48" autocomplete="off"></label><p class="small">Nur einen Code annehmen, den du von einem vertrauenswürdigen Mandanten-Admin erhalten hast. Danach wird dieser Mandant geöffnet.</p><button class="btn btn-primary">Einladung annehmen</button></form></details>
<?php endif ?>
<?php if ($actor->kind==='tenant'): require __DIR__.'/admin-sources.php'; endif ?>
<?php if ($actor->kind==='tenant'): ?>
<section class="o8-info-group o8-info-group--soft" aria-labelledby="trashRetentionTitle"><h4 class="h6" id="trashRetentionTitle">Papierkorb-Aufbewahrung</h4>
<form method="post"><?php postFields('trash_retention_save','account'); ?>
<label class="form-label d-block">Eigene Dokumente nach Tagen endgültig löschen<input class="form-control" type="number" name="days" min="0" max="36500" step="1" value="<?= h($trashRetentionDays) ?>" required></label>
<p class="small text-secondary">0 = unbegrenzt aufbewahren. Ab 1 Tag zählt die Frist ab dem Verschieben in den Papierkorb. Die Einstellung gilt für Ihre Dokumente in diesem Mandanten, auch wenn ein Admin sie in den Papierkorb gelegt hat.</p>
<label class="d-block mb-2"><input type="checkbox" name="retention_confirm" value="yes"> Mir ist bewusst, dass beim Aktivieren oder Verkürzen der Frist bereits vorhandene Papierkorb-Dokumente beim nächsten Dienstlauf endgültig gelöscht werden können.</label>
<button class="btn btn-primary" type="submit">Aufbewahrung speichern</button></form>
<?php if ($trashRetentionDays>0): ?><div class="alert <?= $trashWorkerActive?'alert-success':'alert-warning' ?> mt-3 mb-0" role="status"><?= $trashWorkerActive?'Löschdienst wurde kürzlich ausgeführt.':'Kein kürzlich ausgeführter Löschdienst erkannt. Ohne Cronjob erfolgt keine automatische endgültige Löschung.' ?> Betreiberhinweis: <code>php <?= h($root) ?>/bin/trash-retention.php</code> regelmäßig, z. B. alle fünf Minuten, ausführen.</div><?php else: ?><p class="small text-secondary mt-2 mb-0">Bei 0 Tagen ist kein Löschdienst für Ihre Dokumente erforderlich.</p><?php endif ?>
</section>
<?php endif ?>

<?php if ($actor->kind==='tenant' && $actor->row['role']==='admin'): ?>
<section class="o8-info-group"><h4 class="h6">Storage-Prüfung</h4>
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
    <p class="mb-0"><strong>Storage ist in Ordnung.</strong><br>Pfad: <?= h($storageCheck['root_path']) ?>/<?= h($storageCheck['target']) ?></p>
</div>
<?php elseif ($storageCheck['status']==='broken'): ?>
<div class="alert alert-warning">
    <p class="mb-0"><strong>Storage-Identität ist beschädigt.</strong></p>
    <ul class="mb-2">
    <?php foreach ($storageCheck['issues'] as $issue): ?>
        <li><?= h($issue) ?></li>
    <?php endforeach; ?>
    </ul>
    <p class="small text-secondary mt-2 mb-0">Bitte den Betreiber kontaktieren. Er kann einen verschobenen Pfad nur mit dem ursprünglichen Mandantenverzeichnis und dessen unveränderter Storage-Markierung erneut prüfen.</p>
</div>
<?php elseif ($storageCheck['status']==='missing'): ?>
<div class="alert alert-danger">
    <p class="mb-0"><strong>Keine Storage-Zuordnung gefunden.</strong><br>Bitte den Betreiber kontaktieren.</p>
</div>
<?php else: ?>
<div class="alert alert-danger">
    <p class="mb-0"><strong>Storage-Fehler:</strong> <?= h($storageCheck['error']??'Unbekannter Fehler') ?></p>
</div>
<?php endif; ?>
</section>
<?php endif; ?>

<section class="o8-info-group o8-info-group--strong"><h4 class="h6">Eigenes Passwort ändern</h4>
<form method="post"><?php postFields('password','account'); ?>
<?php foreach (['old_password'=>'Aktuelles Passwort','new_password'=>'Neues Passwort','repeat_password'=>'Neues Passwort wiederholen'] as $key=>$label): ?>
<label class="form-label d-block"><?= h($label) ?><input type="password" class="form-control" name="<?= h($key) ?>" autocomplete="<?= $key==='old_password'?'current-password':'new-password' ?>" required></label>
<?php endforeach ?><p class="small">Mindestens 6 Zeichen, maximal 72 Bytes. Andere Anmeldungen dieses Kontos werden abgemeldet.</p><button class="btn btn-primary">Passwort speichern</button></form></section>
