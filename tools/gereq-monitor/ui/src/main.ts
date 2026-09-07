import './style.css';

type PulseEvent = {
  type: string;
  ts: string;
  file?: string;
  linesAdded?: number;
  linesRemoved?: number;
  source?: 'ai' | 'user';
  seconds?: number;
  shortstat?: string;
  dirtyFiles?: number;
};

type TimeEntry = {
  id: string;
  start: string;
  end: string;
  durationSec: number;
  project: string;
  color: string;
  language: string;
  description: string;
  files: string[];
  linesAdded: number;
  linesRemoved: number;
  edits: number;
  ai: boolean;
  date: string;
};

type Stats = {
  totals: {
    linesAdded: number;
    linesRemoved: number;
    edits: number;
    aiEdits: number;
    filesTouched: number;
    focusSeconds: number;
    trackedSec: number;
  };
  week: {
    monday: string;
    sunday: string;
    trackedSec: number;
    days: Array<{
      date: string;
      label: string;
      durationSec: number;
      entries: TimeEntry[];
      isToday: boolean;
    }>;
  };
  topFiles: Array<{
    file: string;
    linesAdded: number;
    linesRemoved: number;
    ai: number;
    language?: string;
  }>;
  byHour: Array<{ edits: number; ai: number; lines: number; focusSeconds: number }>;
  byDay: Array<{
    date: string;
    edits: number;
    lines: number;
    focusSeconds: number;
    intensity: number;
  }>;
  byLanguage: Array<{
    language: string;
    edits: number;
    linesAdded: number;
    linesRemoved: number;
    focusSeconds: number;
  }>;
  projects: Array<{
    project: string;
    color: string;
    durationSec: number;
    edits: number;
  }>;
  timeEntries: TimeEntry[];
  timeEntriesToday: TimeEntry[];
  timeline: Array<{ index: number; startMin: number; intensity: number }>;
  lastGit: PulseEvent | null;
  rangeTotals: { focusSeconds: number };
};

type User = { email: string; name: string };
type Locale = 'cs' | 'en';
type View = 'timer' | 'day' | 'week' | 'insights';

const I18N = {
  cs: {
    tagline: 'Automatický time tracking z Cursoru — jako Toggl, přímo z workspace.',
    loginTitle: 'Přihlášení',
    loginHint: 'Dočasný účet — později napojíme na GEREQ API.',
    email: 'E-mail',
    password: 'Heslo',
    enter: 'Vstoupit',
    logout: 'Odhlásit',
    live: 'live · :8772',
    offline: 'offline',
    today: 'Dnes',
    thisWeek: 'Tento týden',
    tracked: 'Tracked',
    edits: 'Edits',
    ai: 'AI',
    files: 'Soubory',
    timer: 'Timer',
    day: 'Den',
    week: 'Týden',
    insights: 'Insights',
    entries: 'Time entries',
    projects: 'Projekty',
    activity: 'Activity timeline',
    emptyEntries: 'Zatím žádné time entries — začni editovat soubory.',
    calendar: 'Heatmapa',
    languages: 'Jazyky',
    hours: 'Hodiny dnes',
    trend: 'Trend řádků',
    topFiles: 'Top soubory',
    git: 'Git',
    diff: 'Diff',
    workingTree: 'working tree',
    clickDiff: 'klik = diff',
    emptyFiles: 'Žádné soubory',
    emptyLang: 'Bez jazyků',
    less: 'méně',
    more: 'více',
    loading: 'Startuji Pulse…',
    loadingDash: 'Načítám…',
    waitingGit: 'Čekám na git…',
  },
  en: {
    tagline: 'Automatic time tracking from Cursor — Toggl-style, from your workspace.',
    loginTitle: 'Sign in',
    loginHint: 'Temporary account — later wired to GEREQ API.',
    email: 'Email',
    password: 'Password',
    enter: 'Enter',
    logout: 'Log out',
    live: 'live · :8772',
    offline: 'offline',
    today: 'Today',
    thisWeek: 'This week',
    tracked: 'Tracked',
    edits: 'Edits',
    ai: 'AI',
    files: 'Files',
    timer: 'Timer',
    day: 'Day',
    week: 'Week',
    insights: 'Insights',
    entries: 'Time entries',
    projects: 'Projects',
    activity: 'Activity timeline',
    emptyEntries: 'No time entries yet — start editing files.',
    calendar: 'Heatmap',
    languages: 'Languages',
    hours: 'Hours today',
    trend: 'Lines trend',
    topFiles: 'Top files',
    git: 'Git',
    diff: 'Diff',
    workingTree: 'working tree',
    clickDiff: 'click = diff',
    emptyFiles: 'No files',
    emptyLang: 'No languages',
    less: 'less',
    more: 'more',
    loading: 'Starting Pulse…',
    loadingDash: 'Loading…',
    waitingGit: 'Waiting for git…',
  },
} as const;

