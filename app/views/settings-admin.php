<section id="adminSettings" class="settings-card" data-settings-panel="users" hidden><h2>Benutzer und Berechtigungen</h2>
    <p><strong>Admin:</strong> alle Dokumente, Benutzer und Einstellungen seines Mandanten. <strong>User:</strong> ausschließlich eigene Dokumente, Ordner, Buchungen, Abrufquellen und persönliche Einstellungen. Ordnerlinks erweitern niemals die Rechte.</p>
    <div class="table-responsive"><table class="table"><caption>Fiktive Benutzer – keine echte Anmeldung. Der Profilwechsel oben lädt nur persönliche Vorschauwerte.</caption><thead><tr><th>Name</th><th>E-Mail</th><th>Rolle</th><th>Status</th></tr></thead><tbody id="settingsUsers"></tbody></table></div>
    <p class="small text-body-secondary mb-0">Anlegen, Einladen, Sperren, Passwort zurücksetzen und Rollenvergabe folgen in M2. Einzelrechte, weitere Rollen und spätere Freigaben sind im Konzept vorgesehen. Der letzte aktive Admin darf nicht entfernt werden.</p>
</section>
<section id="storageSettings" class="settings-card" data-settings-panel="storage" hidden><h2>Projekt und Storage · Betreiber-Zuweisung</h2><p>Getrennte Storage-Zuweisung je Mandant als Betreiber-Entwurf. Speichern verändert weder Serverpfade noch Linux-Rechte und verschiebt keine Dateien.</p>
    <div class="mb-3"><label for="projectRoot" class="form-label">Projektpfad (aus Installation, nur Anzeige)</label><input id="projectRoot" class="form-control" readonly value="<?= htmlspecialchars(rtrim(dirname(__DIR__, 2), '/') . '/', ENT_QUOTES, 'UTF-8') ?>"><p class="small text-body-secondary mt-2">Das Projektverzeichnis endet auf /o8/. Eine Projektverlagerung ist ein Deployment, kein einfaches Umbenennen in der Datenbank.</p></div>
    <form id="globalDraftForm" class="settings-form-grid">
        <div class="settings-wide"><label for="storageRoot" class="form-label">Storage-Basispfad</label><input id="storageRoot" name="storagePath" class="form-control" placeholder="/srv/storage/o8/" required maxlength="768"></div>
        <div><label for="storageOwner" class="form-label">Linux-Besitzer (Name oder UID)</label><input id="storageOwner" name="linuxOwner" class="form-control" placeholder="o8-storage" required maxlength="100"></div>
        <div><label for="storageGroup" class="form-label">Linux-Gruppe (Name oder GID)</label><input id="storageGroup" name="linuxGroup" class="form-control" placeholder="o8-storage" required maxlength="100"></div>
        <div><label for="storageFileMode" class="form-label">Rechte neuer Dateien</label><select id="storageFileMode" name="fileMode" class="form-select"><option>0600</option><option>0640</option><option>0660</option></select></div>
        <div><label for="storageDirectoryMode" class="form-label">Rechte neuer Verzeichnisse</label><select id="storageDirectoryMode" name="directoryMode" class="form-select"><option>0700</option><option>0750</option><option>0770</option></select></div>
        <div><label for="maxUploadMb" class="form-label">Geplantes Uploadlimit (MB)</label><input id="maxUploadMb" name="maxUploadMb" type="number" min="1" max="1024" class="form-control" required></div>
        <div class="settings-wide"><button class="btn btn-primary" type="submit">Globalen Entwurf speichern</button><p id="globalDraftStatus" class="small mt-2 mb-0" role="status"></p></div>
    </form>
    <p class="small text-body-secondary mt-3 mb-0">Storage möglichst außerhalb des Webroots. Prüfung von Mount, Schreibrechten, freiem Speicher und atomarer Dateiübernahme folgt serverseitig. SMB/NFS können Besitzer und Rechte durch Mount-Optionen vorgeben. Keine automatischen rekursiven chown/chmod-Aktionen. Eine eingetragene Frist aktiviert keine automatische Löschung.</p>
</section>
<section id="tagSettings" class="settings-card" data-settings-panel="tags" hidden><h2>Tag-Verwaltung</h2><p>Katalog dieses Mandanten, künftig nur durch dessen Admins änderbar. User dürfen vorhandene Tags ihren eigenen Dokumenten zuordnen. Import und AI erzeugen keine neuen Tags.</p>
    <div class="settings-tag-toolbar"><div><label for="tagAdminSearch" class="form-label">Tags suchen</label><input id="tagAdminSearch" type="search" class="form-control" placeholder="Name eingeben …"></div><button id="tagAdminCreate" class="btn btn-primary" type="button">Tag hinzufügen</button></div>
    <p id="tagAdminCount" class="small mt-2" role="status"></p><div id="tagAdminList" class="tag-admin-list"></div><p id="tagAdminStatus" class="small mt-2" role="status"></p>
    <p class="small text-body-secondary mb-0">Umbenennen aktualisiert alle Zuordnungen. Löschen ist nach Rückfrage nur unbenutzt möglich; Eingang und Papierkorb zählen mit. Später kann ein benutzter Tag alternativ deaktiviert oder kontrolliert zusammengeführt werden.</p>
</section>
<section id="previewSettings" class="settings-card" data-settings-panel="preview" hidden><h2>Vorschau &amp; nächste Meilensteine</h2>
    <ul class="settings-roadmap">
        <li><strong>Sicherheit:</strong> serverseitige Rechte bei Suche, Zählern, Vorschau, Download, Auswertung und Hintergrundjobs; CSRF, sichere Sessions, Anmeldelimits, Audit-Protokoll.</li>
        <li><strong>Import:</strong> Zeitpläne, Verbindungsprüfung, Dateitypen/Größen, Duplikate, Quarantäne, Fortschritt, Wiederholung und Abrufstatus je Benutzer.</li>
        <li><strong>AI / OCR:</strong> Anbieter, Modell, Freigabe externer Verarbeitung, verschlüsselte API-Schlüssel, Timeout, JSON-Konfiguration und Umgang mit manuellen/AI-Tags.</li>
        <li><strong>Betrieb:</strong> Backup und getestete Wiederherstellung von Datenbank, Dateien und Schlüsseln; SMTP für Einladungen, Passwort-Reset und Benachrichtigungen; Zeitzone, Sprache, Währung, Speicherwarnungen.</li>
        <li><strong>Datenlebenszyklus:</strong> Aufbewahrung, Löschsperren, endgültiges Löschen nur explizit; Besitzerwechsel, Benutzerdeaktivierung, sichere Konfigurationsexporte und o7-Migrationsprüfung.</li>
    </ul>
    <hr><h3>Beispieldaten zurücksetzen</h3><p>Nur der aktuelle Mandant ist betroffen. Persönliche Darstellung und andere Mandanten bleiben erhalten.</p><button class="btn btn-surface" id="resetDemo"><svg class="icon"><use href="#i-restore"/></svg> Beispieldaten zurücksetzen</button>
</section>
