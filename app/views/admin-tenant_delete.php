<?php if ($deletionJob): ?>
<h3 class="h5">Mandant wird endgültig gelöscht</h3>
<p class="text-break">Kennung: <?= h($deletionJob['uuid']) ?></p>
<div role="status" class="alert alert-info">
<strong><?= $deletionJob['phase']==='files'?'Dateien und Verzeichnisse entfernen':'Datenbank bereinigen' ?></strong><br>
Gelöschte Dateisystem-Einträge: <?= h($deletionJob['files_removed']) ?> · Gelöschte Datensätze: <?= h($deletionJob['rows_removed']) ?>
</div>
<p>Die Verarbeitung erfolgt in kleinen Schritten. Dieses Fenster bitte geöffnet lassen. Nach einer Unterbrechung kann die Löschung über die Mandantenliste fortgesetzt werden. Der Mandant bleibt gesperrt.</p>
<form method="post" <?= !$error?'data-tenant-delete-next':'' ?>><?php postFields('tenant_delete_step','tenants'); ?><input type="hidden" name="id" value="<?= h($deletionId) ?>"><button class="btn btn-danger">Nächsten Löschschritt ausführen</button></form>
<?php if (!$error): ?><script src="<?= h($publicPath) ?>/assets/js/tenant-delete.js" defer></script><?php endif ?>
<?php elseif ($deletionReview): ?>
<h3 class="h5">Mandant endgültig löschen – Bestätigung <?= h($deletionReview['stage']) ?> von 2</h3>
<div class="alert alert-danger"><strong class="text-break"><?= h($deletionReview['name']) ?></strong><br><span class="text-break"><?= h($deletionReview['uuid']) ?></span><br>Alle Dokumente einschließlich Papierkorb und Eingang, Dateien, Buchungsdaten, Ordner, Tags, Benutzer, Quellen, Einstellungen und Mandantenlizenzen werden unwiderruflich entfernt.</div>
<p>Aktueller Prüfstand: <?= h($deletionReview['documents']) ?> Dokumente · <?= h($deletionReview['users']) ?> Benutzer · <?= h($deletionReview['folders']) ?> Ordner. Auch zwischenzeitlich hinzugekommene Daten dieses Mandanten werden gelöscht.</p>
<p>Andere Mandanten und Betreiberkonten bleiben erhalten. Benutzerkonten mit weiteren Mandantenzuordnungen bleiben ebenfalls erhalten; nur ihre Zuordnung zu diesem Mandanten entfällt. Externe Sicherungen und Originale in Mailboxen/WebDAV werden nicht gelöscht. In der Installation bleibt nur ein technischer Löschvermerk mit der Mandantenkennung.</p>
<form method="post"><?php postFields($deletionReview['stage']===1?'tenant_delete_confirm':'tenant_delete_start','tenants'); ?>
<input type="hidden" name="id" value="<?= h($deletionReview['id']) ?>"><input type="hidden" name="delete_token" value="<?= h($deletionReview['token']) ?>">
<?php if ($deletionReview['stage']===2): ?>
<label class="form-label d-block">Zur letzten Bestätigung den Mandantennamen exakt eingeben<input class="form-control" name="tenant_name" autocomplete="off" required></label>
<?php endif ?>
<label class="d-block mb-3"><input type="checkbox" name="confirm" value="yes" required> <?= $deletionReview['stage']===1?'Ich möchte diesen Mandanten samt allen Daten löschen.':'Ich bestätige die endgültige Löschung. Ab dem Start ist sie nicht mehr abbrechbar oder rückgängig zu machen.' ?></label>
<button class="btn btn-danger"><?= $deletionReview['stage']===1?'Weiter zur zweiten Bestätigung':'Jetzt endgültig löschen' ?></button></form>
<form method="post" class="mt-3"><?php postFields('tenant_delete_cancel','tenants'); ?><button class="btn btn-outline-secondary">Abbrechen – nichts löschen</button></form>
<?php else: ?>
<p>Keine gültige Löschvorbereitung oder kein laufender Auftrag vorhanden.</p><a href="?section=tenants">Zur Mandantenverwaltung</a>
<?php endif ?>
