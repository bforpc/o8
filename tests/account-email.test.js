import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const view=fs.readFileSync(new URL('../app/views/admin-account.php',import.meta.url),'utf8');
const usersView=fs.readFileSync(new URL('../app/views/admin-users.php',import.meta.url),'utf8');
const auth=fs.readFileSync(new URL('../app/Auth/AuthService.php',import.meta.url),'utf8');
const administration=fs.readFileSync(new URL('../app/Admin/Administration.php',import.meta.url),'utf8');
const foundation=fs.readFileSync(new URL('../app/foundation.php',import.meta.url),'utf8');

test('My Account exposes a validated shared-account email change action',()=>{
    assert.match(view,/postFields\('account_email_save','account'\)/);
    assert.match(view,/type="email" name="email"/);
    assert.match(view,/o8-info-group--soft mb-3[^>]*>.*name="email"/s);
    assert.doesNotMatch(view,/<dt[^>]*>.*admin\.email.*<\/dd>/s);
    assert.match(auth,/public function saveAccountEmail\(Actor \$actor, string \$email\)/);
    assert.match(auth,/email_verified_at=NULL/);
    assert.match(auth,/DELETE FROM account_identifiers WHERE identifier=\? AND account_id=\?/);
    assert.match(auth,/INSERT INTO account_identifiers \(identifier,account_id\) VALUES/);
    assert.match(auth,/AccountEmailException\('admin\.emailAlreadyUsed'\)/);
    assert.match(foundation,/\$auth->saveAccountEmail\(\$actor,field\('email'\)\)/);
    assert.match(foundation,/catch \(AccountEmailException \$exception\) \{ \$error=tr\(\$exception->translationKey\); \}/);
});

test('tenant admins can update a member shared-account email with explicit global confirmation',()=>{
    assert.match(usersView,/postFields\('admin_member_email_save','users'\)/);
    assert.match(usersView,/name="confirm_global_email" value="yes" required/);
    assert.match(usersView,/name="version" value="<\?= h\(\$user\['auth_version'\]\) \?>"/);
    assert.match(auth,/public function saveMemberAccountEmail\(Actor \$actor, int \$membershipId, int \$membershipVersion, string \$email, bool \$confirmedGlobalChange\)/);
    assert.match(auth,/if \(!\$confirmedGlobalChange\) throw new AccountEmailException\('admin\.emailAdminConfirmationRequired'\)/);
    assert.match(auth,/SELECT account_id,auth_version FROM users WHERE tenant_id=\? AND id=\? FOR UPDATE/);
    assert.match(foundation,/\$auth->saveMemberAccountEmail\(\$actor,\(int\)field\('id'\),\(int\)field\('version'\),field\('email'\),field\('confirm_global_email'\)==='yes'\)/);
});

test('tenant admins can set another member temporary password with confirmation and forced change',()=>{
    assert.match(usersView,/postFields\('user_password_reset','users'\)/);
    assert.match(usersView,/name="confirm_global_password" value="yes" required/);
    assert.match(usersView,/name="new_password" minlength="6" maxlength="72"/);
    assert.match(usersView,/if \(\(int\)\$user\['id'\]!==\$actor->id\(\)\)/);
    assert.match(administration,/public function resetPassword\(Actor \$actor, int \$id, int \$version, string \$password, bool \$confirmedGlobalChange=false\)/);
    assert.match(administration,/must_change_password=1,auth_version=auth_version\+1/);
    assert.match(administration,/user\.password_reset/);
    assert.match(foundation,/\$administration->resetPassword\(\$actor,\(int\)field\('id'\),\(int\)field\('version'\),field\('new_password'\),field\('confirm_global_password'\)==='yes'\)/);
});
