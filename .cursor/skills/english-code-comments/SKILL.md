---
name: english-code-comments
description: >-
  All code comments, docblocks, TODO/FIXME notes, and developer-facing inline
  notes must be written in English. Use when writing or editing PHP, TS/JS, CSS,
  SQL, shell, Go, Makefile, or any source file; when reviewing comments; or when
  the user mentions comments, notes in code, docblocks, or language of comments.
---

# English-only code comments

## Rule

**All** developer-facing notes in source files must be **English**:

- Line comments (`//`, `#`)
- Block comments (`/* … */`)
- Docblocks / JSDoc / PHPDoc (`/** … */`)
- `TODO`, `FIXME`, `NOTE`, `HACK`, `XXX`
- Inline annotations next to code (e.g. `// fallback when X`)
- Makefile target help (`## …`) and similar tooling notes
- CLI / tooling help strings in code (e.g. `#[AsCommand(..., description: '...')]`)

**Do not** write Czech (or other non-English) comments or notes in code.

## Out of scope (leave as-is)

These are **not** code comments — keep the product/locale language:

- Translation catalogs (`resources/translations/`, `messages.*.php`, i18n JSON)
- User-facing UI copy, Twig `|trans` strings, CMS/demo seed **content**
- Public README / product docs aimed at end users (unless the user asks to translate those)

## When editing existing Czech notes

Replace Czech comments with clear, concise English. Preserve meaning; do not invent extra commentary. Prefer short imperative or descriptive phrases.

```php
// Bad
// Tabulka ještě neexistuje — vytvoříme níže.

// Good
// Table does not exist yet — created below.
```

```ts
// Bad
/* uživatel zavřel dialog */

// Good
/* user dismissed the dialog */
```

## Checklist

- [ ] New comments / notes are English only
- [ ] No Czech diacritics in `//`, `#`, `/*`, `/**`, `##` help text
- [ ] User-facing strings and translation files untouched
