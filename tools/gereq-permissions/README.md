# GEREQ Permissions

Hover, autocomplete a F12 pro permission klíče:

- Twig: `user.can('products.view')`
- PHP: `$user->can('…')`, `->permission('…')`, `permission: '…'`

## Generování

```bash
php tools/gereq-permissions/index-permissions.php
```

Výstup: `.cache/permissions/catalog.json`

## Instalace

```bash
bash tools/gereq-permissions/install.sh
```

**Developer: Reload Window**. Příkaz: **GEREQ Permissions: Reindex**.
