# GEREQ Env Schema

<p align="center">
  <img src="logo.png" alt="GEREQ Env" width="128" height="128" />
</p>

**Cursor / VS Code extension** — autocomplete, hover and diagnostics for GEREQ `.env` keys from `config/env.schema.toml`, plus `env_value('…')` completion in PHP.

| | |
| --- | --- |
| **GitLab** | https://git.gereq.cz/gereq/tool-env |
| **Install** | `bash install.sh` → *Developer: Reload Window* |

## Features

- Completion of known keys in `.env*` files (enum values after `=`)
- Hover with type, default, secret/required flags and description
- Diagnostics for unknown / invalid / missing required keys
- Completion inside `env_value('…')` in PHP

## Generate `.env` (platform)

```bash
bin/grq env init --docker          # no Composer
php gereq env:init --docker
php gereq env:validate
```

Schema source of truth: `config/env.schema.toml` in GEREQ Platform.
