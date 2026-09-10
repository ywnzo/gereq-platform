const fs = require('fs');
const path = require('path');

const EXT_LANG = {
  php: 'PHP',
  twig: 'Twig',
  ts: 'TypeScript',
  tsx: 'TypeScript',
  js: 'JavaScript',
  jsx: 'JavaScript',
  mjs: 'JavaScript',
  cjs: 'JavaScript',
  css: 'CSS',
  scss: 'SCSS',
  html: 'HTML',
  json: 'JSON',
  md: 'Markdown',
  yml: 'YAML',
  yaml: 'YAML',
  sql: 'SQL',
  sh: 'Shell',
  bash: 'Shell',
  vue: 'Vue',
  py: 'Python',
  go: 'Go',
  rs: 'Rust',
  java: 'Java',
  xml: 'XML',
  svg: 'SVG',
  env: 'Config',
};

const VSCODE_LANG = {
  php: 'PHP',
  twig: 'Twig',
  typescript: 'TypeScript',
  typescriptreact: 'TypeScript',
  javascript: 'JavaScript',
  javascriptreact: 'JavaScript',
  css: 'CSS',
  scss: 'SCSS',
  html: 'HTML',
  json: 'JSON',
  jsonc: 'JSON',
  markdown: 'Markdown',
  yaml: 'YAML',
  shellscript: 'Shell',
  python: 'Python',
  sql: 'SQL',
  vue: 'Vue',
  plaintext: 'Other',
};

function langFromEvent(ev) {
  if (ev.language && VSCODE_LANG[ev.language]) {
    return VSCODE_LANG[ev.language];
  }
  if (ev.file) {
    const base = ev.file.split('/').pop() || '';
    const ext = base.includes('.') ? base.split('.').pop().toLowerCase() : '';
    if (EXT_LANG[ext]) {
      return EXT_LANG[ext];
    }
  }
  return 'Other';
}