const app = document.querySelector<HTMLDivElement>('#app')!;
let locale: Locale = (localStorage.getItem('pulse_locale') as Locale) || 'cs';
let view: View = (localStorage.getItem('pulse_view') as View) || 'timer';
let stats: Stats | null = null;
let live = false;
let selectedFile: string | null = null;
let diffText = '';
let user: User | null = null;
let loginError = '';
let stream: EventSource | null = null;

const t = () => I18N[locale];
const api = (path: string, init: RequestInit = {}) =>
  fetch(path, { credentials: 'include', ...init });

function fmtDur(sec: number) {
  const s = Math.max(0, Math.round(sec));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const r = s % 60;
  if (h > 0) {
    return `${h}:${String(m).padStart(2, '0')}:${String(r).padStart(2, '0')}`;
  }
  return `${m}:${String(r).padStart(2, '0')}`;
}

function fmtClock(iso: string) {
  return new Date(iso).toLocaleTimeString(locale === 'cs' ? 'cs-CZ' : 'en-GB', {
    hour: '2-digit',
    minute: '2-digit',
  });
}

function fmtDay(isoDate: string) {
  const d = new Date(isoDate + 'T12:00:00');
  return d.toLocaleDateString(locale === 'cs' ? 'cs-CZ' : 'en-GB', {
    weekday: 'long',
    day: 'numeric',
    month: 'short',
  });
}

async function checkAuth() {
  const res = await api('/api/auth/me');
  if (!res.ok) {
    user = null;
    return false;
  }
  const data = await res.json();
  user = data.user || null;
  return !!user;
}

async function doLogin(email: string, password: string) {
  loginError = '';
  const res = await api('/api/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password }),
  });
  const data = await res.json();
  if (!res.ok) {
    loginError = data.error || 'Login failed';
    return false;
  }
  user = data.user;
  return true;
}

async function doLogout() {
  await api('/api/auth/logout', { method: 'POST' });
  user = null;
  stream?.close();
  stream = null;
  live = false;
  stats = null;
  renderLogin();
}

async function fetchStats() {
  const res = await api('/api/stats?days=42');
  if (res.status === 401) {
    await doLogout();
    throw new Error('unauthorized');
  }
  stats = await res.json();
}

async function fetchDiff(file?: string | null) {
  const q = file ? `?file=${encodeURIComponent(file)}` : '';
  const res = await api(`/api/diff${q}`);
  if (res.status === 401) {
    await doLogout();
    throw new Error('unauthorized');
  }
  const data = await res.json();
  diffText = data.diff || data.error || '(empty)';
}

function connectStream() {
  stream?.close();
  const es = new EventSource('/api/stream');
  stream = es;
  es.onopen = () => {
    live = true;
    renderChrome();
  };
  es.onerror = () => {
    live = false;
    renderChrome();
  };
  es.onmessage = () => {
    scheduleStats();
  };
}

let statsTimer: number | null = null;
function scheduleStats() {
  if (statsTimer) {
    return;
  }
  statsTimer = window.setTimeout(async () => {
    statsTimer = null;
    try {
      await fetchStats();
      renderBody();
      renderChrome();
    } catch {
      /* */
    }
  }, 1200);
}

function setLocale(next: Locale) {
  locale = next;
  localStorage.setItem('pulse_locale', next);
  if (user) {
    render();
  } else {
    renderLogin();
  }
}

function setView(next: View) {
  view = next;
  localStorage.setItem('pulse_view', next);
  renderBody();
  document.querySelectorAll('.view-tabs button').forEach((b) => {
    b.classList.toggle('is-on', (b as HTMLElement).dataset.view === view);
  });
}

