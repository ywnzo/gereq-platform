import {hubTabs,instanceTabs,parseTabHash,tabHash} from './navigation.js';
const $ = (id) => document.getElementById(id);
const csrf = document.querySelector('meta[name="csrf-token"]').content;
let inventory;
let billingPolicy;
const packageOptions = new Map();
let packagePlan = null;
const initialTab=parseTabHash(location.hash);
let hubTab=initialTab.tab;
let appliedHash=null;
let detailTab=initialTab.detail;
let view=hubTab==='customers'?'customers':'instances';
let selectedSite=initialTab.site;
const packageSelections=new Map(),releaseSelections=new Map(),billingInputs=new Map();
let selectedCustomer = '';
let pending = null;
let secret = null;
let pollTimer;
let releaseChoices = [];
let updatePlan = null;
const updatePhases = {prepared:"Připraveno", stopped:"Instance zastavena", backed_up:"Záloha ověřena", configuring:"Změna obrazu", checking_modules:"Kontrola modulů", migrating:"Migrace", starting:"Spouštění", complete:"Dokončeno", recovered:"Obnoveno", restoring:"Obnovování dat", restored_storage:"Data obnovena", rollback_requested:"Vrácení verze"};
const actionNames = { 'package-change':'Změna modulů balíčku','package-recover':'Obnova změny balíčku', 'subscription-reconcile':'Kontrola předplatného', update:'Aktualizace', 'update-recover':'Obnova aktualizace', 'update-rollback':'Vrácení verze', inspect: 'Ověření stavu', start: 'Spuštění', stop: 'Zastavení', retry: 'Obnovení vytváření', 'setup-link': 'Nastavení přístupu', create: 'Vytvoření instance', generate: 'Generování návrhu', resume: 'Obnovení instance' };
const statusNames = { updating:'Probíhá aktualizace', running: 'Probíhá', complete: 'Dokončeno', failed: 'Selhalo', interrupted: 'Přerušeno', stopped: 'Zastaveno', needs_customer_setup: 'Připraveno', not_provisioned: 'Nevytvořeno', inspection_required: 'Vyžaduje kontrolu' };
const webCount = (n) => `${n} ${n === 1 ? 'web' : n < 5 ? 'weby' : 'webů'}`;
const phaseNames = { provisioning: 'Připravujeme instanci', generating: 'Generujeme návrh', importing: 'Ukládáme obsah', ready_for_review: 'Návrh je připravený' };
function element(tag, text, className) { const node = document.createElement(tag); if (text !== undefined) node.textContent = text; if (className) node.className = className; return node; }
function badge(text, kind = '') { return element('span', text, `badge ${kind}`); }
function notice(message = '') { $('notice').textContent = message; $('notice').hidden = !message; }
function date(value) { if (!value) return '—'; const parsed = new Date(value); return Number.isNaN(parsed.valueOf()) ? '—' : new Intl.DateTimeFormat('cs-CZ', { dateStyle: 'short', timeStyle: 'short' }).format(parsed); }
function safeURL(value) { const url = new URL(value); if (url.protocol !== 'http:' || !url.hostname.endsWith('.localhost') || !url.port || url.username || url.password) throw new Error('Neplatný místní odkaz.'); return url.href; }
function link(text, value) { const node = element('a', text); node.href = safeURL(value); node.target = '_blank'; node.rel = 'noopener noreferrer'; return node; }
function button(text, action, disabled = false, kind = 'secondary') { const node = element('button', text, kind); node.type = 'button'; node.disabled = disabled; node.addEventListener('click', action); return node; }
function runtime(site) {
  const observation = site.observation;
  if (!observation) return ['Neověřeno', ''];
  if (observation.runtime === 'stopped') return ['Zastavená', 'warning'];
  if (observation.runtime === 'unavailable') return ['Nedostupná', 'bad'];
  return observation.healthy ? ['Běží', 'good'] : ['Běží · chyba kontroly', 'bad'];
}
function setup(site) { const value = site.observation?.setup_required; return value === false ? ['Účet nastaven', 'good'] : value === true ? ['Čeká na nastavení', 'warning'] : ['Nastavení neověřeno', '']; }
async function api(path, method = 'GET', key, body) {
  let response;
  try { response = await fetch(path, { method, ...(body ? {body:JSON.stringify(body)} : {}), credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, ...(key ? { 'Idempotency-Key': key } : {}) } }); }
  catch { throw new Error('Spojení se přerušilo. Akce může běžet na pozadí; obnovte přehled a zkontrolujte historii úloh.'); }
  let data;
  try { data = await response.json(); } catch { throw new Error('Neplatná odpověď služby. Obnovte přehled.'); }
  if (!response.ok) { const error = new Error(data.error || 'Požadavek se nepodařilo dokončit.'); error.status = response.status; throw error; }
  return data;
}
function showTab(tab,site=selectedSite,detail=detailTab){location.hash=tabHash(tab,site,detail);applyTabState();}
function applyTabState(){
 if(appliedHash===location.hash)return;appliedHash=location.hash;const focusId=document.activeElement?.id||'';
 const route=parseTabHash(location.hash);const changed=hubTab!==route.tab;hubTab=route.tab;view=hubTab==='customers'?'customers':'instances';detailTab=route.detail;if(route.site)selectedSite=route.site;if(changed){$('search').value='';secret=null;}render();applyShell();if(changed)window.scrollTo(0,0);if(focusId.startsWith('detail-tab-'))$('detail-tab-'+detailTab)?.focus({preventScroll:true});
}
function applyShell(){
 const directory=hubTab==='instances'||hubTab==='customers';
 document.querySelectorAll('[data-panel]').forEach(panel=>{panel.hidden=panel.dataset.panel==='directory'?!directory:panel.dataset.panel!==hubTab;});
 document.querySelectorAll('[data-hub]').forEach(tab=>{const active=tab.dataset.hub===hubTab;tab.setAttribute('aria-selected',String(active));tab.tabIndex=active?0:-1;});
 $('directory-summary').hidden=!directory;$('instance-filter').parentElement.hidden=hubTab==='customers';$('panel-directory').setAttribute('aria-labelledby','hub-'+hubTab);
 $('hub-title').textContent=hubTabs[hubTab][0];$('hub-description').textContent=hubTabs[hubTab][1];document.title=hubTabs[hubTab][0]+' · Správa SaaS';
}
function selectSite(site){updatePlan=null;packagePlan=null;selectedSite=site;secret=null;showTab('instances',site,'overview');}
function isAttention(site){return site.subscription?.status==='expired'||site.subscription?.error||site.workflow?.retryable||site.record_status==='inspection_required'||site.observation?.runtime==='unavailable'||(site.observation?.runtime==='running'&&!site.observation.healthy);}
function rememberBilling(input,site,key,fallback){const id=site.site_id+':'+key;input.value=billingInputs.has(id)?billingInputs.get(id):fallback;input.addEventListener('input',()=>billingInputs.set(id,input.value));}
function renderList() {
  const list = $('list'); list.replaceChildren();
  const query = $('search').value.trim().toLowerCase();
  if (view === 'customers') {
    for (const customer of inventory.customers.filter((c) => [c.name, c.email, c.ref, ...c.site_ids].join(' ').toLowerCase().includes(query))) {
      const row = button('', () => { selectedCustomer = customer.id; secret = null; render(); }, false, `row ${selectedCustomer === customer.id ? 'selected' : ''}`);
      const info = element('div'); info.append(element('strong', customer.name), element('small', customer.email || customer.ref)); row.append(info, badge(webCount(customer.site_ids.length))); list.append(row);
    }
  } else {
    for (const site of inventory.instances.filter((s) => [s.name, s.site_id, s.email, s.package_name].join(' ').toLowerCase().includes(query)&&($('instance-filter').value==='all'||($('instance-filter').value==='attention'?isAttention(s):s.observation?.runtime===$('instance-filter').value)))) {
      const row = button('', () => selectSite(site.site_id), false, `row ${selectedSite === site.site_id ? 'selected' : ''}`);
      row.setAttribute('aria-pressed', String(selectedSite === site.site_id));
      const info = element('div'); info.append(element('strong', site.name), element('small', site.site_id + '.localhost'), element('small', site.package_name || 'Bez balíčku'));
      const states = element('div', undefined, 'row-status'); states.append(badge(...runtime(site))); if(site.subscription?.status==='expired')states.append(badge(billingStatus(site.subscription),'bad')); if(site.package_change)states.append(badge('Změna modulů','warning'));  row.append(info, states); list.append(row);
    }
  }
  $('directory-count').textContent=`${list.children.length} ${view==='customers'?'zákazníků':'instancí'}`;
  if (!list.children.length) list.append(element('p', 'Nebyly nalezeny žádné záznamy.', 'empty'));
}
function detailPair(grid, label, value) { const group = element('div'); group.append(element('dt', label), element('dd', value || '—')); grid.append(group); }
function renderDetail() {
  const target = $('details'); target.replaceChildren();
  if(!$('list').querySelector('.row.selected')){target.append(element('p','Vyberte záznam ve filtrovaném seznamu.','empty'));return;}
  if (view === 'customers') {
    const customer = inventory.customers.find((c) => c.id === selectedCustomer);
    if (!customer) { target.append(element('p', 'Vyberte zákazníka.', 'empty')); return; }
    target.append(element('span', 'ZÁKAZNÍK', 'eyebrow'), element('h2', customer.name));
    const info = element('dl', undefined, 'detail-grid'); detailPair(info, 'E-mail ze zadání', customer.email); detailPair(info, 'Reference zákazníka', customer.ref); target.append(info);
    target.append(element('p', 'Weby zákazníka', 'section-label'));
    for (const site of inventory.instances.filter((s) => s.customer_id === customer.id)) target.append(button(`${site.site_id}.localhost ↗`, () => selectSite(site.site_id)));
    return;
  }
  const site = inventory.instances.find((s) => s.site_id === selectedSite);
  if (!site) { target.append(element('p', 'Vyberte instanci.', 'empty')); return; }
  target.append(element('span', 'DETAIL INSTANCE', 'eyebrow'), element('h2', site.name), element('div', site.site_id + '.localhost', 'detail-address'));
  const grid = element('dl', undefined, 'detail-grid');
  detailPair(grid, 'E-mail ze zadání', site.email); detailPair(grid, 'Balíček', site.package_name);
  detailPair(grid, 'Runtime', runtime(site)[0]); detailPair(grid, 'Nastavení účtu', setup(site)[0]);
  detailPair(grid, 'Poslední ověření', date(site.observation?.checked_at)); detailPair(grid, 'Verze obrazu', site.release_id);
  detailPair(grid, 'Šablona', site.template_id); detailPair(grid, 'Moduly', site.modules.join(', '));
  
  const summary=element('div',undefined,'instance-summary');summary.append(badge(...runtime(site)),badge(site.package_name||site.package_id));target.append(summary);
  if (site.record_status === 'inspection_required' && !site.package_change) target.append(element('p', 'Registry vyžaduje kontrolu nebo obnovu aktualizace. Provozní ovládání je do vyřešení zablokované.', 'notice'));
  const busy = Boolean(pending || inventory.busy);
  const tabs=element('div',undefined,'detail-tabs');tabs.setAttribute('role','tablist');tabs.setAttribute('aria-label','Detail instance');
  for(const [id,label] of Object.entries(instanceTabs)){const tab=button(label,()=>showTab('instances',site.site_id,id));tab.id='detail-tab-'+id;tab.setAttribute('role','tab');tab.setAttribute('aria-selected',String(detailTab===id));tab.setAttribute('aria-controls','detail-content');tab.tabIndex=detailTab===id?0:-1;tabs.append(tab);}target.append(tabs);
  const content=element('div',undefined,'detail-content');content.id='detail-content';content.setAttribute('role','tabpanel');content.setAttribute('aria-labelledby','detail-tab-'+detailTab);target.append(content);
  if(site.package_change&&detailTab!=='package')renderPackageControls(content,site,busy);
  if(detailTab==='billing'){renderBilling(content,site,busy);return;}
  if(detailTab==='package'){renderPackageControls(content,site,busy);return;}
  if(detailTab==='release'){renderUpdateControls(content,site,busy);return;}
  if(detailTab==='history'){content.classList.add('instance-history');content.tabIndex=0;content.setAttribute('aria-label','Historie úloh instance');renderActionRows(content,inventory.actions.filter(j=>j.site_id===site.site_id));return;}
  if(detailTab==='overview'){
  const overviewLinks=element('div',undefined,'links');for(const [label,url] of [['Administrace ↗',site.admin_url],['Web ↗',site.storefront_url]])if(url)overviewLinks.append(link(label,url));content.append(overviewLinks);

  content.append(element('p', 'Provoz instance', 'section-label'));
  const controls = element('div', undefined, 'controls');
  controls.append(button('Ověřit stav', () => command(site, 'inspect'), busy || !site.can_inspect), button('Spustit', () => command(site, 'start'), busy || !site.can_start), button('Zastavit', () => command(site, 'stop'), busy || !site.can_stop));
  content.append(controls, element('p', 'Poslední ověření: ' + date(site.observation?.checked_at) + '. Stav z registru: ' + (statusNames[site.record_status] || site.record_status) + '.', 'detail-note'),grid);
  if (site.workflow) {
    content.append(element('p', 'Vytvoření webu', 'section-label'));
    const workflow = element('div', undefined, 'workflow');
    workflow.append(element('strong', phaseNames[site.workflow.phase] || site.workflow.phase), element('span', `Stav: ${statusNames[site.workflow.status] || site.workflow.status} · pokus ${site.workflow.attempts}`));
    if (site.workflow.retryable) { workflow.append(element('p', 'Vytváření nebylo dokončeno. Obnovte původní úlohu; již připravená instance se zachová.')); workflow.append(button('Pokračovat ve vytváření', () => command(site, 'retry'), busy || site.observation?.runtime === 'stopped')); }
    content.append(workflow);
  }
  return;}
  // Access is intentionally separated from billing and operational controls.

  content.append(element('p', 'Přístup a obsah', 'section-label'));
  content.append(button('Vytvořit odkaz pro nastavení', () => command(site, 'setup-link'), busy || !site.can_setup));
  if (!site.observation) content.append(element('p', 'Pro vydání odkazu nejprve ověřte stav. Dokončeným účtům se přístup neobnovuje tímto tlačítkem.', 'detail-note'));
  else if (site.observation.setup_required === false) content.append(element('p', 'Účet je už nastavený. Pro obnovu přístupu použijte běžné přihlášení a obnovu hesla.', 'detail-note'));
  else if (!site.email) content.append(element('p', 'Chybí záznam o e-mailu vlastníka. Přístup nelze bezpečně vydat.', 'detail-note'));
  const links = element('div', undefined, 'links');
  for (const [label, value] of [['Administrace ↗', site.admin_url], ['Web ↗', site.storefront_url], ['Náhled ↗', site.preview_url], ['Editor ↗', site.builder_url]]) if (value) links.append(link(label, value));
  content.append(links);
  if (secret?.site === site.site_id) {
    const box = element('div', undefined, 'secret'); box.append(element('strong', 'Jednorázový odkaz je připravený'), element('p', 'Platnost do ' + date(secret.expires) + '. Odkaz se neukládá do přehledu ani do úložiště prohlížeče.'), link('Otevřít nastavení účtu ↗', secret.url)); content.append(box);
  }
}
function renderActionRows(target,jobs){target.replaceChildren();for(const job of jobs.slice(0,100)){const row=element('div',undefined,'job-row');const info=element('div');info.append(element('strong',actionNames[job.operation]||job.operation),element('small',`${job.site_id} · ${date(job.created_at)}`));row.append(info,badge(statusNames[job.status]||job.status,job.status==='complete'?'good':['failed','interrupted'].includes(job.status)?'bad':'warning'));if(job.error)info.append(element('small',job.error));target.append(row);}if(!target.children.length)target.append(element('p','Zatím žádné provozní úlohy.','empty'));}
function renderJobs(){renderActionRows($('jobs'),inventory.actions);}
function render() {
  if (!inventory) return;
  $('customer-count').textContent = inventory.customers.length; $('instance-count').textContent = inventory.instances.length;
  $('running-count').textContent = inventory.instances.filter((s) => s.observation?.runtime === 'running').length;
  $('attention-count').textContent = inventory.instances.filter((s) => s.subscription?.status==='expired' || s.subscription?.error || s.workflow?.retryable || s.record_status === 'inspection_required' || (s.observation?.runtime === 'running' && !s.observation.healthy) || s.observation?.runtime === 'unavailable').length;
  applyShell();
  $('busy').hidden = !pending && !inventory.busy;
  renderList(); renderDetail(); renderJobs();
}
async function refresh(silent = false) {
  try {
    inventory = await api('/ui/overview');
    if (!inventory.instances.some((s) => s.site_id === selectedSite)) selectedSite = inventory.instances[0]?.site_id || '';
    if (!inventory.customers.some((c) => c.id === selectedCustomer)) selectedCustomer = inventory.customers[0]?.id || '';
    if (!silent) notice(); render();
    if(inventory.busy && !pending) {clearTimeout(pollTimer);pollTimer=setTimeout(()=>refresh(true),1500);}
  } catch (error) { if (!(pending && error.status === 409)) notice(error.message); }
}
function confirmAction(title, body, accept = 'Potvrdit') {
  const dialog = $('confirm-dialog'); if (dialog.open) return Promise.resolve(false);
  $('confirm-title').textContent = title; $('confirm-body').textContent = body; $('confirm-accept').textContent = accept; dialog.returnValue = 'cancel'; dialog.showModal();
  return new Promise((resolve) => dialog.addEventListener('close', () => resolve(dialog.returnValue === 'confirm'), { once: true }));
}
async function command(site, action) {
  if (pending) return;
  if (action === 'stop' && !await confirmAction('Zastavit instanci?', `${site.site_id}.localhost bude do opětovného spuštění nedostupný. Data zůstanou zachovaná.`, 'Zastavit instanci')) return;
  if (action === 'retry' && !await confirmAction('Obnovit vytváření webu?', 'Pokračuje původní úloha. Pokud generování ještě nedoběhlo, může znovu použít nakonfigurovaný AI model.', 'Pokračovat')) return;
  if (action === 'setup-link' && !await confirmAction('Vytvořit odkaz pro nastavení?', `Odkaz bude pro ${site.email}. Předchozí odkazy přestanou platit. Heslo si vlastník zvolí sám.`, 'Vytvořit odkaz')) return;
  pending = { site: site.site_id, action }; secret = null; notice(); render();
  try {
    const data = await api(`/ui/instances/${encodeURIComponent(site.site_id)}/${action}`, 'POST', crypto.randomUUID());
    if (action === 'setup-link') {
      secret = { site: site.site_id, url: safeURL(data.setup_url), expires: data.expires_at }; pending = null;
      await refresh(true); notice('Odkaz pro nastavení byl vytvořen.'); return;
    }
    pending = { ...pending, kind: data.kind, id: data.job.id }; notice('Akce byla přijata. Sledujeme její průběh.'); render(); pollTimer = setTimeout(poll, 900);
  } catch (error) { pending = null; await refresh(true); notice(error.message); }
}
async function poll() {
  clearTimeout(pollTimer); if (!pending?.id) return;
  try {
    let job;
    if (pending.kind === 'action') job = await api(`/ui/jobs/${pending.id}`);
    else job = await api(`/ui/workflows/${pending.id}`);
    if (!job) throw new Error('Úlohu se nepodařilo dohledat. Obnovte přehled.');
    if (job.status === 'running') {
 if (pending.action?.startsWith('update')) {const progress=await api(`/ui/instances/${pending.site}/updates`);const node=$('update-progress');if(node && progress.update) node.textContent=updateDescription(progress.update);}
 await refresh(true); pollTimer = setTimeout(poll, 1500); return; }
    pending = null; await refresh(true); notice(job.status === 'complete' ? 'Akce byla dokončena.' : 'Akce nebyla dokončena. Ověřte stav instance a zkontrolujte původní úlohu.');
  } catch (error) { pending = null; notice(error.message); render(); }
}
$('refresh').addEventListener('click', () => refresh()); $('search').addEventListener('input', () => {if(inventory){renderList();renderDetail();}});
document.querySelectorAll('[data-hub]').forEach(tab=>tab.addEventListener('click',()=>showTab(tab.dataset.hub)));
$('instance-filter').addEventListener('change',()=>{renderList();renderDetail();});window.addEventListener('hashchange',applyTabState);
document.addEventListener('keydown',event=>{const tab=event.target.closest('[role=tab]');if(!tab||!['ArrowRight','ArrowLeft','Home','End'].includes(event.key))return;const tabs=[...tab.parentElement.querySelectorAll('[role=tab]')];const index=tabs.indexOf(tab);const next=event.key==='Home'?0:event.key==='End'?tabs.length-1:(index+(event.key==='ArrowRight'?1:tabs.length-1))%tabs.length;event.preventDefault();const focusID=tabs[next].id;tabs[next].click();document.getElementById(focusID)?.focus();});
applyShell();
refresh();

