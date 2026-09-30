import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const bootstrap=fs.readFileSync(new URL('../app/foundation.php',import.meta.url),'utf8');
const auth=fs.readFileSync(new URL('../app/Auth/AuthService.php',import.meta.url),'utf8');
const german=JSON.parse(fs.readFileSync(new URL('../lang/de.json',import.meta.url),'utf8'));
const english=JSON.parse(fs.readFileSync(new URL('../lang/en.json',import.meta.url),'utf8'));

test('authenticated sessions use a renewable persistent cookie while server-side idle expiry remains authoritative',()=>{
    assert.match(bootstrap,/\$sessionCookieLifetime=604800/);
    assert.match(bootstrap,/session_set_cookie_params\(\['lifetime'=>\$sessionCookieLifetime/);
    assert.match(bootstrap,/if \(\$actor\) setcookie\(session_name\(\),session_id\(\),\['expires'=>time\(\)\+\$sessionCookieLifetime/);
    assert.match(auth,/time\(\)-\$last>\$this->sessionMinutes\(\(int\)\$session\['tenant_id'\]\)\*60/);
    assert.match(german.messages.admin.sessionInfo,/Schließen des Browsers/);
    assert.match(english.messages.admin.sessionInfo,/Closing the browser/);
});
