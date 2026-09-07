(function(){const t=document.createElement("link").relList;if(t&&t.supports&&t.supports("modulepreload"))return;for(const a of document.querySelectorAll('link[rel="modulepreload"]'))o(a);new MutationObserver(a=>{for(const c of a)if(c.type==="childList")for(const n of c.addedNodes)n.tagName==="LINK"&&n.rel==="modulepreload"&&o(n)}).observe(document,{childList:!0,subtree:!0});function i(a){const c={};return a.integrity&&(c.integrity=a.integrity),a.referrerPolicy&&(c.referrerPolicy=a.referrerPolicy),a.crossOrigin==="use-credentials"?c.credentials="include":a.crossOrigin==="anonymous"?c.credentials="omit":c.credentials="same-origin",c}function o(a){if(a.ep)return;a.ep=!0;const c=i(a);fetch(a.href,c)}})();const z={cs:{tagline:"Automatický time tracking z Cursoru — jako Toggl, přímo z workspace.",loginTitle:"Přihlášení",loginHint:"Dočasný účet — později napojíme na GEREQ API.",email:"E-mail",password:"Heslo",enter:"Vstoupit",logout:"Odhlásit",live:"live · :8772",offline:"offline",today:"Dnes",thisWeek:"Tento týden",tracked:"Tracked",edits:"Edits",ai:"AI",files:"Soubory",timer:"Timer",day:"Den",week:"Týden",insights:"Insights",entries:"Time entries",projects:"Projekty",activity:"Activity timeline",emptyEntries:"Zatím žádné time entries — začni editovat soubory.",calendar:"Heatmapa",languages:"Jazyky",hours:"Hodiny dnes",trend:"Trend řádků",topFiles:"Top soubory",git:"Git",diff:"Diff",workingTree:"working tree",clickDiff:"klik = diff",emptyFiles:"Žádné soubory",emptyLang:"Bez jazyků",less:"méně",more:"více",loading:"Startuji Pulse…",loadingDash:"Načítám…",waitingGit:"Čekám na git…"},en:{tagline:"Automatic time tracking from Cursor — Toggl-style, from your workspace.",loginTitle:"Sign in",loginHint:"Temporary account — later wired to GEREQ API.",email:"Email",password:"Password",enter:"Enter",logout:"Log out",live:"live · :8772",offline:"offline",today:"Today",thisWeek:"This week",tracked:"Tracked",edits:"Edits",ai:"AI",files:"Files",timer:"Timer",day:"Day",week:"Week",insights:"Insights",entries:"Time entries",projects:"Projects",activity:"Activity timeline",emptyEntries:"No time entries yet — start editing files.",calendar:"Heatmap",languages:"Languages",hours:"Hours today",trend:"Lines trend",topFiles:"Top files",git:"Git",diff:"Diff",workingTree:"working tree",clickDiff:"click = diff",emptyFiles:"No files",emptyLang:"No languages",less:"less",more:"more",loading:"Starting Pulse…",loadingDash:"Loading…",waitingGit:"Waiting for git…"}},h=document.querySelector("#app");let v=localStorage.getItem("pulse_locale")||"cs",g=localStorage.getItem("pulse_view")||"timer",l=null,$=!1,E=null,j="",f=null,w="",S=null;const u=()=>z[v],k=(e,t={})=>fetch(e,{credentials:"include",...t});function d(e){const t=Math.max(0,Math.round(e)),i=Math.floor(t/3600),o=Math.floor(t%3600/60),a=t%60;return i>0?`${i}:${String(o).padStart(2,"0")}:${String(a).padStart(2,"0")}`:`${o}:${String(a).padStart(2,"0")}`}function L(e){return new Date(e).toLocaleTimeString(v==="cs"?"cs-CZ":"en-GB",{hour:"2-digit",minute:"2-digit"})}function x(e){return new Date(e+"T12:00:00").toLocaleDateString(v==="cs"?"cs-CZ":"en-GB",{weekday:"long",day:"numeric",month:"short"})}async function R(){const e=await k("/api/auth/me");return e.ok?(f=(await e.json()).user||null,!!f):(f=null,!1)}async function O(e,t){w="";const i=await k("/api/auth/login",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({email:e,password:t})}),o=await i.json();return i.ok?(f=o.user,!0):(w=o.error||"Login failed",!1)}async function H(){await k("/api/auth/logout",{method:"POST"}),f=null,S?.close(),S=null,$=!1,l=null,D()}async function q(){const e=await k("/api/stats?days=42");if(e.status===401)throw await H(),new Error("unauthorized");l=await e.json()}async function P(e){const t=e?`?file=${encodeURIComponent(e)}`:"",i=await k(`/api/diff${t}`);if(i.status===401)throw await H(),new Error("unauthorized");const o=await i.json();j=o.diff||o.error||"(empty)"}function W(){S?.close();const e=new EventSource("/api/stream");S=e,e.onopen=()=>{$=!0,M()},e.onerror=()=>{$=!1,M()},e.onmessage=()=>{_()}}let T=null;function _(){T||(T=window.setTimeout(async()=>{T=null;try{await q(),I(),M()}catch{}},1200))}function B(e){v=e,localStorage.setItem("pulse_locale",e),f?C():D()}function Q(e){g=e,localStorage.setItem("pulse_view",e),I(),document.querySelectorAll(".view-tabs button").forEach(t=>{t.classList.toggle("is-on",t.dataset.view===g)})}function D(){const e=u();h.innerHTML=`
    <div class="login-shell">
      <form class="login-panel" id="login-form">
        <div class="brand-lockup">
          <img src="/brand/logos/gereq-logo-header.png" alt="GEREQ" />
          <span class="brand-pulse">Pulse</span>
        </div>
        <div class="lang-toggle" style="align-self:flex-end">
          <button type="button" data-loc="cs" class="${v==="cs"?"is-on":""}">CS</button>
          <button type="button" data-loc="en" class="${v==="en"?"is-on":""}">EN</button>
        </div>
        <h1 class="type-title" style="margin:0">${e.loginTitle}</h1>
        <p class="type-small" style="margin:0;color:var(--gereq-muted)">${e.loginHint}</p>
        <label>
          <span class="type-kicker">${e.email}</span>
          <input name="email" type="email" value="test@gereq.cz" required />
        </label>
        <label>
          <span class="type-kicker">${e.password}</span>
          <input name="password" type="password" required />
        </label>
        <p class="login-error type-small ${w?"is-on":""}" id="login-error">${w}</p>
        <button type="submit" class="login-btn">${e.enter}</button>
      </form>
    </div>
  `,h.querySelectorAll("[data-loc]").forEach(i=>{i.addEventListener("click",()=>B(i.dataset.loc))});const t=document.getElementById("login-form");t.addEventListener("submit",async i=>{i.preventDefault();const o=new FormData(t),a=t.querySelector('button[type="submit"]');if(a.disabled=!0,!await O(String(o.get("email")),String(o.get("password")))){a.disabled=!1,document.getElementById("login-error").textContent=w,document.getElementById("login-error").classList.add("is-on");return}await G()})}function C(){const e=u();h.innerHTML=`
    <header class="shell-top">
      <div class="brand-lockup">
        <img src="/brand/logos/gereq-logo-header.png" alt="GEREQ" />
        <span class="brand-pulse">Pulse</span>
      </div>
      <div class="top-right">
        <div class="lang-toggle">
          <button type="button" data-loc="cs" class="${v==="cs"?"is-on":""}">CS</button>
          <button type="button" data-loc="en" class="${v==="en"?"is-on":""}">EN</button>
        </div>
        <div class="chip">${f?.name||""}</div>
        <button type="button" class="btn-ghost" id="logout">${e.logout}</button>
        <div class="live ${$?"":"is-off"}" id="live">
          <span class="live-dot"></span><span>${$?e.live:e.offline}</span>
        </div>
      </div>
    </header>

    <section class="hero" style="grid-template-columns:1fr;padding-bottom:0.5rem">
      <div>
        <p class="type-kicker" style="margin:0 0 0.4rem;color:var(--gereq-muted)">TIME TRACKING</p>
        <h1 class="type-display" style="margin:0">Pulse</h1>
        <p class="type-lead lead" style="margin-top:0.5rem">${e.tagline}</p>
      </div>
    </section>

    <div class="totals-row" id="totals"></div>

    <div class="view-tabs" id="views">
      <button data-view="timer" class="${g==="timer"?"is-on":""}">${e.timer}</button>
      <button data-view="day" class="${g==="day"?"is-on":""}">${e.day}</button>
      <button data-view="week" class="${g==="week"?"is-on":""}">${e.week}</button>
      <button data-view="insights" class="${g==="insights"?"is-on":""}">${e.insights}</button>
    </div>

    <div id="body"></div>
  `,document.getElementById("logout")?.addEventListener("click",()=>H()),h.querySelectorAll("[data-loc]").forEach(t=>{t.addEventListener("click",()=>B(t.dataset.loc))}),document.getElementById("views")?.addEventListener("click",t=>{const i=t.target.closest("button[data-view]");i&&Q(i.dataset.view)}),F(),I()}function M(){const e=document.getElementById("live");if(!e)return;const t=u();e.className=`live ${$?"":"is-off"}`,e.innerHTML=`<span class="live-dot"></span><span>${$?t.live:t.offline}</span>`}function F(){const e=document.getElementById("totals");if(!e||!l)return;const t=u();e.innerHTML=`
    <div class="total-card is-signal">
      <span class="type-kicker">${t.today} · ${t.tracked}</span>
      <div class="big">${d(l.totals.trackedSec||0)}</div>
    </div>
    <div class="total-card">
      <span class="type-kicker">${t.thisWeek}</span>
      <div class="big">${d(l.week?.trackedSec||0)}</div>
    </div>
    <div class="total-card">
      <span class="type-kicker">${t.edits} / ${t.ai}</span>
      <div class="big">${l.totals.edits} <span style="font-size:0.55em;color:var(--gereq-violet)">· ${l.totals.aiEdits}</span></div>
    </div>
    <div class="total-card">
      <span class="type-kicker">+/− ${t.files.toLowerCase()}</span>
      <div class="big">+${l.totals.linesAdded}<span style="font-size:0.55em;color:var(--gereq-coral)">/−${l.totals.linesRemoved}</span></div>
    </div>
  `}function I(){const e=document.getElementById("body");!e||!l||(F(),g==="timer"?(e.innerHTML=Z(),A(e)):g==="day"?e.innerHTML=J():g==="week"?e.innerHTML=K():(e.innerHTML=V(),A(e),U()))}function Z(){const e=u(),t=l.timeEntries||[],i=l.projects||[],o=l.timeline||[],a=new Map;for(const n of t)(a.get(n.date)||a.set(n.date,[]).get(n.date)).push(n);const c=[...a.entries()].map(([n,r])=>{const m=r.reduce((s,y)=>s+y.durationSec,0);return`
        <div class="entry-group-h"><span>${x(n)}</span><span>${d(m)}</span></div>
        <ul class="entry-list">
          ${r.map(s=>`<li>
                <span class="entry-dot" style="background:${s.color}"></span>
                <div class="entry-main">
                  <div class="title">${p(s.description)} ${s.ai?'<span style="color:var(--gereq-violet)">AI</span>':""}</div>
                  <div class="meta">${p(s.project)} · ${L(s.start)}–${L(s.end)}${s.language?` · ${s.language}`:""} · +${s.linesAdded}/−${s.linesRemoved}</div>
                </div>
                <div class="entry-dur">${d(s.durationSec)}</div>
              </li>`).join("")}
        </ul>`}).join("");return`
    <p class="type-kicker" style="color:var(--gereq-muted);margin:0 0 0.5rem">${e.activity}</p>
    <div class="pills" title="15 min activity">
      ${o.map(n=>`<div class="pill" data-i="${n.intensity}"></div>`).join("")}
    </div>
    <div class="grid">
      <section class="panel">
        <div class="panel-h"><h2 class="type-subhead">${e.entries}</h2></div>
        <div class="panel-b scroll" style="max-height:560px;padding:0">
          ${c||`<div class="empty">${e.emptyEntries}</div>`}
        </div>
      </section>
      <section class="panel">
        <div class="panel-h"><h2 class="type-subhead">${e.projects}</h2></div>
        <div class="panel-b">
          <ul class="proj-list">
            ${i.length?i.map(n=>`<li>
                        <span class="proj-swatch" style="background:${n.color}"></span>
                        <span>${p(n.project)}</span>
                        <strong style="font-variant-numeric:tabular-nums">${d(n.durationSec)}</strong>
                      </li>`).join(""):`<div class="empty">${e.emptyEntries}</div>`}
          </ul>
        </div>
      </section>
    </div>
  `}function J(){const e=u(),t=l.timeEntriesToday||[],i=Array.from({length:24},(n,r)=>r),o=new Date,a=(o.getHours()*60+o.getMinutes())/1440*100,c=t.map(n=>{const r=new Date(n.start),m=new Date(n.end),s=r.getHours()*60+r.getMinutes(),y=Math.max(s+8,m.getHours()*60+m.getMinutes()),b=s/1440*100,N=Math.max(1.2,(y-s)/1440*100);return`<div class="day-block" style="top:${b}%;height:${N}%;background:${n.color}">
        <div class="t">${L(n.start)}</div>
        <strong>${p(n.description)}</strong>
        <div>${p(n.project)} · ${d(n.durationSec)}</div>
      </div>`}).join("");return`
    <section class="panel">
      <div class="panel-h">
        <h2 class="type-subhead">${e.day} · ${x(new Date().toISOString().slice(0,10))}</h2>
        <span class="type-caption">${d(l.totals.trackedSec||0)}</span>
      </div>
      <div class="panel-b">
        <div class="day-cal" style="height:720px">
          <div class="day-hours">${i.map(n=>`<span>${String(n).padStart(2,"0")}:00</span>`).join("")}</div>
          <div class="day-track">
            <div class="day-now" style="top:${a}%"></div>
            ${c||`<div class="empty">${e.emptyEntries}</div>`}
          </div>
        </div>
      </div>
    </section>
  `}function K(){const e=u(),t=l.week?.days||[];return`
    <section class="panel" style="border:none;background:transparent">
      <div class="panel-h" style="border:1px solid var(--gereq-border);background:var(--gereq-white);margin-bottom:0.75rem">
        <h2 class="type-subhead">${e.week}</h2>
        <span class="type-caption">${l.week?.monday} → ${l.week?.sunday} · ${d(l.week?.trackedSec||0)}</span>
      </div>
      <div class="week-grid">
        ${t.map(i=>`<div class="week-col ${i.isToday?"is-today":""}">
              <div class="week-col-h">
                <span class="type-caption">${i.label}</span>
                <strong>${i.date.slice(8)}</strong>
                <span class="type-caption">${d(i.durationSec)}</span>
              </div>
              <div class="week-col-b">
                ${i.entries.length?i.entries.map(a=>{const c=Math.max(22,Math.min(120,a.durationSec/3600*360*.25));return`<div class="week-block" style="background:${a.color};min-height:${c}px">
                            <strong>${p(a.description)}</strong><br/>
                            ${d(a.durationSec)}
                          </div>`}).join(""):'<div class="empty" style="padding:1rem 0">—</div>'}
              </div>
            </div>`).join("")}
      </div>
    </section>
  `}function V(){const e=u(),t=l.byLanguage||[],i=Math.max(1,...t.map(s=>s.linesAdded+s.linesRemoved+s.focusSeconds/10)),o=l.byDay||[],a=v==="cs"?["Po","Út","St","Čt","Pá","So","Ne"]:["Mo","Tu","We","Th","Fr","Sa","Su"],n=((o[0]?new Date(o[0].date+"T12:00:00"):new Date).getDay()+6)%7,r=[...a.map(s=>`<div class="cal-head">${s}</div>`),...Array.from({length:n},()=>'<div class="cal-cell" style="visibility:hidden"></div>'),...o.map(s=>`<div class="cal-cell" data-i="${s.intensity}" title="${s.date} · ${s.edits} edits · ${d(s.focusSeconds)}"></div>`)],m=Math.max(1,...l.byHour.map(s=>Math.max(s.lines,s.edits)));return`
    <div class="grid">
      <section class="panel">
        <div class="panel-h"><h2 class="type-subhead">${e.calendar}</h2></div>
        <div class="panel-b"><div class="cal">${r.join("")}</div>
          <div class="cal-legend type-caption"><span>${e.less}</span>
            <i style="background:var(--gereq-wash)"></i><i style="background:#e8f5c4"></i>
            <i style="background:#d7ff45"></i><i style="background:#a8d120"></i>
            <i style="background:var(--gereq-ink)"></i><span>${e.more}</span>
          </div>
        </div>
      </section>
      <div class="stack">
        <section class="panel">
          <div class="panel-h"><h2 class="type-subhead">${e.hours}</h2></div>
          <div class="panel-b"><div class="bars">
            ${l.byHour.map((s,y)=>{const b=Math.round(Math.max(s.lines,s.edits)/m*100);return`<div class="bar" style="height:${Math.max(b,s.edits?4:1)}%" title="${y}:00"></div>`}).join("")}
          </div></div>
        </section>
        <section class="panel">
          <div class="panel-h"><h2 class="type-subhead">${e.languages}</h2></div>
          <div class="panel-b">
            ${t.length?`<ul class="lang-list">${t.map(s=>{const y=s.linesAdded+s.linesRemoved+s.focusSeconds/10,b=Math.round(y/i*100);return`<li><span class="name">${s.language}</span>
                        <div class="lang-track"><div class="lang-fill" style="width:${b}%"></div></div>
                        <span class="pct">${b}%</span></li>`}).join("")}</ul>`:`<div class="empty">${e.emptyLang}</div>`}
          </div>
        </section>
      </div>
      <section class="panel span-2">
        <div class="panel-h"><h2 class="type-subhead">${e.trend}</h2></div>
        <div class="panel-b"><svg class="line-chart" id="trend" viewBox="0 0 640 140" preserveAspectRatio="none"></svg></div>
      </section>
      <section class="panel">
        <div class="panel-h"><h2 class="type-subhead">${e.topFiles}</h2><span class="type-caption">${e.clickDiff}</span></div>
        <div class="panel-b scroll">
          ${l.topFiles.length?`<ul class="files">${l.topFiles.map(s=>`<li data-file="${p(s.file)}">
                      <span class="name">${p(s.file)}</span>
                      <span class="meta">+${s.linesAdded}/−${s.linesRemoved}</span>
                    </li>`).join("")}</ul>`:`<div class="empty">${e.emptyFiles}</div>`}
        </div>
      </section>
      <section class="panel">
        <div class="panel-h"><h2 class="type-subhead">${e.git}</h2></div>
        <div class="panel-b git-box">${l.lastGit?`<strong>${p(l.lastGit.shortstat||"clean")}</strong>`:e.waitingGit}</div>
      </section>
    </div>
    <section class="panel diff-panel">
      <div class="panel-h"><h2 class="type-subhead">${e.diff} · ${E||e.workingTree}</h2></div>
      <pre id="diff">${p(j||"…")}</pre>
    </section>
  `}function U(){const e=document.getElementById("trend");if(!e||!l?.byDay?.length)return;const t=l.byDay.map(r=>r.lines||0),i=Math.max(1,...t),o=640,a=140,c=o/Math.max(1,t.length-1),n=t.map((r,m)=>`${m*c},${a-r/i*(a-12)-6}`).join(" ");e.innerHTML=`<polygon fill="rgba(215,255,69,0.35)" points="0,${a} ${n} ${o},${a}" />
    <polyline fill="none" stroke="#0a0b0a" stroke-width="2" points="${n}" />`}function A(e){e.querySelectorAll("li[data-file]").forEach(t=>{t.addEventListener("click",async()=>{E=t.dataset.file||null,await P(E);const i=document.getElementById("diff");i&&(i.textContent=j)})})}function p(e){return e.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;")}async function G(){h.innerHTML=`<div class="empty">${u().loadingDash}</div>`,await q(),await P(null),C(),W()}async function X(){h.innerHTML=`<div class="empty">${u().loading}</div>`;try{if(!await R()){D();return}await G()}catch(e){h.innerHTML=`<div class="empty">API offline<br/><code>${e}</code></div>`}}X();
