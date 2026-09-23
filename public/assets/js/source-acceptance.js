import {TagPicker} from './tag-picker.js';
import {folderTreeMarkup} from './live-inbound-acceptance.js';

for (const section of document.querySelectorAll('[data-source-acceptance]')) {
    const container=section.querySelector('[data-acceptance-tags]');
    const catalogue=JSON.parse(container.dataset.catalogue);
    const selected=new Set(JSON.parse(container.dataset.selected).map(Number));
    const inputs=section.querySelector('[data-acceptance-tag-inputs]');
    const update=names=>{
        inputs.replaceChildren();
        for(const tag of catalogue.filter(tag=>names.includes(tag.name))) {
            const input=document.createElement('input'); input.type='hidden'; input.name='acceptance_tags[]'; input.value=tag.id; inputs.append(input);
        }
    };
    const picker=new TagPicker(container,catalogue.map(tag=>tag.name),catalogue.filter(tag=>selected.has(Number(tag.id))).map(tag=>tag.name),update,'Feste Übernahme-Tags suchen');
    update(picker.values());
    const folders=section.querySelector('[data-acceptance-folders]');
    const chosen=new Set(JSON.parse(folders.dataset.selected).map(Number));
    folders.innerHTML=folderTreeMarkup(JSON.parse(folders.dataset.folders));
    for(const input of folders.querySelectorAll('input')) { input.name='acceptance_folders[]'; input.checked=chosen.has(Number(input.value)); }
}
