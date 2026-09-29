# Media (core)

Media library (assets, folders, links). Formerly `modules/Media`; now part of the platform core under `src/Media/`.

| | |
| --- | --- |
| Namespace | `Gereq\Media` |
| Registrar | `Gereq\Media\MediaRegistrar` |
| Capability | `media` → `MediaProvider` (provider id `core`) |
| Entities | `MediaAsset`, `MediaFolder`, `MediaLink` |
| Admin | `/admin/media` — list uses `ui()->table()` |
| Public file | `GET /media/{id}/file` |

Admin index is built with the declarative UI API (`ui()->column` / `ui()->table`), including destroy row actions and non-checkable folder rows.
