# GEREQ Twig IDE

Hover, autocomplete (po `.`) a F12 pro objekty v `.twig` — typy jsou **mimo šablony**.

## Generování

```bash
php gereq twig:types
```

Výstup:

```
.cache/twig/types.json
.cache/twig/vars/modules/Currency/Resources/views/index.html.twig.types.twig
```

Šablony zůstanou čisté (staré `BEGIN-GEREQ-TWIG-TYPES` bloky se při běhu smažou).

## Instalace

```bash
php gereq twig:types
bash tools/gereq-twig-goto/install.sh
```

**Developer: Reload Window**. Příkaz: **GEREQ Twig: Reindex types (.cache/twig)**.
