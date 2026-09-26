# GEREQ i18n

<p align="center">
  <img src="logo.png" alt="GEREQ i18n" width="128" height="128" />
</p>

**Cursor / VS Code extension** — hover, autocomplete and Go to Definition for GEREQ translation keys in Twig and PHP.

| | |
| --- | --- |
| **GitLab** | https://git.gereq.cz/gereq/tool-i18n |
| **Install** | index → `bash install.sh` → *Developer: Reload Window* |

## Index

```bash
php index-translations.php
# → .cache/i18n/catalog.json  (run from GEREQ Platform root)
```

## Features

- Hover — cs / en (and other locales) side by side
- Autocomplete while typing keys
- Go to Definition → line in `messages.<locale>.php`
- Output channel **GEREQ i18n**

### Detected contexts

| Context | Example |
| --- | --- |
| Twig filter | `'customers.field.name'\|trans` |
| PHP translator | `$this->translator->trans('nav.customers')` |
| Named args | `label:`, `hint:`, `title:`, `message:`, `placeholder:` |
| Array values | `'label' => 'nav.customers'` |
