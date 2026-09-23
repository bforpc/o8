<section id="accountingSettings" class="settings-card" data-settings-panel="accounting" hidden><h2>Buchhaltung · Admin</h2><p>MWSt-Sätze und Buchungskonten für alle Dokumentarten. In dieser Vorschau im Browser gespeichert; kein vollständiger SKR-Katalog vorgegeben.</p>
            <form id="accountingSetupForm">
                <label for="setupFramework" class="form-label">Kontorahmen / Bezeichnung</label><input id="setupFramework" class="form-control" maxlength="100" placeholder="z. B. eigener Kontenplan oder SKR-Bezeichnung">
                <div class="accounting-setup-grid mt-3"><div><label for="setupVatRates" class="form-label">MWSt-Sätze in Prozent</label><textarea id="setupVatRates" class="form-control" rows="5" required aria-describedby="setupVatHelp"></textarea><p class="small text-body-secondary mt-2" id="setupVatHelp">Ein Satz pro Zeile, ohne %-Zeichen. Vorlage: 19 und 7.</p></div>
                <div><label for="setupAccounts" class="form-label">Buchungskonten</label><textarea id="setupAccounts" class="form-control" rows="8" placeholder="Kontonummer; Bezeichnung" aria-describedby="setupAccountsHelp"></textarea><p class="small text-body-secondary mt-2" id="setupAccountsHelp">Ein Konto pro Zeile: Nummer; Bezeichnung. Die Auswahl sucht nach beiden Feldern. Verwendete Konten und Steuersätze können nicht entfernt werden.</p></div></div>
                <button type="submit" class="btn btn-primary">Buchhaltungs-Setup speichern</button><p id="accountingSetupStatus" class="small mt-2 mb-0" role="status"></p>
            </form>
        </section>
