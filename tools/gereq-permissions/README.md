# GEREQ Permissions

<p align="center">
  <img src="logo.png" alt="GEREQ Permissions" width="128" height="128" />
</p>

**Cursor / VS Code extension** — hover, autocomplete and Go to Definition for GEREQ permission keys.

| | |
| --- | --- |
| **GitLab** | https://git.gereq.cz/gereq/tool-permissions |
| **Install** | index → `bash install.sh` → *Developer: Reload Window* |

## Index

```bash
php index-permissions.php
# → .cache/permissions/catalog.json  (run from GEREQ Platform root)
```

## Contexts

- Twig: `user.can('products.view')`
- PHP: `$user->can('…')`, `->permission('…')`, `permission: '…'`

Command palette: **GEREQ Permissions: Reindex**.
