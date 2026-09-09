---
name: no-native-dialogs
description: >-
  Never use native browser dialogs (window.alert, window.confirm, window.prompt)
  in GEREQ / RealEstates admin UI. Always use window.gereq.confirm / window.gereq.prompt
  (resources/js/ui/confirm.ts) or data-confirm forms. Use when adding confirmations,
  prompts, delete/save dialogs, CMS builder, or any user confirmation UI.
---

# No native browser dialogs

## Rule

**Nikdy** nepoužívej nativní dialogy prohlížeče:

- `window.alert(...)`
- `window.confirm(...)`
- `window.prompt(...)`

V admin UI vždy používej **naše** dialogy z `resources/js/ui/confirm.ts`.

## API

```ts
// Potvrzení (ano/ne)
const ok = await window.gereq.confirm({
  title: 'Smazat?',
  body: 'Tuto akci nelze vrátit.',
  confirmLabel: 'Smazat',
  danger: true, // default true
});

// Prompt s inputem
const name = await window.gereq.prompt({
  title: 'Uložit šablonu',
  body: 'Zadejte název (max. 120 znaků).',
  inputLabel: 'Název',
  inputPlaceholder: 'Název šablony',
  inputValue: '',
  confirmLabel: 'Uložit',
  danger: false, // u promptu default false
  inputRequired: true,
});
// name === null → zrušeno
```

Fallback helper (jako ve `webauthn.ts` / `cmsBuilder.ts`):

```ts
async function askConfirm(opts: { title?: string; body?: string; danger?: boolean }): Promise<boolean> {
  if (window.gereq?.confirm) return window.gereq.confirm(opts);
  return window.confirm(opts.body || opts.title || '');
}

async function askPrompt(opts: {
  title?: string;
  body?: string;
  inputLabel?: string;
  inputValue?: string;
  confirmLabel?: string;
}): Promise<string | null> {
  if (window.gereq?.prompt) {
    return window.gereq.prompt({ ...opts, input: true, inputRequired: true, danger: false });
  }
  return window.prompt(opts.body || opts.title || '', opts.inputValue || '');
}
```

## Twig formuláře

Pro submit potvrzení používej `data-confirm` (ne `onsubmit="return confirm(...)"`):

```twig
<form method="post" action="..." data-confirm="{{ 'cms.confirm_delete_page'|trans }}" data-confirm-title="{{ 'ui.confirm.delete_title'|trans }}" data-confirm-danger="1">
```

## Checklist při code review / nové UI

- [ ] Žádné `window.confirm` / `prompt` / `alert` v novém kódu (kromě fallbacku uvnitř `askConfirm`/`askPrompt`)
- [ ] Async flow: volající funkce musí být `async` a `await`ovat dialog
- [ ] Toast (`window.gereq.toast`) pro výsledek, ne `alert`
