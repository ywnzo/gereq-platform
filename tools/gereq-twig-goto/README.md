# GEREQ Twig IDE

<p align="center">
  <img src="logo.png" alt="GEREQ Twig IDE" width="128" height="128" />
</p>

**Cursor / VS Code extension** — hover, `.` autocomplete and Go to Definition for objects in `.twig` templates. Types are exported from controllers and stored **outside** templates.

| | |
| --- | --- |
| **GitLab** | https://git.gereq.cz/gereq/tool-twig-goto |
| **Install** | `php gereq twig:types` → `bash install.sh` → *Developer: Reload Window* |

## Generate types (platform)

```bash
php gereq twig:types
```

Output:

```
.cache/twig/types.json
.cache/twig/vars/…
```

Command palette: **GEREQ Twig: Reindex types (.cache/twig)**.
