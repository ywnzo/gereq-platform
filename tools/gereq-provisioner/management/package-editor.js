export function parsePrice(value) {
 const text=value.trim();if(text==='')return null;
 if(!/^\d+(?:[.,]\d{1,2})?$/.test(text))throw new Error('Cena musí být nezáporná a mít nejvýše dvě desetinná místa.');
 const [whole,fraction='']=text.replace(',','.').split('.');
 const minor=BigInt(whole)*100n+BigInt(fraction.padEnd(2,'0'));
 if(minor>BigInt(Number.MAX_SAFE_INTEGER))throw new Error('Cena je příliš vysoká.');
 return Number(minor);
}
export function formatPrice(minor) {return minor==null?'':`${Math.floor(minor/100)}.${String(minor%100).padStart(2,'0')}`;}
export function mountPackageEditor({api,element,button,confirmAction,onSaved}) {
 const root=document.getElementById('package-editor');let catalog=[],templates=[],capabilities,editing=null,dirty=false,saving=false;
 const title=element('div',undefined,'section-heading');title.append(element('h2','Katalog balíčků'));
 const actions=element('div',undefined,'controls');actions.append(button('Obnovit katalog',()=>load()),button('Nový balíček',()=>open(null)));title.append(actions);root.append(title);
 root.append(element('p','Ceny a oprávnění pro nové zákazníky. Existující instance zůstávají na své přiřazené revizi; změnu použijete v detailu instance.','detail-note'));
 const status=element('p','','notice');status.setAttribute('role','status');status.hidden=true;root.append(status);
 const layout=element('div',undefined,'package-layout'),list=element('div',undefined,'package-list'),editor=element('div');layout.append(list,editor);root.append(layout);
 function message(text){status.textContent=text;status.hidden=!text;}
 async function discard(){return !dirty||await confirmAction('Zahodit rozepsané úpravy?','Změny balíčku ještě nejsou uložené.','Zahodit úpravy');}
 async function load(){if(saving||!await discard())return;try{
  const result=await Promise.all([api('/ui/catalog/packages'),api('/ui/catalog/templates'),api('/ui/catalog/package-capabilities')]);
  catalog=result[0].packages;templates=result[1].templates;capabilities=result[2];dirty=false;renderList();editor.replaceChildren(element('p','Vyberte balíček nebo vytvořte nový.','detail-note'));message('');
 }catch(error){message(error.message);}}
 function renderList(){list.replaceChildren();for(const p of catalog){
  const row=button('',()=>open(p));row.className='package-item'+(editing?.id===p.id?' selected':'');row.setAttribute('aria-pressed',String(editing?.id===p.id));row.append(element('strong',p.name),element('small',`${p.id} · revize ${p.revision} · ${p.archived?'archivovaný':p.active?'aktivní':'neaktivní'}`));list.append(row);
 }}
 async function open(p){if(saving||!await discard())return;if(!capabilities){message('Nejdříve načtěte katalog.');return;}
  editing=p?structuredClone(p):{id:'',name:'',description:'',runtime_package:capabilities.supported_runtime,template_ids:[],currency:'CZK',monthly_minor:null,yearly_minor:null,trial_days:14,generation_limit:null,modules:[...capabilities.default_modules],features:{},active:false,archived:false,revision:0};dirty=false;renderList();draw();message('');
 }
 function draw(){editor.replaceChildren();const p=editing;const form=element('form');form.id='package-form';form.noValidate=true;editor.append(form);
  form.append(element('h3',p.revision?`${p.name} · revize ${p.revision}`:'Nový balíček'));
  const grid=element('div',undefined,'package-fields');form.append(grid);const fields={};
  function field(id,text,type,value,options={}){const label=element('label',text,'release-label');const input=element(type==='textarea'?'textarea':'input');input.id=`pkg-${id}`;input.name=id;label.htmlFor=input.id;if(type!=='textarea')input.type=type;input.value=value??'';Object.assign(input,options);label.append(input);grid.append(label);fields[id]=input;return input;}
  field('id','Identifikátor','text',p.id,{required:true,readOnly:p.revision>0,pattern:'[a-z][a-z0-9-]{2,39}',maxLength:40});
  field('name','Název','text',p.name,{required:true,maxLength:100});
  field('description','Popis','textarea',p.description,{maxLength:1000});
  field('currency','Měna (ISO, např. CZK)','text',p.currency,{required:true,pattern:'[A-Z]{3}',maxLength:3});
  field('monthly','Cena měsíčně','text',formatPrice(p.monthly_minor),{inputMode:'decimal',placeholder:'Prázdné = cena neurčena'});
  field('yearly','Cena ročně','text',formatPrice(p.yearly_minor),{inputMode:'decimal',placeholder:'Prázdné = cena neurčena'});
  field('trial','Zkušební doba (dny)','number',p.trial_days,{required:true,min:0,max:365,step:1});
  field('generation','Limit generování celkem','number',p.generation_limit,{min:0,max:1000000,step:1,placeholder:'Prázdné = bez omezení'});
  form.append(element('p','Ceny jsou za celý měsíc / rok v uvedené měně. 0 znamená zdarma, prázdné znamená neurčeno. Limit počítá všechny centrální pokusy včetně neúspěšných; neobnovuje se měsíčně.','detail-note'));
  function group(title){const set=element('fieldset',undefined,'package-checks');set.append(element('legend',title));form.append(set);return set;}
  function check(set,id,label,checked,description){const wrap=element('label',undefined,'package-check');const input=element('input');input.type='checkbox';input.id=`pkg-${id}`;input.checked=checked;const text=element('span');text.append(element('strong',label));if(description)text.append(element('small',description));wrap.append(input,text);set.append(wrap);return input;}
  const modules=group('Povolené moduly'),moduleFields=new Map();modules.classList.add('package-module-list');modules.tabIndex=0;
  const selected=p.modules?.length?p.modules:capabilities.default_modules;
  const available=new Map(capabilities.modules.map(m=>[m.id,m]));
  for(const m of capabilities.modules){const extra=[m.version,m.kind==='integration'?'integrace':'',m.requires?.length?'Vyžaduje: '+m.requires.join(', '):'',m.available_releases?.length?'V ověřeném obrazu':'Zatím není v ověřeném obrazu'].filter(Boolean).join(' · ');moduleFields.set(m.id,check(modules,`module-${m.id}`,m.name,selected.includes(m.id),[m.description,extra].filter(Boolean).join(' · ')));}
  for(const id of selected.filter(id=>!available.has(id)))moduleFields.set(id,check(modules,`module-${id}`,id,true,'Není v aktuálním katalogu backendu. Pro aktivaci balíčku musí být modul znovu dostupný.'));
  for(const [id,input]of moduleFields)input.addEventListener('change',()=>{if(!input.checked)return;const seen=new Set();function include(id){if(seen.has(id))return;seen.add(id);for(const dependency of available.get(id)?.requires??[]){const field=moduleFields.get(dependency);if(field){field.checked=true;include(dependency);}}}include(id);});
  modules.append(element('p','Seznam se načítá z backendu včetně vypnutých modulů a integrací. Závislosti se při výběru doplní. Nasazení navíc ověřuje, že cílový obraz obsahuje celou zvolenou sadu.','detail-note'));
  const features=group('Povolené funkce'),featureFields=new Map();
  for(const f of capabilities.features)featureFields.set(f.id,check(features,`feature-${f.id}`,f.name,p.features?.[f.id]!==false,f.description));
  const templateSet=group('Povolené šablony'),templateFields=new Map();
  for(const t of templates)templateFields.set(t.id,check(templateSet,`template-${t.id}`,t.name,p.template_ids.includes(t.id),`${t.id} · ${t.archived?'archivovaná':t.active?'aktivní':'neaktivní'}`));
  templateSet.append(element('p','Vyberte alespoň jednu šablonu. Aktivní balíček může odkazovat pouze na aktivní šablony.','detail-note'));
  const state=group('Dostupnost');const active=check(state,'active','Aktivní – nabízet novým zákazníkům',p.active);const archived=check(state,'archived','Archivovaný – ponechat jen pro historii',p.archived);
  const controls=element('div',undefined,'controls');const save=button('Uložit balíček',()=>{});save.type='submit';controls.append(save);form.append(controls);
  form.addEventListener('input',()=>{dirty=true;message('Máte neuložené změny.');});
  form.addEventListener('submit',async event=>{event.preventDefault();if(saving)return;
   if(!form.reportValidity())return;
   let next;try{
    const selectedModules=[...moduleFields].filter(([,input])=>input.checked).map(([id])=>id);
    next={...p,id:fields.id.value.trim(),name:fields.name.value.trim(),description:fields.description.value,currency:fields.currency.value.trim(),monthly_minor:parsePrice(fields.monthly.value),yearly_minor:parsePrice(fields.yearly.value),trial_days:Number(fields.trial.value),generation_limit:fields.generation.value===''?null:Number(fields.generation.value),modules:selectedModules,features:Object.fromEntries([...featureFields].map(([id,input])=>[id,input.checked])),template_ids:[...templateFields].filter(([,input])=>input.checked).map(([id])=>id),active:active.checked,archived:archived.checked};
    if(!next.modules.length)throw new Error('Vyberte alespoň jeden modul.');
    if(!next.template_ids.length)throw new Error('Vyberte alespoň jednu šablonu.');
    if(next.active&&next.archived)throw new Error('Archivovaný balíček nemůže být zároveň aktivní.');
    if(next.active&&!next.modules.includes('cms'))throw new Error('Aktivní web vyžaduje Obsah a stránky.');
    if(next.active&&(next.features.website_generation||next.features.draft_import)&&!next.modules.includes('site_inquiries'))throw new Error('Generování formulářů vyžaduje modul Poptávky.');
   }catch(error){message(error.message);return;}
   saving=true;save.disabled=true;
   try{const result=await api(`/ui/catalog/packages/${next.id}`,'PUT',undefined,next);const updated=result.package||result;editing=updated;catalog=catalog.filter(v=>v.id!==updated.id).concat(updated).sort((a,b)=>a.id.localeCompare(b.id));dirty=false;renderList();draw();onSaved();message(`Balíček ${updated.name} uložen, revize ${updated.revision}. Existující instance se nezměnily.`);}
   catch(error){message(error.message);}finally{saving=false;save.disabled=false;}
  });
 }
 load();
}
