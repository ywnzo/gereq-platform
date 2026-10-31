const panel=document.getElementById('domains-panel');
const csrf=document.querySelector('meta[name="csrf-token"]').content;
function el(tag,text,cls){const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n;}
function button(text,run){const n=el('button',text,'secondary');n.type='button';n.onclick=async()=>{n.disabled=true;try{await run();}catch(e){notice(e.message);}finally{n.disabled=false;}};return n;}
async function api(path,method='GET',body){const r=await fetch(path,{method,credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:body?JSON.stringify(body):undefined});const data=await r.json();if(!r.ok)throw Error(data.error||'Doménová služba není dostupná.');return data;}
function notice(s){panel.querySelector('[data-domain-notice]').textContent=s;}
function field(label,type='text'){const wrap=el('label',label),input=el('input');input.type=type;wrap.append(input);return {wrap,input};}
const heading=el('div',undefined,'section-heading');heading.append(el('h2','Domény a směrování'),button('Obnovit',load));
panel.append(heading,el('p','Pouze lokální simulace. Žádné skutečné DNS záznamy ani certifikáty se nemění. Proxy běží na 127.0.0.1:18154.','detail-note'),el('p','Všechny adresy *.studio.gereq.cz i vlastní testovací domény otevřete pomocí curl --resolve nebo lokálního hosts souboru. Pokud nastavíte vlastní doménu jako primární, přesměruje se na ni i subdoména.','detail-note'));
const form=el('form',undefined,'self-fields'),site=el('select'),kind=el('select');site.id='domain-site';kind.id='domain-kind';
for(const [value,title] of [['subdomain','Naše subdoména'],['custom','Vlastní doména']]){const o=el('option',title);o.value=value;kind.append(o);}
const siteWrap=el('label','Instance');siteWrap.append(site);const kindWrap=el('label','Typ adresy');kindWrap.append(kind);
const name=field('Název před .studio.gereq.cz');name.input.id='domain-hostname';name.input.required=true;name.input.placeholder='name';const submit=el('button','Přiřadit adresu','primary');submit.type='submit';
form.append(siteWrap,kindWrap,name.wrap,submit);panel.append(form);const message=el('p');message.dataset.domainNotice='';message.setAttribute('role','status');panel.append(message);const list=el('div');list.id='domain-list';panel.append(list);
kind.onchange=()=>{name.wrap.firstChild.textContent=kind.value==='custom'?'Vlastní doména':'Název před .studio.gereq.cz';name.input.placeholder=kind.value==='custom'?'www.example.test':'studio';};
site.onchange=()=>{if(kind.value==='subdomain')name.input.value=site.value;};
form.onsubmit=async e=>{e.preventDefault();submit.disabled=true;try{await api('/ui/domains','POST',{site_id:site.value,kind:kind.value,...(kind.value==='custom'?{hostname:name.input.value}:{label:name.input.value})});await load();notice('Adresa byla přiřazena.');}catch(e){notice(e.message);}finally{submit.disabled=false;}};
const states={active:'Aktivní',awaiting_dns:'Čeká na DNS a ověření vlastnictví',issuing_certificate:'DNS ověřeno · čeká na simulovaný certifikát',removed:'Odebraná'};
async function load(){try{const [data,inventory]=await Promise.all([api('/ui/domains'),api('/ui/overview')]);const previous=site.value;site.replaceChildren();for(const x of inventory.instances){const o=el('option',x.name+' · '+x.site_id);o.value=x.site_id;site.append(o);}if(previous)site.value=previous;if(!name.input.value&&kind.value==='subdomain')name.input.value=site.value;list.replaceChildren();
 for(const d of data.domains){if(d.state==='removed')continue;const card=el('article',undefined,'domain-card');card.append(el('h3',d.hostname+(d.primary?' · primární':'')),el('p',d.site_id+' · '+states[d.state]),el('p',d.certificate_simulated?'Certifikát: pouze simulovaný':'Certifikát: nevydaný','detail-note'));
 if(d.kind==='custom'){
 const dns=el('pre',`TXT ${d.verification_name}\n${d.verification_value}\n\nCNAME / simulovaný apex ALIAS: ${d.hostname} → ${d.dns_target}`);card.append(dns,el('p','Test vlastní domény: curl --resolve '+d.hostname+':18154:127.0.0.1 http://'+d.hostname+':18154/','detail-note'));
 const txt=field('Simulovaná hodnota TXT'),target=field('Simulovaný cíl DNS');txt.input.value=d.dns_verified?d.verification_value:'';target.input.value=d.dns_verified?d.dns_target:'';const cert=field('Simulovat úspěšné vydání certifikátu','checkbox');cert.input.checked=d.certificate_simulated;
 card.append(txt.wrap,target.wrap,cert.wrap,button('Vyplnit očekávané DNS',()=>{txt.input.value=d.verification_value;target.input.value=d.dns_target;}),button('Ověřit simulaci',async()=>{await api('/ui/domains/'+d.hostname+'/simulate','POST',{revision:d.revision,txt_value:txt.input.value,dns_target:target.input.value,certificate_ok:cert.input.checked});await load();notice('Simulace ověřena.');}));
 }
 if(d.state==='active'){const link=el('a','Otevřít přes lokální proxy ↗');link.href='http://'+d.hostname+':18154/';link.target='_blank';link.rel='noopener noreferrer';card.append(link);if(!d.primary)card.append(button('Nastavit jako primární',async()=>{await api('/ui/domains/'+d.hostname+'/primary','POST',{revision:d.revision});await load();}));}
 if(d.kind==='custom')card.append(button('Odebrat doménu',async()=>{await api('/ui/domains/'+d.hostname,'DELETE',{revision:d.revision});await load();notice('Doména odebrána; data webu zůstala zachována.');}));list.append(card);
 }if(!list.children.length)list.append(el('p','Zatím nejsou přiřazené žádné adresy.','empty'));notice('');
 }catch(e){notice(e.message);}}
window.addEventListener('hashchange',()=>{if(location.hash==='#domains')load();});if(location.hash==='#domains')load();
