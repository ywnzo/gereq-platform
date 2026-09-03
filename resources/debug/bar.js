(() => {
  const root = document.getElementById("gereq-debug");
  const raw = document.getElementById("gereq-debug-data");
  if (!root || !raw) return;

  let data;
  try {
    data = JSON.parse(raw.textContent || "{}");
  } catch {
    return;
  }

  const KEY = "gereq.debugbar.v1";
  const tabs = [
    { id: "request", label: "Požadavek" },
    { id: "sql", label: "SQL", count: data.queryCount || 0 },
    { id: "views", label: "Šablony", count: (data.views || []).length },
    { id: "time", label: "Čas" },
    { id: "events", label: "Události", count: (data.events || []).length },
    { id: "session", label: "Session" },
    { id: "user", label: "Uživatel" },
    { id: "dumps", label: "Dumpy", count: (data.dumps || []).length },
    { id: "config", label: "Config" },
  ];
  if ((data.exceptions || []).length) {
    tabs.unshift({ id: "errors", label: "Chyby", count: data.exceptions.length });
  }

  const stored = (() => {
    try {
      return JSON.parse(localStorage.getItem(KEY) || "{}");
    } catch {
      return {};
    }
  })();

  let open = stored.open !== false;
  let tab = stored.tab && tabs.some((t) => t.id === stored.tab) ? stored.tab : tabs[0].id;

  const esc = (value) =>
    String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");

  const statusTone = (status) => {
    if (status >= 500) return "err";
    if (status >= 400) return "warn";
    if (status >= 300) return "info";
    return "ok";
  };

  const highlightSql = (sql) =>
    esc(sql).replace(
      /\b(SELECT|FROM|WHERE|AND|OR|JOIN|LEFT|RIGHT|INNER|OUTER|INSERT|UPDATE|DELETE|INTO|VALUES|SET|LIMIT|OFFSET|ORDER|GROUP|BY|AS|ON|NULL|NOT|IN|IS|DISTINCT|HAVING|UNION|CASE|WHEN|THEN|ELSE|END|COUNT|SUM|AVG|MAX|MIN|LIKE|BETWEEN|EXISTS|CREATE|ALTER|DROP|TABLE|INDEX|PRIMARY|KEY|DEFAULT|TRUE|FALSE)\b/gi,
      "<b>$1</b>"
    );

  const kv = (rows) => {
    const body = rows
      .map(
        ([k, v]) =>
          `<tr><th>${esc(k)}</th><td>${typeof v === "string" && v.startsWith("<") ? v : `<span class="gdb-dump">${esc(typeof v === "object" ? JSON.stringify(v, null, 2) : v)}</span>`}</td></tr>`
      )
      .join("");
    return `<table class="gdb-kv">${body}</table>`;
  };

  const maxQuery = Math.max(1, ...(data.queries || []).map((q) => q.duration || 0));
  const maxView = Math.max(1, ...(data.views || []).map((v) => v.duration || 0));

  const panels = {
    errors: () => {
      if (!(data.exceptions || []).length) return `<div class="gdb-empty">Žádné výjimky.</div>`;
      return data.exceptions
        .map(
          (ex) =>
            `<div class="gdb-exc"><div class="gdb-exc__class">${esc(ex.class)}</div><div class="gdb-exc__msg">${esc(ex.message)}</div><div class="gdb-exc__file">${esc(ex.file)}:${esc(ex.line)}</div></div>`
        )
        .join("");
    },
    request: () => {
      const r = data.route || {};
      const req = data.request || {};
      return kv([
        ["Metoda", data.method],
        ["Cesta", data.path],
        ["URL", req.url],
        ["IP", req.ip],
        ["Route", r.name],
        ["Handler", r.handler],
        ["Middleware", (r.middleware || []).join(", ") || "—"],
        ["Oprávnění", r.permission || "—"],
        ["Parametry", r.params],
        ["Query", req.query],
        ["POST", req.request],
        ["Hlavičky", req.headers],
      ]);
    },
    sql: () => {
      const queries = data.queries || [];
      if (!queries.length) return `<div class="gdb-empty">Žádné SQL v tomto requestu.</div>`;
      const rows = queries
        .map((q) => {
          const slow = q.duration >= 20 ? " is-slow" : "";
          const width = Math.max(4, Math.round((q.duration / maxQuery) * 100));
          return `<tr><td class="gdb-ms${slow}">${esc(q.duration)} ms<div class="gdb-barline"><i style="width:${width}%"></i></div></td><td><div class="gdb-sql">${highlightSql(q.sql)}</div>${q.origin ? `<div class="gdb-origin">${esc(q.origin)}</div>` : ""}${q.params && Object.keys(q.params).length ? `<div class="gdb-origin">${esc(JSON.stringify(q.params))}</div>` : ""}</td></tr>`;
        })
        .join("");
      return `<table class="gdb-table"><thead><tr><th>Čas</th><th>Dotaz · ${esc(data.queryMs)} ms celkem</th></tr></thead><tbody>${rows}</tbody></table>`;
    },
    views: () => {
      const views = data.views || [];
      if (!views.length) return `<div class="gdb-empty">Žádné Twig šablony.</div>`;
      const rows = views
        .map((v) => {
          const width = Math.max(4, Math.round((v.duration / maxView) * 100));
          return `<tr><td>${esc(v.name)}</td><td class="gdb-ms">${esc(v.duration)} ms<div class="gdb-barline"><i style="width:${width}%"></i></div></td></tr>`;
        })
        .join("");
      return `<table class="gdb-table"><thead><tr><th>Šablona</th><th>Čas</th></tr></thead><tbody>${rows}</tbody></table>`;
    },
    time: () => {
      const items = data.timeline || [];
      const rows = [
        ["PHP", data.php],
        ["Prostředí", data.env],
        ["Request", `${data.duration} ms`],
        ["Paměť", data.memory],
        ...items.map((i) => [i.label, `${i.ms} ms`]),
      ];
      return kv(rows);
    },
    events: () => {
      const events = data.events || [];
      if (!events.length) return `<div class="gdb-empty">Žádné eventy.</div>`;
      const rows = events
        .map((e) => `<tr><td>${esc(e.event)}</td><td class="gdb-ms">${esc(e.duration)} ms</td></tr>`)
        .join("");
      return `<table class="gdb-table"><thead><tr><th>Event</th><th>Čas</th></tr></thead><tbody>${rows}</tbody></table>`;
    },
    session: () => {
      const session = data.session || {};
      const keys = Object.keys(session);
      if (!keys.length) return `<div class="gdb-empty">Session je prázdná nebo neexistuje.</div>`;
      return kv(keys.map((k) => [k, session[k]]));
    },
    user: () => {
      const user = data.user || { guest: true };
      if (user.guest) return `<div class="gdb-empty">Host — nepřihlášen.</div>`;
      return kv([
        ["ID", user.id],
        ["Jméno", user.name],
        ["E-mail", user.email],
        ["Role", (user.roles || []).join(", ")],
      ]);
    },
    dumps: () => {
      const dumps = data.dumps || [];
      if (!dumps.length) return `<div class="gdb-empty">Žádné dumpy. Použijte dump() nebo bdump().</div>`;
      return dumps
        .map((d) => {
          const meta = d.file ? `${esc(d.file)}:${esc(d.line)}` : "dump()";
          return `<div class="gdb-card"><div class="gdb-card__meta">${meta}</div>${d.html}</div>`;
        })
        .join("");
    },
    config: () => {
      const cfg = data.config || {};
      return kv(Object.keys(cfg).map((k) => [k, cfg[k]]));
    },
  };

  const tone = statusTone(data.status);
  const tabBtns = tabs
    .map((t) => {
      const n = t.count ? `<span class="gdb-tab__n">${esc(t.count)}</span>` : "";
      return `<button type="button" class="gdb-tab${t.id === tab ? " is-active" : ""}" data-tab="${t.id}">${esc(t.label)}${n}</button>`;
    })
    .join("");

  root.innerHTML = `
    <button type="button" class="gdb-pill" data-act="open" hidden>
      <span class="gdb-pill__brand">GEREQ</span>
      <span class="gdb-pill__meta"><b class="gdb-chip--${tone}">${esc(data.status)}</b> <b>${esc(data.duration)} ms</b> ${esc(data.queryCount)}q</span>
    </button>
    <div class="gdb-dock" data-dock>
      <div class="gdb-bar">
        <span class="gdb-bar__brand">GEREQ</span>
        <span class="gdb-chip gdb-chip--${tone}"><b>${esc(data.status)}</b> ${esc(data.method)}</span>
        <span class="gdb-chip"><b>${esc(data.path)}</b></span>
        <span class="gdb-chip"><b>${esc(data.duration)}</b> ms</span>
        <span class="gdb-chip"><b>${esc(data.memory)}</b></span>
        <span class="gdb-chip"><b>${esc(data.queryCount)}</b> SQL · ${esc(data.queryMs)} ms</span>
        <div class="gdb-bar__tabs">${tabBtns}</div>
        <div class="gdb-bar__actions">
          <kbd>Ctrl+Shift+D</kbd>
          <button type="button" class="gdb-iconbtn" data-act="close" title="Minimalizovat">▾</button>
        </div>
      </div>
      <div class="gdb-panel" data-panel></div>
    </div>
  `;
  root.hidden = false;

  const pill = root.querySelector(".gdb-pill");
  const dock = root.querySelector("[data-dock]");
  const panel = root.querySelector("[data-panel]");

  const persist = () => {
    try {
      localStorage.setItem(KEY, JSON.stringify({ open, tab }));
    } catch {}
  };

  const renderPanel = () => {
    const fn = panels[tab] || panels.request;
    panel.innerHTML = fn();
    root.querySelectorAll(".gdb-tab").forEach((btn) => {
      btn.classList.toggle("is-active", btn.getAttribute("data-tab") === tab);
    });
  };

  const setOpen = (next) => {
    open = next;
    pill.hidden = open;
    dock.hidden = !open;
    document.body.classList.toggle("gdb-pad", open);
    persist();
    if (open) renderPanel();
  };

  root.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-act], .gdb-tab");
    if (!btn || !root.contains(btn)) return;
    if (btn.matches("[data-act=open]")) setOpen(true);
    if (btn.matches("[data-act=close]")) setOpen(false);
    if (btn.matches(".gdb-tab")) {
      tab = btn.getAttribute("data-tab") || "request";
      persist();
      renderPanel();
    }
  });

  window.addEventListener("keydown", (e) => {
    if (!(e.ctrlKey && e.shiftKey && (e.key === "D" || e.key === "d"))) return;
    const tag = (e.target && e.target.tagName) || "";
    if (tag === "INPUT" || tag === "TEXTAREA" || (e.target && e.target.isContentEditable)) return;
    e.preventDefault();
    setOpen(!open);
  });

  setOpen(open);
})();
