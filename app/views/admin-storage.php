<h3 class="h4">Dokumentablage je Mandant</h3>
<p>Nur der Betreiber weist Serverpfade zu. Die vorhandene Basis muss außerhalb des Projekts und Webroots liegen; sie muss nicht auf <code>/o8/</code> enden. Der Projektpfad bleibt <code><?= h(rtrim($root,'/').'/') ?></code>.</p>
<p class="small">Der Webserver benötigt Zugriff auf den Mount. Standardmäßig werden Besitzer, Gruppe und die Unix-Modi 0640/0750 geprüft. Für SMB-/NAS-Mounts, die diese Attribute nicht zulassen, kann dies pro Ablage deaktiviert werden; Pfad-, Schreib-, Lese-, Umbenennungs- und Löschtests bleiben immer verpflichtend.</p>
<?php foreach ($storageLocations as $location): ?>
<article class="tenant-card"><h4 class="h5"><?= h($location['name']) ?> <span class="badge <?= $location['identity_json']?'text-bg-success':'text-bg-secondary' ?>"><?= $location['identity_json']?'Zugewiesen':'Noch nicht eingerichtet' ?></span></h4>
<?php if (!$location['active']): ?><p>Mandant gesperrt; keine Einrichtung möglich.</p><?php else: ?>
<?php if (!$location['root_path']): ?>
<form method="post"><?php postFields('storage_configure','storage'); ?><input type="hidden" name="tenant_id" value="<?= h($location['id']) ?>">
<label class="form-label d-block">Storage-Basispfad (bereits vorhanden)<input class="form-control" name="root_path" placeholder="/mnt/o8/storge" required maxlength="700" value="/mnt/o8/storge"></label>
<div class="row"><label class="form-label col-sm-6">Linux-Besitzer<input class="form-control" name="linux_owner" required maxlength="100" value="www-data"></label><label class="form-label col-sm-6">Linux-Gruppe<input class="form-control" name="linux_group" required maxlength="100" value="www-data"></label></div>
<p class="small text-break">Mandanten-Unterverzeichnis: <code><?= h($location['public_id']) ?>/</code>. Schreib-, Lese-, Umbenennungs- und Löschtest mit eigener temporärer Datei.</p>
<label class="d-block mb-3"><input type="checkbox" name="enforce_file_attributes" value="1" checked> Unix-Dateiattribute erzwingen (Besitzer/Gruppe sowie 0640/0750 prüfen und setzen).</label>
<label class="d-block mb-3"><input type="checkbox" name="confirm" value="yes" required> Diese Ablage prüfen und diesem Mandanten zuweisen.</label><button class="btn btn-primary">Prüfen und speichern</button></form>
<?php else: ?>
<p class="small text-break mb-0">Aktueller Basispfad: <code><?= h($location['root_path']) ?></code><br>Besitzer/Gruppe: <?= h($location['linux_owner']) ?>:<?= h($location['linux_group']) ?> · <?= (bool)$location['enforce_file_attributes']?'Unix-Attribute werden geprüft':'SMB-/NAS-Kompatibilitätsmodus' ?><br>Mandanten-Unterverzeichnis: <code><?= h($location['public_id']) ?>/</code></p>
<details class="tenant-edit mt-3"><summary>Storage-Prüfmodus ändern</summary>
<form method="post" class="mt-3"><?php postFields('storage_configure','storage'); ?><input type="hidden" name="tenant_id" value="<?= h($location['id']) ?>"><input type="hidden" name="root_path" value="<?= h($location['root_path']) ?>"><input type="hidden" name="linux_owner" value="<?= h($location['linux_owner']) ?>"><input type="hidden" name="linux_group" value="<?= h($location['linux_group']) ?>">
<p class="small">Der aktuelle Pfad wird erneut funktional geprüft. Im Kompatibilitätsmodus werden keine Besitzer-, Gruppen- oder Modusänderungen versucht; alle übrigen Storage-Schutzprüfungen bleiben aktiv.</p>
<label class="d-block mb-3"><input type="checkbox" name="enforce_file_attributes" value="1" <?= (bool)$location['enforce_file_attributes']?'checked':'' ?>> Unix-Dateiattribute erzwingen.</label>
<label class="d-block mb-3"><input type="checkbox" name="confirm" value="yes" required> Den Prüfmodus für diese Ablage ausdrücklich erneut prüfen und speichern.</label><button class="btn btn-outline-primary" type="submit">Prüfmodus prüfen und speichern</button></form></details>
<details class="tenant-edit mt-3"><summary>Storage-Pfad nachträglich ändern</summary>
<form method="post" class="mt-3"><?php postFields('storage_relocate','storage'); ?><input type="hidden" name="tenant_id" value="<?= h($location['id']) ?>">
<p class="small">Vorher das vollständige Mandantenverzeichnis <code><?= h($location['public_id']) ?>/</code> einschließlich <code>.o8-storage</code> und aller Dateien in einen anderen vorhandenen Basispfad verschieben oder kopieren. Währenddessen keine Dokumentaktionen ausführen. Die Anwendung verschiebt selbst keine Dateien. Der neue Pfad muss kanonisch sein; Symlinks wie <code>/mnt/o8</code> sind nicht zulässig.</p>
<label class="form-label d-block">Neuer Storage-Basispfad<input class="form-control" name="root_path" placeholder="/srv/o8-storage" required maxlength="700" autocomplete="off"></label>
<label class="d-block mb-3"><input type="checkbox" name="confirm" value="yes" required> Dateien und unveränderte Storage-Markierung sind am Ziel vorhanden; Pfadwechsel prüfen.</label>
<button class="btn btn-outline-primary" type="submit">Neuen Pfad prüfen und übernehmen</button></form></details>
<?php endif ?>
<?php endif ?></article><?php endforeach ?>
