# GEREQ i18n

Hover, autocomplete a F12 pro překladové klíče v Twig a PHP.

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
- **Autocomplete** — návrhy klíčů při psaní
- **Go to Definition** — skok na řádek v `messages.<locale>.php`
- Output kanál **GEREQ i18n** — LSP-like log (`didOpen`, `hover`, `completion`, …)

### Detekované kontexty

| Kontext | Příklad |
| --- | --- |
| Twig filter | `'customers.field.name'\|trans` |
| PHP translator | `$this->translator->trans('nav.customers')` |
| Named arg (FormField, MenuItem, …) | `label: 'customers.field.name'` |
| Named arg | `singular:`, `hint:`, `title:`, `message:`, `placeholder:` |
| Array value | `'label' => 'nav.customers'`, `'placeholder' => '…'` |
| Katalog (fallback) | `'customers.status.active'` v match/return, pokud klíč existuje |
