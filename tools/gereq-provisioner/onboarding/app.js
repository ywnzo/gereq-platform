import { createDomainDraft } from './domain.js';
const $ = (id) => document.getElementById(id);
const storageKey = 'gereq-local-onboarding-request';
const csrf = document.querySelector('meta[name="csrf-token"]').content;
let key = '';
let catalog;
let job;
let timer;
let sending = false;
const domainDraft = createDomainDraft();
try { key = localStorage.getItem(storageKey) || ''; } catch {}
const remember = () => { try { localStorage.setItem(storageKey, key); } catch {} };
const newKey = () => { key = crypto.randomUUID(); try { localStorage.removeItem(storageKey); } catch {} };
function notice(message = '') { $('notice').textContent = message; $('notice').hidden = !message; }
function screen(name) {
  for (const id of ['loading', 'create-form', 'progress', 'complete']) $(id).hidden = id !== name;
  for (const [index, id] of ['step-choose', 'step-build', 'step-review'].entries()) {
    const current = name === 'complete' ? 2 : name === 'progress' ? 1 : 0;
    $(id).classList.toggle('current', index === current);
    $(id).classList.toggle('done', index < current);
  }
}
async function api(path, method = 'GET', body) {
  const response = await fetch(path, {
    method, credentials: 'same-origin', cache: 'no-store',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, ...(key ? { 'Idempotency-Key': key } : {}) },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  });
  let data;
  try { data = await response.json(); } catch { throw new Error('Služba neodpověděla správně. Zkuste načíst stav znovu.'); }
  if (!response.ok) { const error = new Error(data.error || 'Požadavek se nepodařilo dokončit.'); error.status = response.status; error.field = data.field; throw error; }
  return data;
}
function templates() {
  const selected = catalog.packages.find((p) => p.id === $('package').value);
  const choices = catalog.templates.filter((t) => selected?.template_ids.includes(t.id));
  choices.sort((a, b) => Number(b.fixed_layout) - Number(a.fixed_layout));
  $('template').replaceChildren(...choices.map((t) => new Option(t.name, t.id)));
  templateInfo();
}
function templateInfo() {
  const selected = catalog.templates.find((t) => t.id === $('template').value);
  $('template-name').textContent = selected?.name || 'Vyberte šablonu';
  $('template-description').textContent = selected?.description || (selected?.fixed_layout
    ? 'Připravený redakční styl. Vlastní obsah a sladěná barevná paleta pro vaše podnikání.'
    : 'Volnější redakční návrh sestavený podle vašeho zadání.');
}
function booking() {
  const enabled = $('goal').value === 'booking';
  $('booking-field').hidden = !enabled;
  $('booking-url').required = enabled;
  $('booking-url').disabled = !enabled;
}
function safeLocalURL(value) {
  const url = new URL(value);
  if (url.protocol !== 'http:' || !url.hostname.endsWith('.localhost') || !url.port || url.username || url.password) throw new Error('Návrh obsahuje neplatný místní odkaz.');
  return url.href;
}
function showJob(next) {
  job = next;
  notice();
  clearTimeout(timer);
  if (job.status === 'complete') {
    $('preview-link').href = safeLocalURL(job.result.preview_url);
    $('builder-link').href = safeLocalURL(job.result.builder_url);
    screen('complete');
    return;
  }
  screen('progress');
  const phases = ['provisioning', 'generating', 'importing'];
  const current = phases.indexOf(job.phase);
  for (const [index, node] of [...document.querySelectorAll('.pipeline li')].entries()) {
    node.classList.toggle('done', index < current);
    node.classList.toggle('active', index === current && job.status === 'running');
  }
  const failed = ['failed', 'interrupted'].includes(job.status);
  const pending = job.status === 'submission_pending';
  $('retry-button').hidden = !failed && !pending;
  $('retry-button').textContent = pending ? 'Obnovit odeslání ↗' : 'Zkusit znovu ↗';
  $('check-button').hidden = true;
  $('progress-status').textContent = failed
    ? 'Tento krok se nepodařilo dokončit. Pokračujte tlačítkem níže — již vytvořená instance i obsah zůstanou zachované.'
    : pending ? 'Odeslání se nepodařilo potvrdit. Obnovte stejný požadavek, nevytvoří se duplicitní web.'
    : 'Pracujeme na návrhu. Generování může trvat několik minut.';
  if (!failed && !pending) timer = setTimeout(poll, 1800);
}
async function poll() {
  clearTimeout(timer);
  try { showJob(await api('/ui/current')); }
  catch (error) {
    if (error.status === 404) { newKey(); screen('create-form'); notice(); return; }
    notice(error.message);
    $('check-button').hidden = false;
    $('progress-status').textContent = 'Spojení se přerušilo. Návrh může dál vznikat na pozadí.';
  }
}
$('business-name').addEventListener('input', () => { const value = domainDraft.fromName($('business-name').value); if (value !== null) { $('domain').value = value; $('domain').setCustomValidity(''); } });
$('domain').addEventListener('input', () => { domainDraft.edit(); $('domain').setCustomValidity(''); });
$('domain-reset').addEventListener('click', () => { $('domain').value = domainDraft.reset($('business-name').value); $('domain').setCustomValidity(''); $('domain').focus(); });
$('package').addEventListener('change', templates);
$('template').addEventListener('change', templateInfo);
$('goal').addEventListener('change', booking);
$('create-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  if (sending || !$('create-form').reportValidity()) return;
  const fields = Object.fromEntries(new FormData(event.currentTarget));
  fields.facts = fields.facts.split('\n').map((value) => value.trim()).filter(Boolean);
  fields.booking_url = fields.goal === 'booking' ? fields.booking_url : '';
  if (fields.facts.length > 12 || fields.facts.some((fact) => fact.length > 400)) { notice('Zadejte nejvýše 12 faktů, každý do 400 znaků.'); return; }
  if (fields.goal === 'booking' && !fields.booking_url.startsWith('https://')) { notice('Rezervační odkaz musí začínat https://.'); $('booking-url').focus(); return; }
  if (!key) newKey();
  remember();
  sending = true;
  $('create-button').disabled = true;
  screen('progress');
  $('progress-status').textContent = 'Odesíláme vaše zadání…';
  notice();
  try { showJob(await api('/ui/create', 'POST', fields)); }
  catch (error) {
    if (error.field === 'domain') { screen('create-form'); newKey(); notice(error.message); $('domain').setCustomValidity(error.message); $('domain').reportValidity(); }
    else if (error.status === 400) { screen('create-form'); newKey(); notice(error.message); }
    else { showJob({ status: 'submission_pending', phase: 'provisioning' }); $('check-button').hidden = false; notice(error.message); }
  } finally { sending = false; $('create-button').disabled = false; }
});
$('retry-button').addEventListener('click', async () => {
  $('retry-button').disabled = true;
  try { showJob(await api(job?.status === 'submission_pending' ? '/ui/recover' : '/ui/retry', 'POST')); }
  catch (error) {
    if (error.status === 404) { newKey(); screen('create-form'); }
    notice(error.message);
  }
  finally { $('retry-button').disabled = false; }
});
$('check-button').addEventListener('click', poll);
$('setup-button').addEventListener('click', async () => {
  $('setup-button').disabled = true;
  try {
    const access = await api('/ui/setup-link', 'POST');
    // The bearer link lives only in this response, never browser storage.
    location.assign(safeLocalURL(access.setup_url));
  } catch (error) { notice(error.message); }
  finally { $('setup-button').disabled = false; }
});
$('new-button').addEventListener('click', () => { clearTimeout(timer); job = null; newKey(); $('create-form').reset(); domainDraft.reset(''); templates(); booking(); notice(); screen('create-form'); window.scrollTo({top:0,behavior:'instant'}); });
async function start() {
  try {
    catalog = await api('/ui/catalog');
    if (!catalog.packages.length) throw new Error('Zatím není k dispozici balíček s aktivní šablonou.');
    $('package').replaceChildren(...catalog.packages.map((p) => new Option(p.name, p.id)));
    templates(); booking();
    if (key) { screen('progress'); await poll(); }
    else screen('create-form');
  } catch (error) { $('loading').hidden = true; notice(error.message + ' Obnovte stránku.'); }
}
start();
