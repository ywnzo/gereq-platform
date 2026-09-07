# GEREQ i18n

Hover, autocomplete a F12 pro překladové klíče v Twig (`'key'|trans`) a PHP (`->trans('key')`).

## Generování

```bash
php tools/gereq-i18n/index-translations.php
```

Výstup: `.cache/i18n/catalog.json`

## Instalace

```bash
php tools/gereq-i18n/index-translations.php
bash tools/gereq-i18n/install.sh
```

**Developer: Reload Window**. Příkaz: **GEREQ i18n: Reindex translations (.cache/i18n)**.

## Co umí

- **Hover** — cs / en (a další locale) vedle sebe
- **Autocomplete** — návrhy klíčů uvnitř `'…'|trans` / `->trans('…')`
- **Go to Definition** — skok na řádek v `messages.<locale>.php`
- Output kanál **GEREQ i18n** — LSP-like log (`didOpen`, `hover`, `completion`, …)
