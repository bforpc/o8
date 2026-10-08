import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const auth=fs.readFileSync(new URL('../app/Auth/AuthService.php',import.meta.url),'utf8');
const foundation=fs.readFileSync(new URL('../app/foundation.php',import.meta.url),'utf8');
const account=fs.readFileSync(new URL('../app/views/admin-account.php',import.meta.url),'utf8');
const migrator=fs.readFileSync(new URL('../app/Install/Migrator.php',import.meta.url),'utf8');
const migration=fs.readFileSync(new URL('../database/migrations/014_account_tenant_login_preference.sql',import.meta.url),'utf8');

test('multi-tenant login preference is account-scoped and only selects an active membership',()=>{
    assert.match(auth,/tenant\.login_preference/);
    assert.match(auth,/if \(count\(\$choices\)===1\) return \$this->context/);
    assert.match(auth,/if \(\$preference\['mode'\]==='default'\) foreach \(\$choices as \$choice\)/);
    assert.match(auth,/Der ausgewählte Mandant ist diesem Konto nicht zugeordnet/);
    assert.match(account,/name="tenant_login_mode"/);
    assert.match(account,/name="tenant_id"/);
    assert.match(foundation,/tenant_login_preference_save/);
    assert.match(migration,/FOREIGN KEY \(account_id\) REFERENCES accounts\(id\) ON DELETE CASCADE/);
    assert.match(migrator,/014_account_tenant_login_preference\.sql/);
});