function updateDescription(update) { return `${update.from_release} → ${update.to_release} · ${updatePhases[update.phase] || update.phase} · ${update.backup_ready ? 'Záloha ověřena' : 'Záloha zatím není připravena'}`; }
function renderUpdateControls(target, site, busy) {
 target.append(element('p','Aktualizace platformy','section-label'));
 if(!site.update && pending?.site===site.site_id && pending.action?.startsWith('update')) {const progress=element('div','Připravujeme aktualizaci…','workflow');progress.id='update-progress';target.append(progress);}
 if(site.update) {
  const info=element('div',updateDescription(site.update),'workflow'); info.id='update-progress';target.append(info);
  if(site.update.error) target.append(element('p','Aktualizace nebyla dokončena. Použijte obnovu předchozího stavu.','notice'));
  if(site.update.can_recover) target.append(button('Obnovit předchozí stav',()=>updateCommand(site,'update-recover'),busy));
  if(site.update.can_rollback) target.append(button('Vrátit předchozí verzi',()=>updateCommand(site,'update-rollback'),busy));
 }
 if(site.subscription?.package_snapshot?.features?.instance_updates===false){target.append(element('p','Aktualizace jsou v přiřazeném balíčku vypnuté. Obnova a vrácení verze zůstávají dostupné.','detail-note'));return;}
 if(['inspection_required','updating'].includes(site.record_status)) return;
 const label=element('label','Cílová verze','release-label'); const select=element('select');select.id='release-choice'; select.setAttribute('aria-label','Cílová verze'); select.disabled=busy;
 const placeholder=element('option','Vyberte schválenou verzi');placeholder.value='';select.append(placeholder);
 for(const release of releaseChoices.filter(r=>r.active && r.id!==site.release_id)) {const option=element('option',release.id);option.value=release.id;select.append(option);}
 if(updatePlan?.site===site.site_id) select.value=updatePlan.target_release;else if(releaseSelections.has(site.site_id))select.value=releaseSelections.get(site.site_id);
 select.addEventListener('change',()=>{releaseSelections.set(site.site_id,select.value);updatePlan=null;const plan=$('update-plan-result');if(plan)plan.remove();});
 label.append(select);target.append(label);
 target.append(button('Připravit plán',()=>prepareUpdate(site),busy || !site.can_inspect || site.observation?.runtime==='stopped' || !releaseChoices.some(r=>r.active && r.id!==site.release_id)));
 target.append(element('p','Aktualizace instanci krátce odstaví. Před migrací vytvoří a ověří šifrovanou zálohu.','detail-note'));
 if(updatePlan?.site===site.site_id) {
  const box=element('div',undefined,'workflow');box.id='update-plan-result'; box.append(element('strong',`${updatePlan.current_release} → ${updatePlan.target_release}`),element('small','Ověřený obraz: '+updatePlan.image_id),button('Spustit aktualizaci',()=>updateCommand(site,'update'),busy));target.append(box);
 }
}
async function prepareUpdate(site) {
 const release=$('release-choice').value;if(!release){notice('Vyberte cílovou verzi.');return;}
 pending={site:site.site_id,action:'update-plan'};notice();render();
 try {const plan=await api(`/ui/instances/${site.site_id}/update-plan`,'POST',crypto.randomUUID(),{release_id:release});updatePlan={...plan,site:site.site_id};pending=null;render();notice('Plán připraven. Instance se zatím nezměnila.');}
 catch(error){pending=null;render();notice(error.message);}
}
async function updateCommand(site,action) {
 if(pending) return;
 let body={};let title;let message;
 if(action==='update') {if(updatePlan?.site!==site.site_id)return;body={release_id:updatePlan.target_release,current_release:updatePlan.current_release,expected_image_id:updatePlan.image_id};title='Spustit aktualizaci?';message=`${site.site_id}: ${body.current_release} → ${body.release_id}. Web bude během zálohy a migrace nedostupný.`;}
 else {const risky=action==='update-rollback'||site.update.writes_possible;body={accept_data_loss:risky,...(action==='update-rollback'?{update_id:site.update.id}:{})};title=action==='update-rollback'?'Vrátit předchozí verzi?':'Obnovit předchozí stav?';message=risky?'Obnoví se databáze a soubory ze zálohy před aktualizací. Pozdější změny nebudou v obnoveném webu; zachovají se odděleně pro ruční zpracování. Potvrzením tento dopad přijímáte.':'Obnoví se původní verze a data ze zálohy. Nový web ještě nepřijímal požadavky.';}
 if(!await confirmAction(title,message,'Potvrdit a pokračovat'))return;
 pending={site:site.site_id,action};updatePlan=null;notice();render();
 try {const result=await api(`/ui/instances/${site.site_id}/${action}`,'POST',crypto.randomUUID(),body);pending={...pending,kind:result.kind,id:result.job.id};notice('Akce byla přijata. Sledujeme její průběh.');pollTimer=setTimeout(poll,800);}
 catch(error){pending=null;await refresh(true);notice(error.message);}
}
loadReleases();

