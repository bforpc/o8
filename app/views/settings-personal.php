<section id="profileSettings" class="settings-card" data-settings-panel="profile">
    <h2>Meine Benutzerdaten</h2><p>Name und E-Mail für das oben gewählte persönliche Profil.</p>
    <form id="personalProfileForm" class="settings-form-grid">
        <div><label for="personalName" class="form-label">Anzeigename</label><input id="personalName" name="name" class="form-control" maxlength="190" required autocomplete="off"></div>
        <div><label for="personalEmail" class="form-label">E-Mail-Adresse</label><input id="personalEmail" name="email" type="email" class="form-control" maxlength="254" required autocomplete="off"></div>
        <div class="settings-wide"><button class="btn btn-primary" type="submit">Demo-Profil speichern</button><p id="personalProfileStatus" role="status" class="small mt-2 mb-0"></p></div>
    </form>
    <p class="small text-body-secondary mt-3 mb-0">Nur erfundene Daten verwenden. Login, Passwortwechsel und Bestätigung geänderter E-Mail-Adressen folgen mit der Benutzeranmeldung. Der Profilwechsel ist keine Anmeldung.</p>
</section>
<section id="personalTrashSettings" class="settings-card" data-settings-panel="trash" hidden>
    <h2>Mein Papierkorb</h2>
    <p>Aufbewahrungszeit für deine eigenen Dokumente im Papierkorb dieses Mandanten, gerechnet ab dem Verschieben in den Papierkorb.</p>
    <form id="personalTrashForm" class="settings-form-grid">
        <div><label for="personalTrashDays" class="form-label">Aufbewahrung in Tagen</label><input id="personalTrashDays" name="trashDays" type="number" min="0" max="36500" step="1" class="form-control" required aria-describedby="personalTrashHelp"><p id="personalTrashHelp" class="small mt-2">0 = unbegrenzt aufbewahren (Standard). Bei einer positiven Zahl werden Dokumente nach Ablauf dieser Frist endgültig gelöscht.</p></div>
        <div class="settings-wide"><button type="submit" class="btn btn-primary">Papierkorb-Einstellung speichern</button><p id="personalTrashStatus" class="small mt-2 mb-0" role="status"></p></div>
    </form>
    <p class="small text-body-secondary mt-3 mb-0">M1 speichert nur die Einstellung; es findet noch keine automatische Löschung statt. Die serverseitige Umsetzung folgt in M2. Kürzere Fristen können dann auch bereits länger im Papierkorb liegende Dokumente betreffen.</p>
</section>
    <section id="mailboxSettings" class="settings-card" data-settings-panel="imap" hidden><h2>Mein Mailboxabruf</h2><p class="text-body-secondary">Persönlicher IMAP-Entwurf. Neue Dokumente gehören dem Benutzer dieser Quelle und landen in dessen Eingang.</p>
        <form id="imapDraftForm" class="settings-form-grid">
            <div class="settings-wide"><label for="imapHost" class="form-label">Mailserver</label><input id="imapHost" name="host" class="form-control" placeholder="imap.example.invalid" required maxlength="255"></div>
            <div><label for="imapPort" class="form-label">Port</label><input id="imapPort" name="port" type="number" min="1" max="65535" class="form-control" required></div>
            <div><label for="imapSecurity" class="form-label">Transportverschlüsselung</label><select id="imapSecurity" name="security" class="form-select"><option value="tls">TLS (direkt)</option><option value="starttls">STARTTLS (verpflichtend)</option></select></div>
            <div class="settings-wide"><label for="imapUsername" class="form-label">Benutzername</label><input id="imapUsername" name="username" class="form-control" required maxlength="255" autocomplete="off"></div>
            <div><label for="imapFolder" class="form-label">Postfachordner</label><input id="imapFolder" name="folder" class="form-control" required maxlength="768"></div>
            <div><label for="imapInterval" class="form-label">Intervall (Minuten; 0 = manuell)</label><input id="imapInterval" name="intervalMinutes" type="number" min="0" max="1440" class="form-control" required></div>
            <div><label for="imapAuth" class="form-label">Anmeldung (geplant)</label><select id="imapAuth" name="auth" class="form-select"><option value="password">Passwort / App-Passwort</option><option value="oauth2">OAuth2</option></select></div>
            <div><label for="imapSecret" class="form-label">Passwort / Token</label><input id="imapSecret" type="password" class="form-control" placeholder="Erst mit Serveranbindung" disabled autocomplete="new-password"></div>
            <div class="settings-wide"><button class="btn btn-primary" type="submit">Mailbox-Entwurf speichern</button><p id="imapDraftStatus" class="small mt-2 mb-0" role="status"></p></div>
        </form>
        <p class="small text-body-secondary mt-3 mb-0">Kein Abruf und kein Verbindungstest aktiv. Quellnachrichten bleiben unverändert. Zertifikatsprüfung ist verpflichtend. Geheimnisse werden später nur serverseitig verschlüsselt gespeichert und nie zurück angezeigt.</p>
    </section>
    <section id="webdavSettings" class="settings-card" data-settings-panel="webdav" hidden><h2>Mein WebDAV-Abruf</h2><p class="text-body-secondary">Persönlicher Verbindungsentwurf. Quellen bleiben getrennt; mehrere Quellen je Benutzer sind im Datenmodell vorgesehen.</p>
        <form id="webdavDraftForm" class="settings-form-grid">
            <div class="settings-wide"><label for="webdavUrl" class="form-label">WebDAV-Basisadresse (HTTPS)</label><input id="webdavUrl" name="url" type="url" class="form-control" placeholder="https://cloud.example.invalid/remote.php/dav/files/name/" required maxlength="768"></div>
            <div class="settings-wide"><label for="webdavUsername" class="form-label">Benutzername</label><input id="webdavUsername" name="username" class="form-control" required maxlength="255" autocomplete="off"></div>
            <div class="settings-wide"><label for="webdavFolder" class="form-label">Quellordner innerhalb der Basisadresse</label><input id="webdavFolder" name="folder" class="form-control" required maxlength="768"></div>
            <div><label for="webdavInterval" class="form-label">Intervall (Minuten; 0 = manuell)</label><input id="webdavInterval" name="intervalMinutes" type="number" min="0" max="1440" class="form-control" required></div>
            <div><label for="webdavSecret" class="form-label">Passwort / App-Token</label><input id="webdavSecret" type="password" class="form-control" placeholder="Erst mit Serveranbindung" disabled autocomplete="new-password"></div>
            <label class="settings-wide"><input id="webdavRecursive" name="recursive" type="checkbox" class="form-check-input"> Unterordner einbeziehen</label>
            <div class="settings-wide"><button class="btn btn-primary" type="submit">WebDAV-Entwurf speichern</button><p id="webdavDraftStatus" class="small mt-2 mb-0" role="status"></p></div>
        </form>
        <p class="small text-body-secondary mt-3 mb-0">Keine Tokens in URLs eintragen. Kein Abruf aktiv; Quelldateien werden nicht gelöscht. Zielordner sind virtuelle DMS-Ordner und ändern keinen Dateipfad.</p>
    </section>
