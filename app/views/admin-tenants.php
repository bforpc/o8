<div class="tenant-section-heading"><div><h3 class="h4 mb-1">Mandanten</h3><p class="mb-0">Unabhängige Datenräume und ihre Zugänge verwalten.</p></div><span class="tenant-count"><?= count($tenants) ?> gesamt</span></div>
<details class="tenant-create mb-4"><summary class="fw-semibold">Neuen Mandanten anlegen</summary>
<form method="post" class="tenant-create-form"><?php postFields('tenant_create','tenants'); ?>
<section class="o8-info-group o8-info-group--head">
<label class="form-label d-block">Mandantenname<input class="form-control" name="tenant_name" required maxlength="190"></label>
<p class="small text-secondary">Der Name muss eindeutig sein – auch gegenüber gesperrten Mandanten. Groß-/Kleinschreibung und Leerzeichen am Rand unterscheiden keine Namen.</p>
<label class="form-label d-block">Kontakt-E-Mail (optional)<input class="form-control" name="contact_email" type="email" maxlength="254"></label>
</section><section class="o8-info-group o8-info-group--soft"><h4 class="h6">Erster Administrator dieses Mandanten</h4>
<label class="form-label d-block">Konto<select class="form-select" name="admin_mode" data-account-mode><option value="new">Neues Benutzerkonto anlegen</option><option value="existing">Bestehendes Benutzerkonto zuordnen</option></select></label>
<div data-new-account>
<?php require __DIR__.'/admin-new-user.php'; ?>
</div>
<div data-existing-account hidden><label class="form-label d-block">Login oder E-Mail des bestehenden Kontos<input class="form-control" name="existing_login" maxlength="254" required disabled autocomplete="off"></label><p class="small">Dieses Konto erhält ausdrücklich Admin-Rechte im neuen Mandanten. Passwort und Rechte in anderen Mandanten bleiben unverändert.</p></div></section>
<button class="btn btn-primary">Mandant anlegen</button></form></details>
<?php foreach ($tenants as $tenant): ?>
<article class="tenant-card <?= $tenant['deleting']?'is-deleting':($tenant['active']?'is-active':'is-inactive') ?>" data-tenant-id="<?= h($tenant['id']) ?>">
<div class="tenant-card-heading"><span class="tenant-monogram" aria-hidden="true"><?= h(mb_strtoupper(mb_substr($tenant['name'],0,1))) ?></span><div class="tenant-identity">
<h4 class="h5"><?= h($tenant['name']) ?> <span class="badge <?= $tenant['active']?'text-bg-success':'text-bg-secondary' ?>"><?= $tenant['active']?'Aktiv':'Gesperrt' ?></span></h4>
<p class="small text-break tenant-uuid"><?= h($tenant['public_id']) ?></p>
<?php if ($tenant['contact_email']): ?><p class="tenant-contact"><?= h($tenant['contact_email']) ?></p><?php endif ?>
</div></div>
<?php if ($tenant['deleting']): ?>
<p class="text-danger">Endgültige Löschung läuft oder wurde unterbrochen. Reaktivierung ist nicht mehr möglich.</p>
<a class="btn btn-outline-danger" href="?section=tenant_delete&amp;id=<?= h($tenant['id']) ?>">Löschung fortsetzen / Fortschritt</a></article>
<?php continue; endif ?>
<?php if ($tenant['active']): ?><form method="post" class="mb-3"><?php postFields('tenant_login',''); ?><input type="hidden" name="tenant" value="<?= h($tenant['public_id']) ?>"><button class="btn btn-primary">Abmelden und zum Mandanten-Login</button></form><?php endif ?>
<details class="tenant-edit"><summary>Mandant bearbeiten</summary><form method="post" class="mt-3"><?php postFields('tenant_update','tenants'); ?><input type="hidden" name="id" value="<?= h($tenant['id']) ?>">
<label class="form-label d-block">Name<input class="form-control" name="tenant_name" required maxlength="190" value="<?= h($tenant['name']) ?>"></label>
<label class="form-label d-block">Kontakt-E-Mail<input class="form-control" type="email" name="contact_email" maxlength="254" value="<?= h($tenant['contact_email']) ?>"></label>
<label class="form-label d-block">Status<select name="active" class="form-select"><option value="1" <?= $tenant['active']?'selected':'' ?>>Aktiv</option><option value="0" <?= !$tenant['active']?'selected':'' ?>>Gesperrt</option></select></label>
<label class="d-block mb-3"><input type="checkbox" name="confirm" value="yes" required> Änderung bestätigen. Eine Sperrung beendet alle Mandantensitzungen; Daten bleiben erhalten.</label>
<button class="btn btn-primary">Mandant speichern</button></form></details>
<form method="post" class="tenant-danger-zone"><?php postFields('tenant_delete_review','tenants'); ?><input type="hidden" name="id" value="<?= h($tenant['id']) ?>"><span class="small">Dauerhaft entfernen</span><button class="btn btn-outline-danger">Mandant endgültig löschen …</button></form>
</article>
<?php endforeach ?>
<?php if (!$tenants): ?><div class="tenant-empty">Noch keine Mandanten vorhanden. Lege oben den ersten Datenraum an.</div><?php endif ?>
