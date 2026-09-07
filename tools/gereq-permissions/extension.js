const vscode = require('vscode');
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');

/** @type {Record<string, {
 *   name: string,
 *   label: string,
 *   group: string,
 *   module: string|null,
 *   file: string,
 *   line: number,
 *   labels: Record<string, string>,
 *   groups: Record<string, string>
 * }>} */
let catalog = {};
/** @type {Record<string, string[]>} */
let groups = {};
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
  return path.join(root, '.cache', 'permissions', 'catalog.json');
}

function rebuildIndex(workspaceRoot) {
  const indexer = path.join(workspaceRoot, 'tools', 'gereq-permissions', 'index-permissions.php');
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
    groups = {};
    lsp('workspace/index', 'empty');
    return;
  }
  try {
    const raw = JSON.parse(fs.readFileSync(file, 'utf8'));
    catalog = raw.permissions || {};
    groups = raw.groups || {};
    lsp(
      'workspace/index',
      `${Object.keys(catalog).length} permissions, ${Object.keys(groups).length} groups ← ${path.relative(workspaceRoot, file)}`,
    );
    if (raw.generated_at) {
      lsp('workspace/index', `generated_at ${raw.generated_at}`);
    }
  } catch (e) {
    lsp('workspace/index', `read failed: ${e}`);
    catalog = {};
    groups = {};
  }
}

function entryFor(name) {
  return catalog[name] || null;
}

/**
 * Permission string under cursor in can()/permission() contexts.
 * @returns {{ key: string, range: vscode.Range } | null}
 */
