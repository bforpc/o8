<?php /* Shared fields; the caller owns the form and authorization. */ ?>
<p class="small text-secondary">Login und E-Mail sind installationsweit eindeutig. Bereits vorhandene Konten bitte zuordnen bzw. einladen, nicht erneut anlegen.</p>
<label class="form-label d-block">Login<input class="form-control" name="login" required maxlength="190" autocomplete="off" pattern="[a-zA-Z0-9][a-zA-Z0-9._@\-]{0,189}"></label>
<label class="form-label d-block">Anzeigename<input class="form-control" name="display_name" required maxlength="190" autocomplete="off"></label>
<label class="form-label d-block">E-Mail-Adresse<input type="email" class="form-control" name="email" required maxlength="254" autocomplete="off"></label>
<label class="form-label d-block">Individuelles Startpasswort<input type="password" class="form-control" name="temporary_password" required autocomplete="new-password"></label>
<p class="small text-secondary">Mindestens 6 Zeichen, maximal 72 Bytes. Sicher mitteilen: Es wird noch keine E-Mail versendet. Das Passwort muss beim ersten Login geändert werden.</p>
