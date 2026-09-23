<?php
$settingsMenus = [
    'Mein Benutzer' => ['profile'=>'Benutzerdaten', 'appearance'=>'Aussehen', 'layout'=>'Spalten & Ansicht', 'trash'=>'Papierkorb', 'imap'=>'Mailboxabruf', 'webdav'=>'WebDAV-Abruf'],
    'Mein Mandant · Admin' => ['users'=>'Benutzer & Rechte', 'tags'=>'Tags', 'accounting'=>'Buchhaltung', 'license'=>'Lizenz & Support'],
    'Betreiber' => ['tenants'=>'Mandantenverwaltung', 'storage'=>'Projekt & Storage', 'preview'=>'Vorschau & Ausblick'],
];
?>
<main id="page-settings" class="page secondary-page" hidden>
    <div class="eyebrow">DEIN ARBEITSPLATZ</div><h1>Einstellungen</h1>
    <p class="page-lead">Persönlich, mandantenbezogen und Betrieb – jeder Bereich hat sein eigenes Menü.</p>
    <p class="small text-body-secondary">M1-Vorschau ohne Anmeldung oder wirksame Rechteprüfung. Nur Beispieldaten verwenden; keine echten Zugangsdaten eingeben.</p>
    <div class="settings-profile-context">
        <div><span class="setting-label">Aktiver Benutzer im gewählten Mandanten</span><strong id="settingsProfileName"></strong></div>
        <div><label for="layoutProfile" class="form-label">Vorschauprofil</label><select id="layoutProfile" class="form-select"><option value="standard">Standard</option><option value="second">Zweites Profil</option></select></div>
    </div>
    <div class="settings-mobile-menu"><label for="settingsSectionSelect" class="form-label">Einstellungsbereich</label><select id="settingsSectionSelect" class="form-select">
        <?php foreach ($settingsMenus as $group => $items): ?><optgroup label="<?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8') ?>">
            <?php foreach ($items as $key => $label): ?><option value="<?= $key ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
        </optgroup><?php endforeach; ?>
    </select></div>
    <div class="settings-workspace">
        <nav class="settings-menu" aria-label="Einstellungsbereiche">
            <?php foreach ($settingsMenus as $group => $items): ?>
            <div class="settings-menu-group"><h2><?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8') ?></h2>
                <?php foreach ($items as $key => $label): ?><button type="button" data-settings-section="<?= $key ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></button><?php endforeach; ?>
            </div><?php endforeach; ?>
        </nav>
        <div class="settings-panel-content">
            <?php require __DIR__ . '/settings-personal.php'; ?>
            <?php require __DIR__ . '/settings-appearance.php'; ?>
            <section id="layoutSettings" class="settings-card" data-settings-panel="layout" hidden><h2>Persönliche Spaltenbreiten</h2><p>Trennlinien im Dokumentarbeitsplatz ziehen oder mit den Pfeiltasten verschieben. Die Breiten gehören ausschließlich zum aktiven Benutzerprofil dieses Mandanten.</p><button class="btn btn-surface" id="resetColumnsSettings">Spalten auf Standard setzen</button><p id="layoutSaveStatus" class="small mt-2 mb-0" role="status"></p><p class="small text-body-secondary mt-3 mb-0">Darstellung und Spalten werden sofort gespeichert. Andere Formulare haben einen eigenen Speichern-Button. Menüwechsel bewahren ungespeicherte Eingaben; Profil- und Mandantenwechsel laden andere Daten.</p></section>
            <?php require __DIR__ . '/settings-admin.php'; ?>
            <?php require __DIR__ . '/settings-tenancy.php'; ?>
            <?php require __DIR__ . '/settings-accounting.php'; ?>
        </div>
    </div>
</main>
