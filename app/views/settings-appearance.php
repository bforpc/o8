<section id="appearanceSettings" class="settings-card" data-settings-panel="appearance" hidden><h2>Erscheinungsbild</h2><p class="text-body-secondary">Die Vorschau reagiert sofort. Deine Auswahl bleibt auf diesem Gerät gespeichert.</p>
                <fieldset><legend class="setting-label">Darstellung</legend><div class="theme-options">
                    <label><input type="radio" name="themeMode" value="light"><span><span class="theme-thumbnail light-thumbnail"></span>Hell</span></label>
                    <label><input type="radio" name="themeMode" value="dark"><span><span class="theme-thumbnail dark-thumbnail"></span>Dunkel</span></label>
                    <label><input type="radio" name="themeMode" value="system"><span><span class="theme-thumbnail system-thumbnail"></span>System</span></label>
                </div></fieldset>
                <div class="color-mode-head"><h3>Eigene Farben</h3><label class="visually-hidden" for="paletteMode">Farbschema bearbeiten</label><select id="paletteMode" class="form-select form-select-sm"><option value="light">Helles Schema</option><option value="dark">Dunkles Schema</option></select></div>
                <div class="color-fields"><label>Akzent<input type="color" id="accentColor" class="form-control form-control-color" value="#326d62"></label><label>Hintergrund<input type="color" id="backgroundColor" class="form-control form-control-color" value="#f3f4f0"></label><label>Oberflächen<input type="color" id="surfaceColor" class="form-control form-control-color" value="#ffffff"></label></div>
                <p class="small text-body-secondary">Helle und dunkle Farben lassen sich getrennt einstellen.</p>
                <label for="fontFamilySelect" class="setting-label">Schriftart</label><select id="fontFamilySelect" class="form-select" aria-describedby="fontFamilyHelp"><option value="system">Systemschrift (Standard)</option><option value="sans">Serifenlos</option><option value="serif">Serifenschrift</option><option value="mono">Nichtproportional (feste Zeichenbreite)</option></select>
                <p id="fontFamilyHelp" class="small mt-2">Nur vorhandene Systemschriften, keine Downloads. Die konkrete Schrift kann je nach Betriebssystem und Browser unterschiedlich aussehen.</p>
                <label for="fontSizeSelect" class="setting-label">Schriftgröße der Oberfläche</label><select id="fontSizeSelect" class="form-select" aria-describedby="fontSizeHelp"><option value="100">100 % · bisherige Größe</option><option value="110">110 %</option><option value="125">125 % · Standard</option><option value="150">150 % · groß</option><option value="175">175 % · sehr groß</option><option value="200">200 % · maximal</option></select>
                <p id="fontSizeHelp" class="small mt-2">Wirkt sofort auf Listen, Ordner, Details und Dialoge. Die Browser-Vergrößerung bleibt zusätzlich nutzbar.</p>
                <div class="typography-preview" aria-label="Schriftvorschau">Vorschau: Rechnung vom 16.09.2026 · 1.234,56 €<br>Ää Öö Üü ß · ABC abc 0123456789</div>
                <label for="densitySelect" class="setting-label">Abstand in der Dokumentliste</label><select id="densitySelect" class="form-select"><option value="comfortable">Entspannt · mehr Platz</option><option value="compact">Kompakt · mehr Dokumente</option></select>
                <button class="btn btn-surface mt-4" id="resetTheme">Darstellung zurücksetzen</button>
                <div class="small text-body-secondary mt-3" id="themeSaveStatus" role="status"></div>
            </section>
