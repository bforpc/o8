<section class="settings-card" id="licenseSettings" data-settings-panel="license" hidden data-public-keys="<?= htmlspecialchars(json_encode((object) (require dirname(__DIR__, 2) . '/config/license-public-keys.php'), JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8') ?>">
    <h2>Lizenz &amp; Support · aktueller Mandant</h2>
    <p id="licenseState" class="fw-semibold" role="status">Community · kostenlos</p><p id="licenseDetails"></p>
    <p class="small">Mandanten-ID: <code id="licenseTenantId"></code><br>Installations-ID: <code id="licenseInstallationId"></code></p>
    <form id="licenseImportForm"><label for="licenseFile" class="form-label">Signierte Lizenzdatei (JSON, bis 16 KB)</label><input id="licenseFile" type="file" accept=".json,application/json" class="form-control" required><button id="licenseImport" type="submit" class="btn btn-primary mt-3">Lizenz prüfen und hinterlegen</button></form>
    <button id="licenseRemove" type="button" class="btn btn-surface mt-3" disabled>Hinterlegte Lizenz entfernen</button><p id="licenseMessage" class="small mt-2" role="status"></p>
    <p class="small text-body-secondary mb-0">Prüfung gegen den öffentlichen Ausstellerschlüssel: im Browser oder bei fehlender Unterstützung lokal über PHP. Keine Telefon-nach-Hause-Funktion. Ein Supportablauf sperrt niemals Dokumente, Suche oder Export. M1 speichert die Lizenz im Browser; verbindliche serverseitige Prüfung und Supportportal folgen mit M2. Ohne eingerichteten Ausstellerschlüssel kann keine Supportlizenz aktiviert werden.</p>
</section>
<section class="settings-card" id="tenantAdministration" data-settings-panel="tenants" hidden>
    <h2>Mandantenverwaltung · Betreiber-Vorschau</h2><p>Der Mandanten-Admin verwaltet nur seinen Mandanten. Der spätere Betreiber verwaltet Mandanten und Betriebsparameter, erhält dadurch aber keinen automatischen Dokumentzugriff.</p>
    <p class="alert alert-warning">M1: Browser-Datenräume zum Testen, keine echte Zugriffssperre. Die Betreiberansicht wird erst mit der Anmeldung abgesichert. Neue Mandanten starten ohne Dokumente, Ordner oder Tags; enthalten sind nur zwei fiktive Benutzerprofile.</p>
    <button id="tenantCreate" type="button" class="btn btn-primary">Mandant anlegen</button><div id="tenantList" class="mt-3"></div><p id="tenantStatus" class="small" role="status"></p>
    <p class="small text-body-secondary mb-0">Namen/Kontakt bearbeiten, deaktivieren und reaktivieren. Keine physische Löschung. Erst den Mandanten wechseln, bevor der bisherige deaktiviert werden kann. Storage-Wurzeln, Betriebsrechte und Vertrauensschlüssel werden künftig ausschließlich vom Betreiber verwaltet.</p>
</section>