let localImages=[];
let registrationBusy=false;
async function loadReleases() {
 try {const data=await api('/ui/releases');releaseChoices=data.releases;if(updatePlan && !releaseChoices.some(r=>r.id===updatePlan.target_release && r.active))updatePlan=null;renderReleases();render();}
 catch(error){$('release-notice').textContent=error.message;}
}
function renderReleases() {
 const target=$('release-list');target.replaceChildren();
 for(const release of releaseChoices) {
  const row=element('div',undefined,'registered-release');const info=element('div');
  info.append(element('strong',release.id),element('p',release.verified?`Platforma ${release.platform_version} · ${Object.entries(release.module_versions).map(([name,version])=>`${name} ${version}`).join(' · ')}`:'Importováno z původního katalogu. Pro ověření a uzamčení obrazu jej zaregistrujte.'),element('code',release.image_id || release.original_image));
  const controls=element('div',undefined,'release-controls');controls.append(badge(release.active?'V nabídce':'Vyřazeno',release.active?'good':''),badge(release.verified?'Ověřeno':'Původní záznam',release.verified?'good':'warning'),button(release.active?'Vyřadit z nabídky':'Zařadit do nabídky',()=>toggleRelease(release),registrationBusy));row.append(info,controls);target.append(row);
 }
 if(!releaseChoices.length)target.append(element('p','Zatím nejsou registrované žádné verze. Vyberte a zaregistrujte první lokální obraz.','empty'));
}
$('load-images').addEventListener('click',async()=>{
 $('load-images').disabled=true;$('release-notice').textContent='Načítáme lokální obrazy…';
 try {const data=await api('/ui/local-images');localImages=data.images;const select=$('local-image');select.replaceChildren();const placeholder=element('option',localImages.length?'Vyberte obraz':'Žádné lokální GEREQ obrazy');placeholder.value='';select.append(placeholder);for(const image of localImages){const option=element('option',`${image.image} · ${image.image_id.slice(7,19)}`);option.value=image.image;select.append(option);}$('register-image').disabled=true;$('release-notice').textContent=`Nalezeno ${localImages.length} lokálních obrazů. Registrace přečte jejich metadata a ověří přesnou identitu.`;}
 catch(error){$('release-notice').textContent=error.message;}
 finally{$('load-images').disabled=false;}
});
$('local-image').addEventListener('change',()=>{$('register-image').disabled=registrationBusy || !$('local-image').value;});
$('register-image').addEventListener('click',async()=>{
 const image=localImages.find(i=>i.image===$('local-image').value);if(!image || registrationBusy)return;
 registrationBusy=true;$('register-image').disabled=true;$('local-image').disabled=true;$('load-images').disabled=true;renderReleases();$('release-notice').textContent='Ověřujeme obraz a registrujeme verzi…';
 try {const release=await api('/ui/releases','POST',crypto.randomUUID(),{image:image.image,expected_image_id:image.image_id});await loadReleases();$('release-notice').textContent=`Verze ${release.id} je registrovaná. Její obraz je uzamčen na ${release.image_id.slice(0,24)}…`;}
 catch(error){$('release-notice').textContent=error.message;}
 finally{registrationBusy=false;$('register-image').disabled=!$('local-image').value;$('local-image').disabled=false;$('load-images').disabled=false;renderReleases();}
});
async function toggleRelease(release) {
 if(registrationBusy)return;registrationBusy=true;renderReleases();
 try {await api(`/ui/releases/${release.id}`,'PATCH',undefined,{active:!release.active});await loadReleases();$('release-notice').textContent=release.active?'Verze je vyřazená z nabídky. Běžící instance a jejich obnova zůstávají zachované.':'Verze je znovu dostupná v nabídce.';}
 catch(error){$('release-notice').textContent=error.message;}
 finally{registrationBusy=false;renderReleases();}
}

