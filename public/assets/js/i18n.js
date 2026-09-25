const node = document.getElementById('o8-language-messages');
let bundle = {messages:{},de:{},language:'de'};
try { bundle = node ? JSON.parse(node.textContent || '{}') : bundle; } catch { bundle = {messages:{},de:{},language:'de'}; }

function lookup(messages,key) {
  return key.split('.').reduce((value, part) => value && typeof value === 'object' ? value[part] : undefined, messages);
}

window.o8Translate = (key, values = {}) => {
  let template = lookup(bundle.messages,key) ?? lookup(bundle.de,key);
  if (template && typeof template === 'object' && typeof template.one === 'string' && typeof template.other === 'string') template=Number(values.count)===1?template.one:template.other;
  if (typeof template !== 'string') return bundle.language === 'de' ? 'Text nicht verfügbar' : 'Text not available';
  return template.replace(/\{([a-zA-Z][a-zA-Z0-9_]*)\}/g, (match, name) => Object.hasOwn(values, name) ? String(values[name]) : match);
};

function findKey(messages,source,prefix=[]) {
  for (const [key,value] of Object.entries(messages || {})) {
    if (typeof value === 'string' && value === source) return [...prefix,key].join('.');
    if (value && typeof value === 'object') { const found=findKey(value,source,[...prefix,key]); if (found) return found; }
  }
  return null;
}

function findTemplate(messages,source,prefix=[]) {
  let best=null;
  for (const [key,value] of Object.entries(messages || {})) {
    const path=[...prefix,key];
    const templates=typeof value==='string'?[value]:value && typeof value==='object' && typeof value.one==='string' && typeof value.other==='string'?[value.one,value.other]:[];
    for (const template of templates) {
      let regex='^', cursor=0; const seen=new Set();
      for (const match of template.matchAll(/\{([a-zA-Z][a-zA-Z0-9_]*)\}/g)) {
        regex+=template.slice(cursor,match.index).replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
        const name=match[1]; regex+=seen.has(name)?'\\k<'+name+'>':'(?<'+name+'>.*?)'; seen.add(name); cursor=match.index+match[0].length;
      }
      regex+=template.slice(cursor).replace(/[.*+?^${}()|[\]\\]/g,'\\$&')+'$';
      const found=source.match(new RegExp(regex,'u'));
      if(found) {
        const score=template.replace(/\{[a-zA-Z][a-zA-Z0-9_]*\}/g,'').length;
        if(!best||score>best.score) best={path,values:Object.fromEntries([...seen].map(name=>[name,found.groups[name]])),score};
      }
    }
    if(value && typeof value==='object') { const found=findTemplate(value,source,path); if(found&&(!best||found.score>best.score))best=found; }
  }
  return best;
}

window.o8TranslateSource = source => {
  const key=findKey(bundle.de,source);
  if(key) return window.o8Translate(key);
  const match=findTemplate(bundle.de,source);
  if(!match) return source;
  const path=match.path.join('.'); let template=lookup(bundle.messages,path) ?? lookup(bundle.de,path);
  if(template && typeof template==='object') template=Number(match.values.count)===1?template.one:template.other;
  if(typeof template!=='string') return source;
  return template.replace(/\{([a-zA-Z][a-zA-Z0-9_]*)\}/g,(_,name)=>Object.hasOwn(match.values,name)?String(match.values[name]):'');
};
