import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {createHash} from 'node:crypto';

const root=new URL('../',import.meta.url);
const read=path=>fs.readFileSync(new URL(path,root),'utf8');
test('all shipped Feather files have pinned provenance and an unchanged MIT text',()=>{
    const manifest=JSON.parse(read('public/assets/vendor/feather/provenance.json'));
    assert.equal(manifest.version,'4.29.2');
    assert.equal(manifest.commit,'1b002399e8758fb2bccea4d07312dc9e0f43dcb8');
    assert.equal(manifest.files.length,22);
    for(const file of manifest.files){
        assert.equal(createHash('sha256').update(read('public/assets/vendor/feather/'+file.path)).digest('hex'),file.localSha256);
        assert.equal(file.source,`https://raw.githubusercontent.com/feathericons/feather/${manifest.commit}/${file.path}`);
    }
    const notices=read('THIRD-PARTY-NOTICES.md');
    assert.ok(notices.includes(read('public/assets/vendor/feather/LICENSE').trim()));
    assert.ok(read('LICENSE-o8.md').includes('](THIRD-PARTY-NOTICES.md)'));
});
test('inline symbol geometry exactly matches the pinned SVG originals and every use resolves',()=>{
    const manifest=JSON.parse(read('public/assets/vendor/feather/provenance.json'));
    const sprite=read('app/views/icons.php');
    const symbols=new Map([...sprite.matchAll(/<symbol id="([^"]+)"[^>]*>([\s\S]*?)<\/symbol>/g)].map(m=>[m[1],m[2]]));
    assert.equal(symbols.size,22);
    assert.ok(symbols.has('i-tag'));
    for(const file of manifest.files.filter(file=>file.id)){
        const svg=read('public/assets/vendor/feather/'+file.path);
        assert.equal(symbols.get(file.id),svg.match(/<svg[^>]*>([\s\S]*?)<\/svg>/)[1]);
        assert.ok(read('THIRD-PARTY-NOTICES.md').includes('`'+file.id+'`'));
    }
    assert.ok(read('app/views/live-workspace.php').includes("require __DIR__.'/icons.php'"));
    for(const view of fs.readdirSync(new URL('app/views/',root)).filter(name=>name.endsWith('.php'))){
        for(const match of read('app/views/'+view).matchAll(/href="#(i-[^"]+)"/g)) assert.ok(symbols.has(match[1]),match[1]);
    }
});
