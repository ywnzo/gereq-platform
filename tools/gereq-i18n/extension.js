const vscode = require('vscode');
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');

/** @type {Record<string, Record<string, { value: string, file: string, line: number, domain?: string }>>} */
let catalog = {};
/** @type {string[]} */
let locales = [];
/** @type {{ appendLine: Function }} */
let log = { appendLine() {} };

/** @type {Set<string>} */
const openedLogged = new Set();
/** @type {number} */
let lastCompletionLog = 0;

function ts() {
  return new Date().toISOString().slice(11, 23);
}

function lsp(method, detail) {
  log.appendLine(`[${ts()}] ${method}${detail ? `  ${detail}` : ''}`);
}

function cachePath(root) {
  return path.join(root, '.cache', 'i18n', 'catalog.json');
}

function rebuildIndex(workspaceRoot) {
  const indexer = path.join(workspaceRoot, 'tools', 'gereq-i18n', 'index-translations.php');
  if (!fs.existsSync(indexer)) {
    return;
  }
  execFileSync('php', [indexer], { cwd: workspaceRoot, timeout: 120000 });
}

function loadIndex(workspaceRoot) {
  const file = cachePath(workspaceRoot);
  if (!fs.existsSync(file)) {
    lsp('workspace/index', 'cache missing → rebuild');
    try {
      rebuildIndex(workspaceRoot);
    } catch (e) {
      lsp('workspace/index', `rebuild failed: ${e}`);
    }
  }
  if (!fs.existsSync(file)) {
    catalog = {};
    locales = [];
    lsp('workspace/index', 'empty (no cache)');
    return;
  }
  try {
    const raw = JSON.parse(fs.readFileSync(file, 'utf8'));
    catalog = raw.keys || {};
    locales = Array.isArray(raw.locales) ? raw.locales : Object.keys(
      Object.values(catalog)[0] || {},
    ).sort();
    lsp(
      'workspace/index',
      `${Object.keys(catalog).length} keys, locales [${locales.join(', ')}] ← ${path.relative(workspaceRoot, file)}`,
    );
    if (raw.generated_at) {
      lsp('workspace/index', `generated_at ${raw.generated_at}`);
    }
  } catch (e) {
    lsp('workspace/index', `read failed: ${e}`);
    catalog = {};
    locales = [];
  }
}

function entryFor(key) {
  return catalog[key] || null;
}

function preferredLocaleOrder() {
  const prefer = ['cs', 'en'];
  const rest = locales.filter((l) => !prefer.includes(l));
  return [...prefer.filter((l) => locales.includes(l)), ...rest];
}

/**
 * Klíč pod kurzorem: 'key'|trans / "key"|trans / ->trans('key')
 * @returns {{ key: string, range: vscode.Range } | null}
 */