function renderLogin() {
  const i = t();
  app.innerHTML = `
    <div class="login-shell">
      <form class="login-panel" id="login-form">
        <div class="brand-lockup">
          <img src="/brand/logos/gereq-logo-header.png" alt="GEREQ" />
          <span class="brand-pulse">Pulse</span>
        </div>
        <div class="lang-toggle" style="align-self:flex-end">
          <button type="button" data-loc="cs" class="${locale === 'cs' ? 'is-on' : ''}">CS</button>
          <button type="button" data-loc="en" class="${locale === 'en' ? 'is-on' : ''}">EN</button>
        </div>
        <h1 class="type-title" style="margin:0">${i.loginTitle}</h1>
        <p class="type-small" style="margin:0;color:var(--gereq-muted)">${i.loginHint}</p>
        <label>
          <span class="type-kicker">${i.email}</span>
          <input name="email" type="email" value="test@gereq.cz" required />
        </label>
        <label>
          <span class="type-kicker">${i.password}</span>
          <input name="password" type="password" required />
        </label>
        <p class="login-error type-small ${loginError ? 'is-on' : ''}" id="login-error">${loginError}</p>
        <button type="submit" class="login-btn">${i.enter}</button>
      </form>
    </div>
  `;
  app.querySelectorAll<HTMLButtonElement>('[data-loc]').forEach((b) => {
    b.addEventListener('click', () => setLocale(b.dataset.loc as Locale));
  });
  const form = document.getElementById('login-form') as HTMLFormElement;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    const btn = form.querySelector('button[type="submit"]') as HTMLButtonElement;
    btn.disabled = true;
    const ok = await doLogin(String(fd.get('email')), String(fd.get('password')));
    if (!ok) {
      btn.disabled = false;
      document.getElementById('login-error')!.textContent = loginError;
      document.getElementById('login-error')!.classList.add('is-on');
      return;
    }
    await enterApp();
  });
}

function render() {
  const i = t();
  app.innerHTML = `
    <header class="shell-top">
      <div class="brand-lockup">
        <img src="/brand/logos/gereq-logo-header.png" alt="GEREQ" />
        <span class="brand-pulse">Pulse</span>
      </div>
      <div class="top-right">
        <div class="lang-toggle">
          <button type="button" data-loc="cs" class="${locale === 'cs' ? 'is-on' : ''}">CS</button>
          <button type="button" data-loc="en" class="${locale === 'en' ? 'is-on' : ''}">EN</button>
        </div>
        <div class="chip">${user?.name || ''}</div>
        <button type="button" class="btn-ghost" id="logout">${i.logout}</button>
        <div class="live ${live ? '' : 'is-off'}" id="live">
          <span class="live-dot"></span><span>${live ? i.live : i.offline}</span>
        </div>
      </div>
    </header>

    <section class="hero" style="grid-template-columns:1fr;padding-bottom:0.5rem">
      <div>
        <p class="type-kicker" style="margin:0 0 0.4rem;color:var(--gereq-muted)">TIME TRACKING</p>
        <h1 class="type-display" style="margin:0">Pulse</h1>
        <p class="type-lead lead" style="margin-top:0.5rem">${i.tagline}</p>
      </div>
    </section>

    <div class="totals-row" id="totals"></div>

    <div class="view-tabs" id="views">
      <button data-view="timer" class="${view === 'timer' ? 'is-on' : ''}">${i.timer}</button>
      <button data-view="day" class="${view === 'day' ? 'is-on' : ''}">${i.day}</button>
      <button data-view="week" class="${view === 'week' ? 'is-on' : ''}">${i.week}</button>
      <button data-view="insights" class="${view === 'insights' ? 'is-on' : ''}">${i.insights}</button>
    </div>

    <div id="body"></div>
  `;

  document.getElementById('logout')?.addEventListener('click', () => doLogout());
  app.querySelectorAll<HTMLButtonElement>('[data-loc]').forEach((b) => {
    b.addEventListener('click', () => setLocale(b.dataset.loc as Locale));
  });
  document.getElementById('views')?.addEventListener('click', (e) => {
    const btn = (e.target as HTMLElement).closest('button[data-view]') as HTMLButtonElement | null;
    if (btn) {
      setView(btn.dataset.view as View);
    }
  });

  renderTotals();
  renderBody();
}

function renderChrome() {
  const el = document.getElementById('live');
  if (!el) {
    return;
  }
  const i = t();
  el.className = `live ${live ? '' : 'is-off'}`;
  el.innerHTML = `<span class="live-dot"></span><span>${live ? i.live : i.offline}</span>`;
}