function billingStatus(sub) {return {trialing:'Zkušební doba',active:'Aktivní předplatné',expired:'Vypršelo'}[sub.status] || sub.status;}
function renderBilling(target,site,busy) {
 const panel=element('section',undefined,'billing-panel');panel.id='subscription-panel';panel.setAttribute('aria-label','Předplatné a zkušební doba');target.append(panel);target=panel;
 target.append(element('p','Předplatné a zkušební doba','section-label'));
 const sub=site.subscription;
 if(!sub) {
  target.append(element('p','Tato starší instance zatím nemá zapnuté řízení předplatného. Nově vytvořené instance dostanou zkušební dobu automaticky.','detail-note'));
  const label=element('label','Zkušební doba ve dnech','release-label');const input=element('input');input.type='number';input.id='trial-days';input.min='0';input.max='365';input.required=true;rememberBilling(input,site,'trial',site.trial_days??14);label.append(input);target.append(label,button('Zahájit zkušební dobu',()=>billingCommand(site,'trial'),busy));return;
 }
 const box=element('div',undefined,'workflow');box.append(badge(billingStatus(sub),sub.entitled?'good':'bad'),element('p','Konec zkušební doby: '+date(sub.trial_ends_at)));
 if(sub.paid_until)box.append(element('p','Simulovaně uhrazeno do: '+date(sub.paid_until)));
 box.append(element('p',sub.suspended?'Instance je pozastavená. Data zůstávají zachovaná.':!sub.entitled?'Vypršelo. Čeká na pozastavení instance.':sub.resume_owned?'Předplatné je obnoveno. Čeká na spuštění instance.':'Přístup je povolený.'));
 if(sub.error)box.append(element('p','Změnu běhu instance se nepodařilo dokončit. Zkontrolujte její stav a opakujte kontrolu.','notice'));
 target.append(box);
 const controls=element('div',undefined,'controls');const disabled=Boolean(pending)||!billingPolicy?.simulation_enabled;
 controls.append(button('Simulovat úhradu na měsíc',()=>billingCommand(site,'simulate-renewal','monthly'),disabled),button('Simulovat úhradu na rok',()=>billingCommand(site,'simulate-renewal','yearly'),disabled),button('Zkontrolovat předplatné',()=>billingCommand(site,'reconcile'),busy));target.append(controls);
 target.append(element('p','Pouze lokální simulace. Žádné skutečné platby se neprovádějí. Ručně zastavená instance se po úhradě sama nespustí.','detail-note'));
 const label=element('label','Simulovat vypršení za (sekundy)','release-label');const input=element('input');input.id='expiry-delay';input.type='number';input.min='0';input.max='86400';input.required=true;rememberBilling(input,site,'expiry','0');label.append(input);target.append(label,button('Simulovat vypršení',()=>billingCommand(site,'simulate-expiry'),disabled));
}
async function billingCommand(site,action,term) {
 if(pending)return;let body={};
 if(action==='trial') {const input=$('trial-days');if(!input.checkValidity()){notice('Zadejte délku zkušební doby od 0 do 365 dní.');return;}body={trial_days:Number(input.value)};if(!await confirmAction('Zahájit řízení předplatného?',`Po skončení zkušební doby (${body.trial_days} dní) bude tato instance automaticky zastavená. Data zůstanou zachovaná.`,'Zahájit'))return;}
 if(action==='simulate-renewal')body={term};
 if(action==='simulate-expiry'){const input=$('expiry-delay');if(!input.checkValidity()){notice('Zadejte 0 až 86400 sekund.');return;}body={expire_after_seconds:Number(input.value)};if(!await confirmAction('Simulovat vypršení?',`Zkušební doba i simulovaná úhrada skončí za ${body.expire_after_seconds} sekund. Instance se zastaví; data zůstanou zachovaná.`,'Simulovat vypršení'))return;}
 pending={site:site.site_id,action};notice();render();
 try {const data=await api(`/ui/subscriptions/${site.site_id}/${action}`,'POST',crypto.randomUUID(),body);
 if(action==='reconcile'){pending={...pending,kind:data.kind,id:data.job.id};pollTimer=setTimeout(poll,700);notice('Kontrola předplatného byla přijata.');return;}
 pending=null;await refresh(true);notice(action==='simulate-renewal'?'Simulovaná úhrada je uložená. Obnovujeme přístup, pokud instanci zastavilo předplatné.':action==='trial'?'Zkušební doba byla založená.':'Simulované vypršení bylo nastavené.');}
 catch(error){pending=null;await refresh(true);notice(error.message);}
}
async function loadBillingPolicy(){try{billingPolicy=await api('/ui/subscription-policy');$('billing-interval').value=billingPolicy.check_interval_seconds;$('billing-simulation').checked=billingPolicy.simulation_enabled;render();}catch(error){$('billing-policy-notice').textContent=error.message;}}
$('save-billing-policy').addEventListener('click',async()=>{const input=$('billing-interval');if(!input.checkValidity()){$('billing-policy-notice').textContent='Zadejte interval 1 až 3600 sekund.';return;}$('save-billing-policy').disabled=true;try{billingPolicy=await api('/ui/subscription-policy','PATCH',undefined,{check_interval_seconds:Number(input.value),simulation_enabled:$('billing-simulation').checked});$('billing-policy-notice').textContent='Nastavení uložené. Délku zkušební doby určuje vybraný balíček.';render();}catch(error){$('billing-policy-notice').textContent=error.message;}finally{$('save-billing-policy').disabled=false;}});
loadBillingPolicy();

