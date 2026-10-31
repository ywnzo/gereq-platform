# AI — Web+ submodule (`webplus_ai`)

Bundled with Web+ in `Submodules/Ai/`; there is no separate Git repository or release archive.
The parent Web+ module must be enabled before AI can run. AI can be paused or
uninstalled independently. Its module ID, permissions, settings keys, public URLs,
`webplus_ai_tools` table and migration IDs remain unchanged from the former
standalone WebPlusAi module.

Version: **2.0.0** (shipped in Web+ **2.1.0**).
Namespace: `Modules\WebPlus\Submodules\Ai`.
Twig namespace: `@webplus_ai`.

For an existing installation, update Web+ and AI in Admin → Modules after deploying
the bundle. Existing tool records and migration history are retained. Source
imports using `Modules\WebPlusAi` must use the new namespace.

Registr WebMCP / agent toolů — veřejný katalog + admin CRUD. V1 bez `invoke` runtime.

## Public discovery

| Endpoint | Auth |
| --- | --- |
| `GET /tools.json` (FE rewrite) | open |
| `GET /api/public/webplus-ai/tools.json` | open (OPEN_DISCOVERY) |
| `GET /api/public/webplus-ai/tools` | public key / first-party |

Built-in tools: `find_properties`, `get_property_detail`, `get_site_info`.

## Admin

- `/admin/webplus-ai/tools` — seznam, toggle, CRUD
- `/admin/webplus-ai/settings` — master switch `webplus_ai.enabled`

Menu child pod Web+ (`parent: webplus`).

## Requires

`webplus ^2.1`
