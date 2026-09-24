<h1 class="h4">Einstellungen</h1>
<?php if ($actor->row['role']==='admin'): ?><section class="o8-info-group o8-info-group--head mb-3" aria-labelledby="sessionSettingsTitle">
<h2 class="h5" id="sessionSettingsTitle">Mandanten-Sitzung</h2>
<form method="post" class="d-flex flex-wrap align-items-end gap-2"><?php postFields('session_timeout_save','settings'); ?>
<label class="form-label mb-0">Inaktivitätsfrist in Minuten<input class="form-control" type="number" name="minutes" min="5" max="10080" required value="<?= (int)$sessionTimeoutMinutes ?>"></label>
<button class="btn btn-primary" type="submit">Speichern</button></form>
<p class="small text-secondary mt-2 mb-0">Nach dieser Zeit ohne Aktivität endet die Anmeldung in diesem Mandanten. Standard: 480 Minuten.</p>
</section><?php endif ?>
<?php require __DIR__.'/admin-tags.php'; ?>