function renderTotals() {
  const el = document.getElementById('totals');
  if (!el || !stats) {
    return;
  }
  const i = t();
  el.innerHTML = `
    <div class="total-card is-signal">
      <span class="type-kicker">${i.today} · ${i.tracked}</span>
      <div class="big">${fmtDur(stats.totals.trackedSec || 0)}</div>
    </div>
    <div class="total-card">
      <span class="type-kicker">${i.thisWeek}</span>
      <div class="big">${fmtDur(stats.week?.trackedSec || 0)}</div>
    </div>
    <div class="total-card">
      <span class="type-kicker">${i.edits} / ${i.ai}</span>
      <div class="big">${stats.totals.edits} <span style="font-size:0.55em;color:var(--gereq-violet)">· ${stats.totals.aiEdits}</span></div>
    </div>
    <div class="total-card">
      <span class="type-kicker">+/− ${i.files.toLowerCase()}</span>
      <div class="big">+${stats.totals.linesAdded}<span style="font-size:0.55em;color:var(--gereq-coral)">/−${stats.totals.linesRemoved}</span></div>
    </div>
  `;
}

function renderBody() {
  const el = document.getElementById('body');
  if (!el || !stats) {
    return;
  }
  renderTotals();
  if (view === 'timer') {
    el.innerHTML = renderTimerHtml();
    bindFileClicks(el);
  } else if (view === 'day') {
    el.innerHTML = renderDayHtml();
  } else if (view === 'week') {
    el.innerHTML = renderWeekHtml();
  } else {
    el.innerHTML = renderInsightsHtml();
    bindFileClicks(el);
    renderTrendSvg();
  }
}

function renderTimerHtml() {
  const i = t();
  const entries = stats!.timeEntries || [];
  const projects = stats!.projects || [];
  const pills = stats!.timeline || [];

  // group by date
  const groups = new Map<string, TimeEntry[]>();
  for (const e of entries) {
    (groups.get(e.date) || groups.set(e.date, []).get(e.date)!).push(e);
  }

  const groupsHtml = [...groups.entries()]
    .map(([date, list]) => {
      const sum = list.reduce((s, e) => s + e.durationSec, 0);
      return `
        <div class="entry-group-h"><span>${fmtDay(date)}</span><span>${fmtDur(sum)}</span></div>
        <ul class="entry-list">
          ${list
            .map(
              (e) => `<li>
                <span class="entry-dot" style="background:${e.color}"></span>
                <div class="entry-main">
                  <div class="title">${escapeHtml(e.description)} ${e.ai ? '<span style="color:var(--gereq-violet)">AI</span>' : ''}</div>
                  <div class="meta">${escapeHtml(e.project)} · ${fmtClock(e.start)}–${fmtClock(e.end)}${e.language ? ` · ${e.language}` : ''} · +${e.linesAdded}/−${e.linesRemoved}</div>
                </div>
                <div class="entry-dur">${fmtDur(e.durationSec)}</div>
              </li>`,
            )
            .join('')}
        </ul>`;
    })
    .join('');

  return `
    <p class="type-kicker" style="color:var(--gereq-muted);margin:0 0 0.5rem">${i.activity}</p>
    <div class="pills" title="15 min activity">
      ${pills.map((p) => `<div class="pill" data-i="${p.intensity}"></div>`).join('')}
    </div>
    <div class="grid">
      <section class="panel">
        <div class="panel-h"><h2 class="type-subhead">${i.entries}</h2></div>
        <div class="panel-b scroll" style="max-height:560px;padding:0">
          ${groupsHtml || `<div class="empty">${i.emptyEntries}</div>`}
        </div>
      </section>
      <section class="panel">
        <div class="panel-h"><h2 class="type-subhead">${i.projects}</h2></div>
        <div class="panel-b">
          <ul class="proj-list">
            ${
              projects.length
                ? projects
                    .map(
                      (p) => `<li>
                        <span class="proj-swatch" style="background:${p.color}"></span>
                        <span>${escapeHtml(p.project)}</span>
                        <strong style="font-variant-numeric:tabular-nums">${fmtDur(p.durationSec)}</strong>
                      </li>`,
                    )
                    .join('')
                : `<div class="empty">${i.emptyEntries}</div>`
            }
          </ul>
        </div>
      </section>
    </div>
  `;
}