function keyAtPosition(document, position) {
  const line = document.lineAt(position.line).text;
  const candidates = [];

  const twigRe = /(['"])([a-zA-Z0-9][a-zA-Z0-9_.-]*)\1\s*\|\s*trans\b/g;
  let m;
  while ((m = twigRe.exec(line))) {
    const keyStart = m.index + 1;
    const keyEnd = keyStart + m[2].length;
    candidates.push({
      key: m[2],
      start: keyStart,
      end: keyEnd,
    });
  }

  const phpRe = /->\s*trans\s*\(\s*(['"])([a-zA-Z0-9][a-zA-Z0-9_.-]*)\1/g;
  while ((m = phpRe.exec(line))) {
    const quotePos = m.index + m[0].indexOf(m[1]);
    const keyStart = quotePos + 1;
    const keyEnd = keyStart + m[2].length;
    candidates.push({
      key: m[2],
      start: keyStart,
      end: keyEnd,
    });
  }

  const ch = position.character;
  for (const c of candidates) {
    if (ch >= c.start && ch <= c.end) {
      return {
        key: c.key,
        range: new vscode.Range(
          position.line,
          c.start,
          position.line,
          c.end,
        ),
      };
    }
  }
  return null;
}

/**
 * Autocomplete kontext: uvnitř uvozovek u |trans / ->trans(
 * V .twig i bez |trans (klíče se píšou jako 'foo.bar').
 * @returns {{ prefix: string, range: vscode.Range } | null}
 */
function completionContext(document, position) {
  const line = document.lineAt(position.line).text;
  const before = line.slice(0, position.character);
  const after = line.slice(position.character);
  const isTwig = document.fileName.endsWith('.twig');

  // ->trans('prefix
  if (/->\s*trans\s*\(\s*(['"])[a-zA-Z0-9_.-]*$/.test(before)) {
    const m = before.match(/(['"])([a-zA-Z0-9_.-]*)$/);
    if (m) {
      const prefix = m[2];
      const start = position.character - prefix.length;
      return {
        prefix,
        range: new vscode.Range(position.line, start, position.line, position.character),
      };
    }
  }

  const twigOpen = before.match(/(['"])([a-zA-Z0-9_.-]*)$/);
  if (!twigOpen) {
    return null;
  }
  const quote = twigOpen[1];
  const prefix = twigOpen[2];

  const hasTransAfter =
    after.includes('|trans')
    || after.includes('| trans')
    || new RegExp(`^${quote}\\s*\\|\\s*trans\\b`).test(after);

  // Twig: jen když je na řádku |trans, nebo prefix už vypadá jako klíč (má tečku)
  if (isTwig) {
    if (hasTransAfter || prefix.includes('.')) {
      const start = position.character - prefix.length;
      return {
        prefix,
        range: new vscode.Range(position.line, start, position.line, position.character),
      };
    }
  } else if (hasTransAfter) {
    const start = position.character - prefix.length;
    return {
      prefix,
      range: new vscode.Range(position.line, start, position.line, position.character),
    };
  }

  return null;
}

function hoverMarkdown(key) {
  const entry = entryFor(key);
  const md = new vscode.MarkdownString(undefined, true);
  md.isTrusted = true;
  md.supportHtml = true;
  md.appendMarkdown(`### $(globe) \`${key}\``);
  md.appendMarkdown('\n\n');

  if (!entry) {
    md.appendMarkdown('_Klíč není v katalogu_ — spusť **GEREQ i18n: Reindex**');
    return md;
  }

  const order = preferredLocaleOrder();
  const rows = [];
  for (const loc of order) {
    if (!entry[loc]) {
      continue;
    }
    const val = entry[loc].value.replace(/\|/g, '\\|').replace(/\n/g, ' ');
    rows.push(`| **${loc}** | ${val} |`);
  }
  // zbylé locale
  for (const loc of Object.keys(entry)) {
    if (order.includes(loc)) {
      continue;
    }
    const val = entry[loc].value.replace(/\|/g, '\\|').replace(/\n/g, ' ');
    rows.push(`| **${loc}** | ${val} |`);
  }

  if (rows.length) {
    md.appendMarkdown('| | |\n| --- | --- |\n');
    md.appendMarkdown(rows.join('\n'));
    md.appendMarkdown('\n');
  }

  const missing = locales.filter((l) => !entry[l]);
  if (missing.length) {
    md.appendMarkdown(`\n\n_chybí: ${missing.join(', ')}_`);
  }

  const first = entry[order.find((l) => entry[l]) || Object.keys(entry)[0]];
  if (first?.file) {
    md.appendMarkdown(`\n\n\`${first.file}:${first.line}\``);
  }

  return md;
}

function locationForKey(workspaceRoot, key) {
  const entry = entryFor(key);
  if (!entry) {
    return null;
  }
  const order = preferredLocaleOrder();
  let hit = null;
  for (const loc of order) {
    if (entry[loc]) {
      hit = entry[loc];
      break;
    }
  }
  if (!hit) {
    hit = entry[Object.keys(entry)[0]];
  }
  if (!hit?.file) {
    return null;
  }
  const abs = path.join(workspaceRoot, hit.file);
  if (!fs.existsSync(abs)) {
    return null;
  }
  const line = Math.max(0, (hit.line || 1) - 1);
  return new vscode.Location(
    vscode.Uri.file(abs),
    new vscode.Position(line, 0),
  );
}

function onDocOpened(document) {
  const name = document.fileName;
  if (!name.endsWith('.twig') && !name.endsWith('.php')) {
    return;
  }
  const id = document.uri.fsPath;
  if (openedLogged.has(id)) {
    return;
  }
  openedLogged.add(id);

  const text = document.getText();
  const keys = new Set();
  const twigRe = /(['"])([a-zA-Z0-9][a-zA-Z0-9_.-]*)\1\s*\|\s*trans\b/g;
  const phpRe = /->\s*trans\s*\(\s*(['"])([a-zA-Z0-9][a-zA-Z0-9_.-]*)\1/g;
  let m;
  while ((m = twigRe.exec(text))) {
    keys.add(m[2]);
  }
  while ((m = phpRe.exec(text))) {
    keys.add(m[2]);
  }

  const rel = vscode.workspace.asRelativePath(document.uri);
  lsp('textDocument/didOpen', rel);
  if (!keys.size) {
    lsp('  → keys', 'none');
    return;
  }
  let known = 0;
  let missing = 0;
  for (const k of keys) {
    if (catalog[k]) {
      known++;
    } else {
      missing++;
    }
  }
  lsp('  → keys', `${keys.size} used · ${known} in catalog · ${missing} missing`);
  const sample = [...keys].slice(0, 8);
  for (const k of sample) {
    const e = catalog[k];
    if (!e) {
      lsp('  →', `${k} ✗`);
      continue;
    }
    const parts = preferredLocaleOrder()
      .filter((l) => e[l])
      .map((l) => `${l}=${JSON.stringify(e[l].value).slice(0, 40)}`);
    lsp('  →', `${k} · ${parts.join(' · ')}`);
  }
  if (keys.size > 8) {
    lsp('  →', `… +${keys.size - 8} more`);
  }
}

function activate(context) {
  log = vscode.window.createOutputChannel('GEREQ i18n');
  context.subscriptions.push(log);
  lsp('initialize', 'GEREQ i18n');

  const root = () => vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;

  const folders = vscode.workspace.workspaceFolders;
  if (folders) {
    loadIndex(folders[0].uri.fsPath);
  }

  const selector = [
    { pattern: '**/*.twig' },
    { language: 'php', pattern: '**/*.{php}' },
  ];

  const hover = {
    provideHover(document, position) {
      const hit = keyAtPosition(document, position);
      if (!hit) {
        return null;
      }
      const entry = entryFor(hit.key);
      lsp(
        'textDocument/hover',
        entry
          ? `${hit.key} · ${Object.keys(entry).join('/')}`
          : `${hit.key} · missing`,
      );
      return new vscode.Hover(hoverMarkdown(hit.key), hit.range);
    },
  };

  const definition = {
    provideDefinition(document, position) {
      const workspaceRoot = root();
      if (!workspaceRoot) {
        return null;
      }
      const hit = keyAtPosition(document, position);
      if (!hit) {
        return null;
      }
      const loc = locationForKey(workspaceRoot, hit.key);
      lsp(
        'textDocument/definition',
        `${hit.key} → ${loc ? 'ok' : 'miss'}`,
      );
      return loc;
    },
  };

  const completion = {
    provideCompletionItems(document, position) {
      const ctx = completionContext(document, position);
      if (!ctx) {
        return null;
      }
      const prefix = ctx.prefix.toLowerCase();
      const allKeys = Object.keys(catalog);
      const matched = allKeys
        .filter((k) => k.toLowerCase().startsWith(prefix) || k.toLowerCase().includes(prefix))
        .sort((a, b) => {
          const aS = a.toLowerCase().startsWith(prefix) ? 0 : 1;
          const bS = b.toLowerCase().startsWith(prefix) ? 0 : 1;
          if (aS !== bS) {
            return aS - bS;
          }
          return a.length - b.length || a.localeCompare(b);
        })
        .slice(0, 80);

      const now = Date.now();
      if (now - lastCompletionLog > 400) {
        lastCompletionLog = now;
        lsp(
          'textDocument/completion',
          `"${ctx.prefix}" → ${matched.length} keys`,
        );
      }

      return matched.map((key) => {
        const entry = catalog[key];
        const item = new vscode.CompletionItem(key, vscode.CompletionItemKind.Text);
        item.range = ctx.range;
        item.insertText = key;
        const order = preferredLocaleOrder();
        const snippets = order
          .filter((l) => entry[l])
          .map((l) => `${l}: ${entry[l].value}`);
        item.detail = snippets[0] || key;
        const doc = new vscode.MarkdownString(undefined, true);
        doc.appendMarkdown(`\`${key}\`\n\n`);
        for (const s of snippets) {
          doc.appendMarkdown(`- ${s}\n`);
        }
        item.documentation = doc;
        item.filterText = key;
        item.sortText = key.toLowerCase().startsWith(prefix) ? `0${key}` : `1${key}`;
        return item;
      });
    },
  };

  context.subscriptions.push(
    vscode.languages.registerHoverProvider(selector, hover),
    vscode.languages.registerDefinitionProvider(selector, definition),
    vscode.languages.registerCompletionItemProvider(selector, completion, "'", '"', '.'),
    vscode.commands.registerCommand('gereq.i18n.reindex', async () => {
      const workspaceRoot = root();
      if (!workspaceRoot) {
        return;
      }
      try {
        lsp('workspace/reindex', 'start');
        rebuildIndex(workspaceRoot);
        openedLogged.clear();
        loadIndex(workspaceRoot);
        lsp('workspace/reindex', 'done');
        vscode.window.showInformationMessage(
          `GEREQ i18n: ${Object.keys(catalog).length} klíčů [${locales.join(', ')}] → .cache/i18n/`,
        );
      } catch (e) {
        lsp('workspace/reindex', `failed: ${e}`);
        vscode.window.showErrorMessage(`Reindex selhal: ${e}`);
      }
    }),
  );

  context.subscriptions.push(
    vscode.workspace.onDidOpenTextDocument((doc) => onDocOpened(doc)),
    vscode.window.onDidChangeActiveTextEditor((editor) => {
      if (editor) {
        onDocOpened(editor.document);
      }
    }),
  );
  for (const editor of vscode.window.visibleTextEditors) {
    onDocOpened(editor.document);
  }

  const watcher = vscode.workspace.createFileSystemWatcher('**/translations/*.php');
  let reloadTimer = null;
  const reload = (uri) => {
    const workspaceRoot = root();
    if (!workspaceRoot) {
      return;
    }
    if (reloadTimer) {
      clearTimeout(reloadTimer);
    }
    reloadTimer = setTimeout(() => {
      try {
        lsp(
          'workspace/didChangeWatchedFiles',
          uri ? path.relative(workspaceRoot, uri.fsPath) : 'translations',
        );
        rebuildIndex(workspaceRoot);
        openedLogged.clear();
        loadIndex(workspaceRoot);
      } catch (e) {
        lsp('workspace/didChangeWatchedFiles', `rebuild failed: ${e}`);
      }
    }, 600);
  };
  watcher.onDidChange(reload);
  watcher.onDidCreate(reload);
  watcher.onDidDelete(reload);
  context.subscriptions.push(watcher);

  lsp('initialized', 'hover · definition · completion · didOpen logging');
}

function deactivate() {}

module.exports = { activate, deactivate };
