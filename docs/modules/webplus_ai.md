# Web+AI (`webplus_ai`)

Web+AI is the **WebMCP / agent-tool registry** for [WebPlus](webplus.md): an admin-managed catalog of tools (name, title, description, JSON input/output schemas, enabled/mutating/builtin flags) plus public discovery endpoints. V1 is **discovery-only** (no invoke runtime). It seeds builtins `find_properties`, `get_property_detail`, `get_site_info`, injects into Web+ `public_site_endpoints` and `llms.txt`, and belongs to the **RealEstates** package (not Forum).

Related: [WebPlus](webplus.md), [Listings](listings.md), [Ai](ai.md), [AI platform](../AI_PLATFORM.md), [module system](../architecture/module-system.md).

## At a glance

| Item | Value |
| --- | --- |
| Module id | `webplus_ai` |
| Version | `1.99.0` (`modules/WebPlusAi/module.php`, `WebPlusAiModule::version()`) |
| Directory | `modules/WebPlusAi/` |
| Class | `Modules\WebPlusAi\WebPlusAiModule` |
| Requires | `core ^1.0`, `webplus ^1.5` |
| Optional | `listings ^1.0` |
| Capabilities provided | `webplus.ai` → `Application/WebPlusAiProvider` |
| Groups / packages | none in `module.php`; listed in `config/module_groups.php` package **RealEstates** |
| Permissions | `webplus_ai.view`, `webplus_ai.manage` |
| API scopes | none (public discovery only) |

## Domain model

| Entity | Table | Key fields |
| --- | --- | --- |
| `AgentTool` | `webplus_ai_tools` | unique `name` slug, title, description, `input_schema` / `output_schema` JSON, `is_enabled`, `is_mutating`, `is_builtin`, `sort_order` |

## Admin UI

Child of the Web+ menu: tools list / CRUD / toggle and settings. Routes under `/admin/webplus-ai/tools` and `/admin/webplus-ai/settings`. Command palette keywords: ai, mcp, webmcp, agent, tools.

## Public API

| Endpoint | Notes |
| --- | --- |
| `GET /api/public/webplus-ai/tools.json` | Open catalog (WebMCP) |
| `GET /api/public/webplus-ai/tools` | Same in GEREQ API envelope |
| `GET /api/public/webplus-ai/ai.txt` | Informal AI policy |
| `GET /api/public/webplus-ai/llms-full.txt` | llms + embedded schemas |

FE rewrites via Web+: `/tools.json`, `/ai.txt`, `/llms-full.txt`. No `/api/v1`. No platform events.

## Settings

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `webplus_ai.enabled` | bool | `true` | Master switch for public discovery |

## Other

- Migrations: 1 (empty `up` — SchemaTool from entity; `down` drops table).
- Hooks: `webplus.public_site_endpoints`, `webplus.llms_txt`.
- No CLI or dashboard widgets. Tests directory not yet populated — see [MODULE_AUDIT](../MODULE_AUDIT.md).