function renderDayHtml() {
  const i = t();
  const entries = stats!.timeEntriesToday || [];
  const hours = Array.from({ length: 24 }, (_, h) => h);
  const now = new Date();
  const nowPct = ((now.getHours() * 60 + now.getMinutes()) / (24 * 60)) * 100;

  const blocks = entries
    .map((e) => {
      const s = new Date(e.start);
      const en = new Date(e.end);
      const startMin = s.getHours() * 60 + s.getMinutes();
      const endMin = Math.max(startMin + 8, en.getHours() * 60 + en.getMinutes());
      const top = (startMin / (24 * 60)) * 100;
      const height = Math.max(1.2, ((endMin - startMin) / (24 * 60)) * 100);
      return `<div class="day-block" style="top:${top}%;height:${height}%;background:${e.color}">
        <div class="t">${fmtClock(e.start)}</div>
        <strong>${escapeHtml(e.description)}</strong>
        <div>${escapeHtml(e.project)} · ${fmtDur(e.durationSec)}</div>
      </div>`;
    })
    .join('');

  return `
    <section class="panel">
      <div class="panel-h">
        <h2 class="type-subhead">${i.day} · ${fmtDay(new Date().toISOString().slice(0, 10))}</h2>
        <span class="type-caption">${fmtDur(stats!.totals.trackedSec || 0)}</span>
      </div>
      <div class="panel-b">
        <div class="day-cal" style="height:720px">
          <div class="day-hours">${hours.map((h) => `<span>${String(h).padStart(2, '0')}:00</span>`).join('')}</div>
          <div class="day-track">
            <div class="day-now" style="top:${nowPct}%"></div>
            ${blocks || `<div class="empty">${i.emptyEntries}</div>`}
          </div>
        </div>
      </div>
    </section>
  `;
}

function renderWeekHtml() {
  const i = t();
  const days = stats!.week?.days || [];
  return `
    <section class="panel" style="border:none;background:transparent">
      <div class="panel-h" style="border:1px solid var(--gereq-border);background:var(--gereq-white);margin-bottom:0.75rem">
        <h2 class="type-subhead">${i.week}</h2>
        <span class="type-caption">${stats!.week?.monday} → ${stats!.week?.sunday} · ${fmtDur(stats!.week?.trackedSec || 0)}</span>
      </div>
      <div class="week-grid">
        ${days
          .map((d) => {
            const maxH = 360;
            return `<div class="week-col ${d.isToday ? 'is-today' : ''}">
              <div class="week-col-h">
                <span class="type-caption">${d.label}</span>
                <strong>${d.date.slice(8)}</strong>
                <span class="type-caption">${fmtDur(d.durationSec)}</span>
              </div>
              <div class="week-col-b">
                ${
                  d.entries.length
                    ? d.entries
                        .map((e) => {
                          const h = Math.max(22, Math.min(120, (e.durationSec / 3600) * maxH * 0.25));
                          return `<div class="week-block" style="background:${e.color};min-height:${h}px">
                            <strong>${escapeHtml(e.description)}</strong><br/>
                            ${fmtDur(e.durationSec)}
                          </div>`;
                        })
                        .join('')
                    : `<div class="empty" style="padding:1rem 0">—</div>`
                }
              </div>
            </div>`;
          })
          .join('')}
      </div>
    </section>
  `;
}