function dayKey(iso) {
  const d = new Date(iso);
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function startOfDay(d) {
  const x = new Date(d);
  x.setHours(0, 0, 0, 0);
  return x;
}

class Store {
  /**
   * @param {string} dataDir
   * @param {{ appendLine: Function }} log
   */
  constructor(dataDir, log) {
    this.dataDir = dataDir;
    this.log = log || { appendLine() {} };
    this.eventsFile = path.join(dataDir, 'events.jsonl');
    this.metaFile = path.join(dataDir, 'meta.json');
    if (!fs.existsSync(dataDir)) {
      fs.mkdirSync(dataDir, { recursive: true });
    }
    if (!fs.existsSync(this.eventsFile)) {
      fs.writeFileSync(this.eventsFile, '');
    }
  }

  append(event) {
    try {
      fs.appendFileSync(this.eventsFile, JSON.stringify(event) + '\n');
    } catch (e) {
      this.log.appendLine(`store append: ${e}`);
    }
  }

  /**
   * @param {{ limit?: number, since?: string, type?: string }} opts
   */
  readEvents(opts = {}) {
    const limit = opts.limit ?? 500;
    const since = opts.since ? Date.parse(opts.since) : 0;
    const type = opts.type || null;
    let lines = [];
    try {
      const raw = fs.readFileSync(this.eventsFile, 'utf8');
      lines = raw.split('\n').filter(Boolean);
    } catch {
      return [];
    }
    const out = [];
    for (let i = lines.length - 1; i >= 0 && out.length < limit; i--) {
      try {
        const ev = JSON.parse(lines[i]);
        if (type && ev.type !== type) {
          continue;
        }
        if (since && Date.parse(ev.ts || 0) < since) {
          continue;
        }
        out.push(ev);
      } catch {
        // skip
      }
    }
    return out.reverse();
  }

  todayStartIso() {
    return startOfDay(new Date()).toISOString();
  }

  /**
   * @param {{ days?: number }} opts
   */
  stats(opts = {}) {
    const days = Math.min(120, Math.max(1, Number(opts.days) || 42));
    const rangeStart = startOfDay(new Date());
    rangeStart.setDate(rangeStart.getDate() - (days - 1));
    const since = rangeStart.toISOString();
    const todayStart = this.todayStartIso();

    const events = this.readEvents({ limit: 200000, since });
    const todayEvents = events.filter((e) => Date.parse(e.ts || 0) >= Date.parse(todayStart));

    const aggregate = (list) => {
      const files = new Map();
      let linesAdded = 0;
      let linesRemoved = 0;
      let edits = 0;
      let saves = 0;
      let aiEdits = 0;
      let userEdits = 0;
      let focusSeconds = 0;
      let creates = 0;
      let deletes = 0;
      let lastGit = null;
      const languages = new Map();

      for (const ev of list) {
        if (ev.file) {
          const f = files.get(ev.file) || {
            file: ev.file,
            edits: 0,
            saves: 0,
            linesAdded: 0,
            linesRemoved: 0,
            ai: 0,
            focusSeconds: 0,
            lastTs: ev.ts,
            language: langFromEvent(ev),
          };
          f.lastTs = ev.ts;
          if (ev.type === 'edit') {
            f.edits++;
            f.linesAdded += ev.linesAdded || 0;
            f.linesRemoved += ev.linesRemoved || 0;
            if (ev.source === 'ai') {
              f.ai++;
            }
            f.language = langFromEvent(ev);
          }
          if (ev.type === 'save') {
            f.saves++;
          }
          if (ev.type === 'focus') {
            f.focusSeconds += ev.seconds || 0;
          }
          files.set(ev.file, f);
        }

        if (ev.type === 'edit') {
          edits++;
          linesAdded += ev.linesAdded || 0;
          linesRemoved += ev.linesRemoved || 0;
          if (ev.source === 'ai') {
            aiEdits++;
          } else {
            userEdits++;
          }
          const lang = langFromEvent(ev);
          const L = languages.get(lang) || {
            language: lang,
            edits: 0,
            linesAdded: 0,
            linesRemoved: 0,
            ai: 0,
            focusSeconds: 0,
          };
          L.edits++;
          L.linesAdded += ev.linesAdded || 0;
          L.linesRemoved += ev.linesRemoved || 0;
          if (ev.source === 'ai') {
            L.ai++;
          }
          languages.set(lang, L);
        }
        if (ev.type === 'save') {
          saves++;
        }
        if (ev.type === 'focus') {
          focusSeconds += ev.seconds || 0;
          const lang = langFromEvent(ev);
          const L = languages.get(lang) || {
            language: lang,
            edits: 0,
            linesAdded: 0,
            linesRemoved: 0,
            ai: 0,
            focusSeconds: 0,
          };
          L.focusSeconds += ev.seconds || 0;
          languages.set(lang, L);
        }
        if (ev.type === 'create') {
          creates++;
        }
        if (ev.type === 'delete') {
          deletes++;
        }
        if (ev.type === 'git') {
          lastGit = ev;
        }
      }

      return {
        files,
        languages,
        totals: {
          linesAdded,
          linesRemoved,
          edits,
          saves,
          aiEdits,
          userEdits,
          focusSeconds,
          creates,
          deletes,
          filesTouched: files.size,
        },
        lastGit,
      };
    };

    const today = aggregate(todayEvents);
    const range = aggregate(events);

    const topFiles = [...today.files.values()]
      .sort((a, b) => (b.linesAdded + b.linesRemoved) - (a.linesAdded + a.linesRemoved))
      .slice(0, 30);

    const byLanguage = [...range.languages.values()]
      .sort((a, b) => (b.linesAdded + b.linesRemoved + b.focusSeconds) - (a.linesAdded + a.linesRemoved + a.focusSeconds))
      .slice(0, 16);

    const byHour = Array.from({ length: 24 }, () => ({
      edits: 0,
      ai: 0,
      lines: 0,
      focusSeconds: 0,
    }));
    for (const ev of todayEvents) {
      const h = new Date(ev.ts).getHours();
      if (ev.type === 'edit') {
        byHour[h].edits++;
        byHour[h].lines += (ev.linesAdded || 0) + (ev.linesRemoved || 0);
        if (ev.source === 'ai') {
          byHour[h].ai++;
        }
      }
      if (ev.type === 'focus') {
        byHour[h].focusSeconds += ev.seconds || 0;
      }
    }

    /** @type {Record<string, { date: string, edits: number, ai: number, lines: number, focusSeconds: number, saves: number }>} */
    const byDayMap = {};
    for (let i = 0; i < days; i++) {
      const d = new Date(rangeStart);
      d.setDate(rangeStart.getDate() + i);
      const key = dayKey(d.toISOString());
      byDayMap[key] = {
        date: key,
        edits: 0,
        ai: 0,
        lines: 0,
        focusSeconds: 0,
        saves: 0,
      };
    }
    for (const ev of events) {
      const key = dayKey(ev.ts);
      if (!byDayMap[key]) {
        continue;
      }
      if (ev.type === 'edit') {
        byDayMap[key].edits++;
        byDayMap[key].lines += (ev.linesAdded || 0) + (ev.linesRemoved || 0);
        if (ev.source === 'ai') {
          byDayMap[key].ai++;
        }
      }
      if (ev.type === 'focus') {
        byDayMap[key].focusSeconds += ev.seconds || 0;
      }
      if (ev.type === 'save') {
        byDayMap[key].saves++;
      }
    }
    const byDay = Object.keys(byDayMap)
      .sort()
      .map((k) => byDayMap[k]);

    // calendar weeks (Sun-Sat) for heatmap
    const calendar = [];
    for (const day of byDay) {
      calendar.push({
        ...day,
        intensity: intensity(day),
      });
    }

    // —— time tracking ——
    const todayKey = dayKey(todayStart);
    const timeEntriesToday = buildTimeEntries(todayEvents);
    const trackedTodaySec = timeEntriesToday.reduce((s, e) => s + e.durationSec, 0);

    const { monday, sunday } = weekBounds(new Date());
    const weekEvents = events.filter((e) => {
      const t = Date.parse(e.ts || 0);
      return t >= monday.getTime() && t <= sunday.getTime() + 86400000 - 1;
    });
    const timeEntriesWeek = buildTimeEntries(weekEvents);
    const trackedWeekSec = timeEntriesWeek.reduce((s, e) => s + e.durationSec, 0);

    /** @type {Record<string, object[]>} */
    const entriesByDate = {};
    for (const e of timeEntriesWeek) {
      (entriesByDate[e.date] ||= []).push(e);
    }

    const weekDays = [];
    for (let i = 0; i < 7; i++) {
      const d = new Date(monday);
      d.setDate(monday.getDate() + i);
      const key = dayKey(d.toISOString());
      const dayEntries = entriesByDate[key] || [];
      weekDays.push({
        date: key,
        label: d.toLocaleDateString('cs-CZ', { weekday: 'short' }),
        durationSec: dayEntries.reduce((s, e) => s + e.durationSec, 0),
        entries: dayEntries,
        isToday: key === todayKey,
      });
    }

    const projectsMap = new Map();
    for (const e of timeEntriesWeek) {
      const p = projectsMap.get(e.project) || {
        project: e.project,
        color: e.color,
        durationSec: 0,
        edits: 0,
        linesAdded: 0,
        linesRemoved: 0,
      };
      p.durationSec += e.durationSec;
      p.edits += e.edits;
      p.linesAdded += e.linesAdded;
      p.linesRemoved += e.linesRemoved;
      projectsMap.set(e.project, p);
    }
    const projects = [...projectsMap.values()].sort((a, b) => b.durationSec - a.durationSec);

    const timeline = buildTimeline(todayEvents, todayKey);

    // all recent entries (last N days) for list view
    const timeEntries = buildTimeEntries(events).slice(-200).reverse();

    return {
      since: todayStart,
      rangeSince: since,
      days,
      generated_at: new Date().toISOString(),
      totals: {
        ...today.totals,
        trackedSec: trackedTodaySec,
      },
      rangeTotals: range.totals,
      week: {
        monday: dayKey(monday.toISOString()),
        sunday: dayKey(sunday.toISOString()),
        trackedSec: trackedWeekSec,
        days: weekDays,
      },
      topFiles,
      byHour,
      byDay: calendar,
      byLanguage,
      projects,
      timeEntries,
      timeEntriesToday: timeEntriesToday.slice().reverse(),
      timeline,
      lastGit: today.lastGit || range.lastGit,
      eventCount: todayEvents.length,
      rangeEventCount: events.length,
    };
  }

  resetToday() {
    const since = Date.parse(this.todayStartIso());
    let lines = [];
    try {
      lines = fs.readFileSync(this.eventsFile, 'utf8').split('\n').filter(Boolean);
    } catch {
      return;
    }
    const keep = [];
    for (const line of lines) {
      try {
        const ev = JSON.parse(line);
        if (Date.parse(ev.ts || 0) < since) {
          keep.push(line);
        }
      } catch {
        keep.push(line);
      }
    }
    fs.writeFileSync(this.eventsFile, keep.length ? keep.join('\n') + '\n' : '');
  }
}

function intensity(day) {
  const score = (day.focusSeconds || 0) / 60 + (day.lines || 0) * 0.15 + (day.edits || 0);
  if (score <= 0) {
    return 0;
  }
  if (score < 15) {
    return 1;
  }
  if (score < 45) {
    return 2;
  }
  if (score < 120) {
    return 3;
  }
  return 4;
}

function projectFromFile(file) {
  if (!file) {
    return 'Other';
  }
  const parts = file.replace(/\\/g, '/').split('/');
  if (parts[0] === 'modules' && parts[1]) {
    return `modules/${parts[1]}`;
  }
  if (parts[0] === 'integrations' && parts[1]) {
    return `integrations/${parts[1]}`;
  }
  if (parts[0] === 'tools' && parts[1]) {
    return `tools/${parts[1]}`;
  }
  if (parts[0] === 'resources') {
    return 'resources';
  }
  if (parts[0] === 'src') {
    return 'src';
  }
  if (parts[0] === 'frontend') {
    return 'frontend';
  }
  return parts[0] || 'Other';
}

const PROJECT_COLORS = [
  '#d7ff45', '#625bff', '#72d6ff', '#ff6b5e', '#0a0b0a',
  '#a8d120', '#8b83ff', '#4bb8e0', '#e85a4f', '#3d7a1f',
];

function colorForProject(name) {
  let h = 0;
  for (let i = 0; i < name.length; i++) {
    h = (h * 31 + name.charCodeAt(i)) >>> 0;
  }
  return PROJECT_COLORS[h % PROJECT_COLORS.length];
}

/**
 * Group activity into time entries (gap > 4 min = new entry).
 * @param {object[]} events
 */
function buildTimeEntries(events) {
  const relevant = events
    .filter((e) => ['edit', 'focus', 'save', 'open'].includes(e.type) && e.file)
    .slice()
    .sort((a, b) => Date.parse(a.ts) - Date.parse(b.ts));

  /** @type {Array<object>} */
  const entries = [];
  /** @type {object | null} */
  let cur = null;
  const GAP_MS = 4 * 60 * 1000;

  for (const ev of relevant) {
    const t = Date.parse(ev.ts);
    const project = projectFromFile(ev.file);
    const lang = langFromEvent(ev);
    const ai = ev.type === 'edit' && ev.source === 'ai';

    if (
      cur
      && cur.project === project
      && t - Date.parse(cur.end) <= GAP_MS
    ) {
      cur.end = ev.ts;
      cur.files.add(ev.file);
      if (ev.type === 'edit') {
        cur.linesAdded += ev.linesAdded || 0;
        cur.linesRemoved += ev.linesRemoved || 0;
        cur.edits += 1;
        if (ai) {
          cur.ai = true;
        }
      }
      if (ev.type === 'focus') {
        cur.focusSeconds += ev.seconds || 0;
      }
      if (lang && lang !== 'Other') {
        cur.language = lang;
      }
      continue;
    }

    if (cur) {
      entries.push(finalizeEntry(cur));
    }
    cur = {
      start: ev.ts,
      end: ev.ts,
      project,
      language: lang,
      files: new Set([ev.file]),
      linesAdded: ev.type === 'edit' ? (ev.linesAdded || 0) : 0,
      linesRemoved: ev.type === 'edit' ? (ev.linesRemoved || 0) : 0,
      edits: ev.type === 'edit' ? 1 : 0,
      focusSeconds: ev.type === 'focus' ? (ev.seconds || 0) : 0,
      ai: !!ai,
      description: fileBase(ev.file),
    };
  }
  if (cur) {
    entries.push(finalizeEntry(cur));
  }
  return entries;
}

function fileBase(file) {
  return (file || '').split('/').pop() || file || '';
}

function finalizeEntry(cur) {
  const startMs = Date.parse(cur.start);
  let endMs = Date.parse(cur.end);
  let durationSec = Math.round((endMs - startMs) / 1000);
  if (cur.focusSeconds > durationSec) {
    durationSec = cur.focusSeconds;
    endMs = startMs + durationSec * 1000;
  }
  // single pulse → at least 1 min
  if (durationSec < 60) {
    durationSec = Math.max(60, cur.focusSeconds || 60);
    endMs = startMs + durationSec * 1000;
  }
  const files = [...cur.files];
  return {
    id: `${cur.start}-${cur.project}`,
    start: cur.start,
    end: new Date(endMs).toISOString(),
    durationSec,
    project: cur.project,
    color: colorForProject(cur.project),
    language: cur.language,
    description: files.length === 1 ? fileBase(files[0]) : `${files.length} files`,
    files,
    linesAdded: cur.linesAdded,
    linesRemoved: cur.linesRemoved,
    edits: cur.edits,
    ai: cur.ai,
    date: dayKey(cur.start),
  };
}

/** 15min activity pills for a day */
function buildTimeline(events, day) {
  const slots = Array.from({ length: 96 }, (_, i) => ({
    index: i,
    startMin: i * 15,
    intensity: 0,
    edits: 0,
    ai: 0,
    lines: 0,
  }));
  for (const ev of events) {
    if (dayKey(ev.ts) !== day) {
      continue;
    }
    if (!['edit', 'focus', 'save'].includes(ev.type)) {
      continue;
    }
    const d = new Date(ev.ts);
    const mins = d.getHours() * 60 + d.getMinutes();
    const idx = Math.min(95, Math.floor(mins / 15));
    const s = slots[idx];
    if (ev.type === 'edit') {
      s.edits++;
      s.lines += (ev.linesAdded || 0) + (ev.linesRemoved || 0);
      if (ev.source === 'ai') {
        s.ai++;
      }
      s.intensity = Math.min(4, s.intensity + 1 + (s.lines > 40 ? 1 : 0));
    }
    if (ev.type === 'focus') {
      s.intensity = Math.min(4, Math.max(s.intensity, Math.ceil((ev.seconds || 0) / 180)));
    }
    if (ev.type === 'save') {
      s.intensity = Math.max(s.intensity, 1);
    }
  }
  return slots;
}

function weekBounds(fromDate = new Date()) {
  const d = startOfDay(fromDate);
  const day = (d.getDay() + 6) % 7; // Mon=0
  const monday = new Date(d);
  monday.setDate(d.getDate() - day);
  const sunday = new Date(monday);
  sunday.setDate(monday.getDate() + 6);
  return { monday, sunday };
}

module.exports = { Store };