setInterval(()=>{if(document.hidden || pending || $('confirm-dialog').open || ['INPUT','SELECT','TEXTAREA'].includes(document.activeElement?.tagName))return;refresh(true);},5000);

function renderPackageControls(target,site,busy) {
 if(site.package_change){
  target.append(element('p',(busy?'Probíhá změna modulů. Stav: ':'Změna modulů vyžaduje obnovu. Stav: ')+site.package_change.phase,'notice'));
  target.append(button('Obnovit změnu balíčku',async()=>{
   if(!await confirmAction('Obnovit změnu balíčku?','Pracovník bezpečně dokončí potvrzenou změnu, nebo obnoví původní stav ze zálohy.','Obnovit'))return;
   try{const result=await api(`/ui/packages/${site.site_id}/recover`,'POST',crypto.randomUUID(),{});pending={site:site.site_id,action:'package-recover',kind:result.kind,id:result.job.id};render();pollTimer=setTimeout(poll,900);}catch(error){notice(error.message);}
  },busy));return;
 }

 if(!site.subscription) return;
 const panel=element('section',undefined,'billing-panel');panel.id='package-panel';target.append(panel);
 panel.append(element('p','Balíček a oprávnění','section-label'));
 const options=packageOptions.get(site.site_id);
 if(!options){panel.append(button('Načíst nabídku balíčků',async()=>{try{packageOptions.set(site.site_id,await api(`/ui/packages/${site.site_id}/options`));render();}catch(error){notice(error.message);}},busy));return;}
 const current=options.current_package;
 panel.append(element('p',`Generování: ${options.generation_used} / ${current?.generation_limit ?? 'bez omezení'} pokusů celkem. Neúspěšné pokusy se započítávají.`,'detail-note'));
 const label=element('label','Cílový balíček','release-label');const select=element('select');select.id='package-target';select.setAttribute('aria-label','Cílový balíček');label.htmlFor=select.id;
 for(const p of options.packages){const o=element('option',`${p.name} · revize ${p.revision}`);o.value=p.id;select.append(o);}
 if(packagePlan?.site_id===site.site_id)select.value=packagePlan.target.id;else if(packageSelections.has(site.site_id))select.value=packageSelections.get(site.site_id);
 select.addEventListener('change',()=>packageSelections.set(site.site_id,select.value));
 select.disabled=busy;label.append(select);panel.append(label);
 panel.append(button('Připravit změnu',async()=>{try{packagePlan=await api(`/ui/packages/${site.site_id}/preview`,'POST',undefined,{package_id:select.value});render();}catch(error){packagePlan=null;notice(error.message);}},busy||!options.packages.length));
 const plan=packagePlan?.site_id===site.site_id?packagePlan:null;
 if(!plan)return;
 panel.append(element('p',`${plan.source_package} → ${plan.target.name}`,'section-label'));
 panel.append(element('p',`Šablony: ${plan.target.template_ids.join(', ')}. Limit generování: ${plan.target.generation_limit ?? 'bez omezení'}; využito ${plan.generation_used}. Moduly: ${(plan.target.modules?.length?plan.target.modules:site.modules).join(', ')}.`,'detail-note'));
 panel.append(element('p',`Vypnuté funkce: ${Object.entries(plan.target.features||{}).filter(([,enabled])=>!enabled).map(([id])=>({website_generation:'generování webu',draft_import:'import návrhu',instance_updates:'aktualizace instance'}[id]||id)).join(', ')||'žádné'}.`,'detail-note'));
 if(plan.module_change)panel.append(element('p',`Změna modulů: ${plan.source_modules.join(', ')} → ${plan.target_modules.join(', ')}. Instance se krátce zastaví a vytvoří se šifrovaná záloha. Odebrané moduly se pouze vypnou; jejich data zůstanou zachovaná.`,'detail-note'));
 panel.append(element('p','Web, přístupové údaje a termíny předplatného zůstanou zachované. Změna neúčtuje platbu ani poměrnou cenu. Stávající obsah se při odebrání šablony nemaže.','detail-note'));
 panel.append(button('Použít balíček',async()=>{
  if(!await confirmAction('Změnit balíček?',`Nastavit ${plan.target.name} pro ${site.site_id}? ${plan.module_change?'Změna modulů instanci krátce zastaví. Data vypnutých modulů zůstanou zachovaná.':'Změní se oprávnění pro další generování.'}`, 'Použít balíček'))return;
  pending={site:site.site_id,action:'package-change'};render();
  try{const result=await api(`/ui/packages/${site.site_id}/apply`,'POST',crypto.randomUUID(),{plan_id:plan.id});packagePlan=null;packageOptions.delete(site.site_id);
   if(result.kind==='action'){pending={site:site.site_id,action:'package-change',kind:result.kind,id:result.job.id};notice('Změna modulů byla přijata. Sledujeme její průběh.');render();pollTimer=setTimeout(poll,900);return;}
   notice('Balíček byl změněn.');
  }catch(error){notice(error.message);}finally{if(!pending?.id){pending=null;await refresh(true);}}
 },busy));
}

import {mountPackageEditor} from './package-editor.js';
mountPackageEditor({api,element,button,confirmAction,onSaved:()=>{packageOptions.clear();packagePlan=null;refresh(true);}});

import "./customer-view.js";

api('/ui/customer-account-policy').then(policy=>{$('customer-max-sites').value=policy.max_sites_per_account;}).catch(error=>{$('customer-policy-notice').textContent=error.message;});
$('save-customer-policy').addEventListener('click',async()=>{const input=$('customer-max-sites');if(!input.reportValidity())return;try{await api('/ui/customer-account-policy','PATCH',undefined,{max_sites_per_account:Number(input.value)});$('customer-policy-notice').textContent='Limit účtu byl uložen. Existující weby se nemažou.';}catch(error){$('customer-policy-notice').textContent=error.message;}});

import './mail-inbox.js';

import './domains.js';
