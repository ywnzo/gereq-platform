# GEREQ Pulse

Monitorování práce ve workspace — soubory, řádky, diff, AI heuristika. Dashboard na **:8772**.

## Instalace

```bash
bash tools/gereq-monitor/install.sh
```

**Developer: Reload Window** → status bar **Pulse** nebo http://127.0.0.1:8772

## Co sbírá

| event | popis |
|-------|--------|
| `open` / `close` / `save` | dokumenty |
| `edit` | +/− řádky, `source: user\|ai` |
| `focus` | čas na souboru |
| `create` / `delete` / `rename` | FS |
| `git` | shortstat každých 60s |

AI = heuristika (velký jednorázový chunk / Cursor apply), ne 100% přesný telemetrie agentů.

Data: `.cache/worklog/events.jsonl`

## Login (dočasné)

Po otevření `:8772` je přihlášení:

- e-mail: `test@gereq.cz`
- heslo: `heslo`

Později napojíme na GEREQ API (`lib/auth.js` → `tryLogin`).

UI používá brand z `GEREQ.cz/.brand` (Ink / Cream / Signal Lime, Space Grotesk + DM Sans).

Pohledy jako Toggl Track:
- **Timer** — time entries + projekty + 15min activity timeline
- **Den** — vertikální kalendář 0–24 h s bloky
- **Týden** — 7 sloupců s entries
- **Insights** — heatmapa, jazyky, grafy

## Příkazy

- **GEREQ Pulse: Open dashboard (:8772)**
- **GEREQ Pulse: Reset today's session log**

## Dev UI

```bash
cd tools/gereq-monitor/ui
bun install && bun run dev   # :8773, proxy → :8772
bun run build
```