function renderInsightsHtml() {
  const i = t();
  const langs = stats!.byLanguage || [];
  const maxLang = Math.max(1, ...langs.map((l) => l.linesAdded + l.linesRemoved + l.focusSeconds / 10));
  const days = stats!.byDay || [];
  const heads =
    locale === 'cs'
      ? ['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne']
      : ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
  const first = days[0] ? new Date(days[0].date + 'T12:00:00') : new Date();
  const pad = (first.getDay() + 6) % 7;
  const cells = [
    ...heads.map((h) => `<div class="cal-head">${h}</div>`),
    ...Array.from({ length: pad }, () => '<div class="cal-cell" style="visibility:hidden"></div>'),
    ...days.map(
      (d) =>
        `<div class="cal-cell" data-i="${d.intensity}" title="${d.date} · ${d.edits} edits · ${fmtDur(d.focusSeconds)}"></div>`,
    ),
  ];

  const maxH = Math.max(1, ...stats!.byHour.map((h) => Math.max(h.lines, h.edits)));

  return `
    <div class="grid">
      <section class="panel">
        <div class="panel-h"><h2 class="type-subhead">${i.calendar}</h2></div>
        <div class="panel-b"><div class="cal">${cells.join('')}</div>
          <div class="cal-legend type-caption"><span>${i.less}</span>
            <i style="background:var(--gereq-wash)"></i><i style="background:#e8f5c4"></i>
            <i style="background:#d7ff45"></i><i style="background:#a8d120"></i>
            <i style="background:var(--gereq-ink)"></i><span>${i.more}</span>
          </div>
        </div>
      </section>
      <div class="stack">
        <section class="panel">
          <div class="panel-h"><h2 class="type-subhead">${i.hours}</h2></div>
          <div class="panel-b"><div class="bars">
            ${stats!.byHour
              .map((h, idx) => {
                const height = Math.round((Math.max(h.lines, h.edits) / maxH) * 100);
                return `<div class="bar" style="height:${Math.max(height, h.edits ? 4 : 1)}%" title="${idx}:00"></div>`;
              })
              .join('')}
          </div></div>
        </section>
        <section class="panel">
          <div class="panel-h"><h2 class="type-subhead">${i.languages}</h2></div>
          <div class="panel-b">
            ${
              langs.length
                ? `<ul class="lang-list">${langs
                    .map((l) => {
                      const score = l.linesAdded + l.linesRemoved + l.focusSeconds / 10;
                      const pct = Math.round((score / maxLang) * 100);
                      return `<li><span class="name">${l.language}</span>
                        <div class="lang-track"><div class="lang-fill" style="width:${pct}%"></div></div>
                        <span class="pct">${pct}%</span></li>`;
                    })
                    .join('')}</ul>`
                : `<div class="empty">${i.emptyLang}</div>`
            }
          </div>
        </section>
      </div>
      <section class="panel span-2">
        <div class="panel-h"><h2 class="type-subhead">${i.trend}</h2></div>
        <div class="panel-b"><svg class="line-chart" id="trend" viewBox="0 0 640 140" preserveAspectRatio="none"></svg></div>
      </section>
      <section class="panel">
        <div class="panel-h"><h2 class="type-subhead">${i.topFiles}</h2><span class="type-caption">${i.clickDiff}</span></div>
        <div class="panel-b scroll">
          ${
            stats!.topFiles.length
              ? `<ul class="files">${stats!.topFiles
                  .map(
                    (f) => `<li data-file="${escapeHtml(f.file)}">
                      <span class="name">${escapeHtml(f.file)}</span>
                      <span class="meta">+${f.linesAdded}/−${f.linesRemoved}</span>
                    </li>`,
                  )
                  .join('')}</ul>`
              : `<div class="empty">${i.emptyFiles}</div>`
          }
        </div>
      </section>
      <section class="panel">
        <div class="panel-h"><h2 class="type-subhead">${i.git}</h2></div>
        <div class="panel-b git-box">${
          stats!.lastGit
            ? `<strong>${escapeHtml(stats!.lastGit.shortstat || 'clean')}</strong>`
            : i.waitingGit
        }</div>
      </section>
    </div>
    <section class="panel diff-panel">
      <div class="panel-h"><h2 class="type-subhead">${i.diff} · ${selectedFile || i.workingTree}</h2></div>
      <pre id="diff">${escapeHtml(diffText || '…')}</pre>
    </section>
  `;
}

function renderTrendSvg() {
  const el = document.getElementById('trend');
  if (!el || !stats?.byDay?.length) {
    return;
  }
  const vals = stats.byDay.map((d) => d.lines || 0);
  const max = Math.max(1, ...vals);
  const w = 640;
  const h = 140;
  const step = w / Math.max(1, vals.length - 1);
  const points = vals
    .map((v, i) => `${i * step},${h - (v / max) * (h - 12) - 6}`)
    .join(' ');
  el.innerHTML = `<polygon fill="rgba(215,255,69,0.35)" points="0,${h} ${points} ${w},${h}" />
    <polyline fill="none" stroke="#0a0b0a" stroke-width="2" points="${points}" />`;
}

function bindFileClicks(root: HTMLElement) {
  root.querySelectorAll<HTMLElement>('li[data-file]').forEach((li) => {
    li.addEventListener('click', async () => {
      selectedFile = li.dataset.file || null;
      await fetchDiff(selectedFile);
      const pre = document.getElementById('diff');
      if (pre) {
        pre.textContent = diffText;
      }
    });
  });
}

function escapeHtml(s: string) {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

async function enterApp() {
  app.innerHTML = `<div class="empty">${t().loadingDash}</div>`;
  await fetchStats();
  await fetchDiff(null);
  render();
  connectStream();
}

async function main() {
  app.innerHTML = `<div class="empty">${t().loading}</div>`;
  try {
    if (!(await checkAuth())) {
      renderLogin();
      return;
    }
    await enterApp();
  } catch (e) {
    app.innerHTML = `<div class="empty">API offline<br/><code>${e}</code></div>`;
  }
}

main();
