import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const view=readFileSync(new URL('../app/views/live-workspace.php',import.meta.url),'utf8');
const script=readFileSync(new URL('../public/assets/js/live-workspace.js',import.meta.url),'utf8');
const backend=readFileSync(new URL('../app/Documents/Documents.php',import.meta.url),'utf8');

test('detailed search uses searchable multi-tag pickers and sends selected tag IDs',()=>{
    assert.match(view,/id="tagFilter" class="search-tag-picker"/);
    assert.match(view,/id="notTagFilter" class="search-tag-picker"/);
    assert.match(script,/new TagPicker\(\$\(id\),tags,selected,\(\)=>\{\},searchTr\(key==='tag'\?'tagInclude':'tagExclude'\),false\)/);
    assert.match(script,/function searchFormInput\(\)/);
    assert.match(script,/idsByName\.get\(name\)/);
    assert.match(script,/input\[key\] = \(searchTagPickers\[id\]\?\.values\(\) \|\| \[\]\).*join\(','\)/);
    assert.match(script,/for\(const searchPicker of Object\.values\(searchTagPickers\)\).*searchPicker\.toggle\(name\)/);
});

test('server applies AND for included tags and excludes every selected tag safely',()=>{
    assert.match(backend,/foreach \(\['tag','notTag'\] as \$key\)/);
    assert.match(backend,/is_array\(\$raw\)/);
    assert.match(backend,/count\(\$tagFilters\[\$key\]\)>1000/);
    assert.match(backend,/array_intersect\(\$tagFilters\['tag'\],\$tagFilters\['notTag'\]\)/);
    assert.match(backend,/foreach \(\$tagFilters\['tag'\] as \$tagId\).*AND EXISTS/s);
    assert.match(backend,/foreach \(\$tagFilters\['notTag'\] as \$tagId\).*AND NOT EXISTS/s);
});