function keyAtPosition(document, position) {
  const line = document.lineAt(position.line).text;
  const candidates = [];

  const patterns = [
    /\.can\s*\(\s*(['"])([a-zA-Z0-9][a-zA-Z0-9_.]*)\1/g,
    /->\s*can\s*\(\s*(['"])([a-zA-Z0-9][a-zA-Z0-9_.]*)\1/g,
    /->\s*permission\s*\(\s*(['"])([a-zA-Z0-9][a-zA-Z0-9_.]*)\1/g,
    /permission\s*:\s*(['"])([a-zA-Z0-9][a-zA-Z0-9_.]*)\1/g,
    /'name'\s*=>\s*(['"])([a-zA-Z0-9][a-zA-Z0-9_.]*)\1/g,
  ];

  for (const re of patterns) {
    let m;
    while ((m = re.exec(line))) {
      const quotePos = m.index + m[0].indexOf(m[1]);
      const keyStart = quotePos + 1;
      const keyEnd = keyStart + m[2].length;
      candidates.push({ key: m[2], start: keyStart, end: keyEnd });
    }
  }

  const ch = position.character;
  for (const c of candidates) {
    if (ch >= c.start && ch <= c.end) {
      return {
        key: c.key,
        range: new vscode.Range(position.line, c.start, position.line, c.end),
      };
    }
  }
  return null;
}

/**
 * Autocomplete inside can('/permission') contexts.
 * @returns {{ prefix: string, range: vscode.Range } | null}
 */
function completionContext(document, position) {
  const line = document.lineAt(position.line).text;
  const before = line.slice(0, position.character);

  const contexts = [
    /\.can\s*\(\s*(['"])([a-zA-Z0-9_.]*)$/,
    /->\s*can\s*\(\s*(['"])([a-zA-Z0-9_.]*)$/,
    /->\s*permission\s*\(\s*(['"])([a-zA-Z0-9_.]*)$/,
    /permission\s*:\s*(['"])([a-zA-Z0-9_.]*)$/,
    /'name'\s*=>\s*(['"])([a-zA-Z0-9_.]*)$/,
  ];

  for (const re of contexts) {
    const m = before.match(re);
    if (m) {
      const prefix = m[2];
      const start = position.character - prefix.length;
      return {
        prefix,
        range: new vscode.Range(position.line, start, position.line, position.character),
      };
    }
  }
  return null;
}

function hoverMarkdown(key) {
  const entry = entryFor(key);
  const md = new vscode.MarkdownString(undefined, true);
  md.isTrusted = true;
  md.supportHtml = true;

  md.appendMarkdown(`### $(shield) \`${key}\``);
  md.appendMarkdown('\n\n');

  if (!entry) {
    md.appendMarkdown('_Permission není v katalogu_ — **GEREQ Permissions: Reindex**');
    return md;
  }

  const labelCs = entry.labels?.cs || entry.label;
  const labelEn = entry.labels?.en || '';
  const groupCs = entry.groups?.cs || entry.group;
  const groupEn = entry.groups?.en || '';

  md.appendMarkdown('| | |\n| --- | --- |\n');
  md.appendMarkdown(`| **cs** | ${labelCs.replace(/\|/g, '\\|')} |\n`);
  if (labelEn) {
    md.appendMarkdown(`| **en** | ${labelEn.replace(/\|/g, '\\|')} |\n`);
  }
  md.appendMarkdown(`| **group** | ${groupCs}${groupEn && groupEn !== groupCs ? ` / ${groupEn}` : ''} |\n`);
  if (entry.module) {
    md.appendMarkdown(`| **module** | \`${entry.module}\` |\n`);
  }
  md.appendMarkdown(`\n\n\`${entry.file}:${entry.line}\``);
  md.appendMarkdown(`\n\n\`${entry.label}\` · \`${entry.group}\``);

  return md;
}

function locationFor(workspaceRoot, key) {
  const entry = entryFor(key);
  if (!entry?.file) {
    return null;
  }
  const abs = path.join(workspaceRoot, entry.file);
  if (!fs.existsSync(abs)) {
    return null;
  }
  const line = Math.max(0, (entry.line || 1) - 1);
  return new vscode.Location(vscode.Uri.file(abs), new vscode.Position(line, 0));
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
  const re = /(?:\.can|->can|->permission|permission\s*:)\s*\(?\s*['"]([a-zA-Z0-9][a-zA-Z0-9_.]*)['"]/g;
  let m;
  while ((m = re.exec(text))) {
    keys.add(m[1]);
  }

  const rel = vscode.workspace.asRelativePath(document.uri);
  lsp('textDocument/didOpen', rel);
  if (!keys.size) {
    lsp('  → perms', 'none');
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
  lsp('  → perms', `${keys.size} used · ${known} known · ${missing} missing`);
  for (const k of [...keys].slice(0, 10)) {
    const e = catalog[k];
    if (!e) {
      lsp('  →', `${k} ✗`);
      continue;
    }
    lsp('  →', `${k} · ${e.labels?.cs || e.label} · ${e.module || 'core'}`);
  }
}

function activate(context) {
  log = vscode.window.createOutputChannel('GEREQ Permissions');
  context.subscriptions.push(log);
  lsp('initialize', 'GEREQ Permissions');

  const root = () => vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
  const folders = vscode.workspace.workspaceFolders;
  if (folders) {
    loadIndex(folders[0].uri.fsPath);
  }

  const selector = [
    { pattern: '**/*.twig' },
    { language: 'php', pattern: '**/*.php' },
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
        entry ? `${hit.key} · ${entry.module || 'core'}` : `${hit.key} · missing`,
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
      const loc = locationFor(workspaceRoot, hit.key);
      lsp('textDocument/definition', `${hit.key} → ${loc ? 'ok' : 'miss'}`);
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
      const all = Object.keys(catalog);
      const matched = all
        .filter((k) => k.toLowerCase().startsWith(prefix) || k.toLowerCase().includes(prefix))
        .sort((a, b) => {
          const aS = a.toLowerCase().startsWith(prefix) ? 0 : 1;
          const bS = b.toLowerCase().startsWith(prefix) ? 0 : 1;
          if (aS !== bS) {
            return aS - bS;
          }
          return a.localeCompare(b);
        })
        .slice(0, 80);

      const now = Date.now();
      if (now - lastCompletionLog > 400) {
        lastCompletionLog = now;
        lsp('textDocument/completion', `"${ctx.prefix}" → ${matched.length}`);
      }

      return matched.map((key) => {
        const entry = catalog[key];
        const item = new vscode.CompletionItem(key, vscode.CompletionItemKind.Value);
        item.range = ctx.range;
        item.insertText = key;
        item.detail = entry.labels?.cs || entry.label;
        const doc = new vscode.MarkdownString(undefined, true);
        doc.appendMarkdown(`\`${key}\`\n\n`);
        if (entry.labels?.cs) {
          doc.appendMarkdown(`**cs:** ${entry.labels.cs}\n\n`);
        }
        if (entry.labels?.en) {
          doc.appendMarkdown(`**en:** ${entry.labels.en}\n\n`);
        }
        doc.appendMarkdown(`group: \`${entry.group}\``);
        if (entry.module) {
          doc.appendMarkdown(` · module: \`${entry.module}\``);
        }
        item.documentation = doc;
        item.sortText = key.toLowerCase().startsWith(prefix) ? `0${key}` : `1${key}`;
        return item;
      });
    },
  };

  context.subscriptions.push(
    vscode.languages.registerHoverProvider(selector, hover),
    vscode.languages.registerDefinitionProvider(selector, definition),
    vscode.languages.registerCompletionItemProvider(selector, completion, "'", '"', '.'),
    vscode.commands.registerCommand('gereq.permissions.reindex', async () => {
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
          `GEREQ Permissions: ${Object.keys(catalog).length} klíčů → .cache/permissions/`,
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

  const watcher = vscode.workspace.createFileSystemWatcher('**/module.php');
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
          uri ? path.relative(workspaceRoot, uri.fsPath) : 'module.php',
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
  context.subscriptions.push(watcher);

  lsp('initialized', 'hover · definition · completion · didOpen');
}

function deactivate() {}

module.exports = { activate, deactivate };
