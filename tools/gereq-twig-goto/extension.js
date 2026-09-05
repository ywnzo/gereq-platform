const vscode = require('vscode');
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');

const TWIG_TO_RUNTIME = {
  url: 'url',
  csrf_field: 'csrfField',
  csrf_token: 'csrfToken',
  asset: 'asset',
  icon: 'icon',
  current_user: 'currentUser',
  route_is: 'routeIs',
  flash: 'flash',
  csp_nonce: 'cspNonce',
  old: 'old',
  error: 'error',
  errors: 'errors',
  has_error: 'hasError',
  form_errors: 'formErrors',
  do_action: 'doAction',
  apply_filters: 'applyFilters',
};

const SCALAR = new Set([
  'string', 'int', 'float', 'bool', 'boolean', 'array', 'mixed', 'null',
  'void', 'iterable', 'callable', 'object', 'number', 'list', 'dict',
]);

/** @type {Record<string, Record<string, { class: string, array: boolean }>>} */
let templateIndex = {};
/** @type {Record<string, { file?: string|null, methods: Array<{name:string,return:string,params:string[]}> }>} */
let classIndex = {};
/** @type {Record<string, string>} */
let psr4 = {};
/** @type {{ appendLine: Function }} */
let log = { appendLine() {} };

function cachePath(root) {
  const primary = path.join(root, '.cache', 'twig', 'types.json');
  if (fs.existsSync(primary)) {
    return primary;
  }
  return path.join(root, '.cache', 'twig-goto-types.json');
}

function loadPsr4(workspaceRoot) {
  try {
    const composer = JSON.parse(
      fs.readFileSync(path.join(workspaceRoot, 'composer.json'), 'utf8'),
    );
    const map = {
      ...(composer.autoload?.['psr-4'] || {}),
      ...(composer['autoload-dev']?.['psr-4'] || {}),
    };
    const out = {};
    for (const [prefix, dirs] of Object.entries(map)) {
      const list = Array.isArray(dirs) ? dirs : [dirs];
      out[prefix] = path.join(workspaceRoot, list[0]);
    }
    psr4 = out;
  } catch (e) {
    log.appendLine(`psr-4: ${e}`);
    psr4 = {};
  }
}

function rebuildIndex(workspaceRoot) {
  const indexer = path.join(workspaceRoot, 'tools', 'gereq-twig-goto', 'index-templates.php');
  if (!fs.existsSync(indexer)) {
    return;
  }
  execFileSync('php', [indexer], { cwd: workspaceRoot, timeout: 120000 });
}

function loadIndex(workspaceRoot) {
  const file = cachePath(workspaceRoot);
  if (!fs.existsSync(file)) {
    try {
      rebuildIndex(workspaceRoot);
    } catch (e) {
      log.appendLine(`rebuild: ${e}`);
    }
  }
  if (!fs.existsSync(file)) {
    templateIndex = {};
    classIndex = {};
    return;
  }
  try {
    const raw = JSON.parse(fs.readFileSync(file, 'utf8'));
    templateIndex = raw.templates || {};
    classIndex = raw.classes || {};
    log.appendLine(
      `index: ${Object.keys(templateIndex).length} templates, ${Object.keys(classIndex).length} classes`,
    );
  } catch (e) {
    log.appendLine(`read index: ${e}`);
    templateIndex = {};
    classIndex = {};
  }
}

function parseTypeString(raw) {
  let t = raw.trim().replace(/^['"]|['"]$/g, '');
  t = t.replace(/^\\+/, '').replace(/\\\\/g, '\\');
  let isArray = false;
  if (t.endsWith('[]')) {
    t = t.slice(0, -2);
    isArray = true;
  }
  return { class: t, array: isArray };
}

function typesFromDocument(document) {
  const types = {};
  const text = document.getText();
  const twiggyVar = /\{#\s*@var\s+([A-Za-z_][A-Za-z0-9_]*)\s+(\S+)\s*#\}/g;
  let m;
  while ((m = twiggyVar.exec(text))) {
    types[m[1]] = parseTypeString(m[2]);
  }
  return types;
}

function varsForDocument(document, workspaceRoot) {
  const rel = path.relative(workspaceRoot, document.uri.fsPath).replace(/\\/g, '/');
  const merged = { ...(templateIndex[rel] || {}), ...typesFromDocument(document) };

  // for-loop inference (když index ještě nemá item)
  const text = document.getText();
  const forRe = /\{%\s*for\s+([A-Za-z_][A-Za-z0-9_]*)\s+in\s+([A-Za-z_][A-Za-z0-9_]*)/g;
  let fm;
  while ((fm = forRe.exec(text))) {
    const [, loopVar, collection] = fm;
    if (!merged[loopVar] && merged[collection]) {
      merged[loopVar] = { class: merged[collection].class, array: false };
    }
  }
  return merged;
}

/**
 * @returns {{ class: string, array: boolean } | null}
 */
function resolveVarType(document, position, varName, vars) {
  const text = document.getText();
  const offset = document.offsetAt(position);
  const forRe = new RegExp(
    `\\{%\\s*for\\s+${varName}\\s+in\\s+([A-Za-z_][A-Za-z0-9_]*)`,
    'g',
  );
  let collection = null;
  let m;
  while ((m = forRe.exec(text))) {
    if (m.index < offset) {
      collection = m[1];
    }
  }
  if (collection && vars[collection]) {
    const col = vars[collection];
    /** @type {{ class: string, array: boolean, shape?: Record<string,string> }} */
    const out = { class: col.class, array: false };
    if (col.shape) {
      out.shape = col.shape;
      if (col.class === 'array' || String(col.class).startsWith('array{')) {
        out.class = 'array';
      }
    }
    return out;
  }
  if (vars[varName]) {
    return {
      class: vars[varName].class,
      array: !!vars[varName].array,
      ...(vars[varName].shape ? { shape: vars[varName].shape } : {}),
    };
  }
  return null;
}

function findClassFile(workspaceRoot, fqcn) {
  const clean = fqcn.replace(/^\\+/, '').replace(/\\\\/g, '\\');
  if (!clean || SCALAR.has(clean.toLowerCase())) {
    return null;
  }
  if (classIndex[clean]?.file && fs.existsSync(classIndex[clean].file)) {
    return classIndex[clean].file;
  }
  let best = null;
  for (const [prefix, dir] of Object.entries(psr4)) {
    if (clean === prefix.replace(/\\$/, '') || clean.startsWith(prefix)) {
      if (!best || prefix.length > best.prefix.length) {
        best = { prefix, dir };
      }
    }
  }
  if (best) {
    const full = path.join(best.dir, clean.slice(best.prefix.length).replace(/\\/g, '/') + '.php');
    if (fs.existsSync(full)) {
      return full;
    }
  }
  return null;
}

function methodsOf(fqcn) {
  const clean = fqcn.replace(/^\\+/, '');
  return classIndex[clean]?.methods || [];
}

function shapeKeys(type) {
  if (!type?.shape || typeof type.shape !== 'object') {
    return [];
  }
  return Object.entries(type.shape).map(([name, ret]) => ({ name, return: ret }));
}

function isShapeType(type) {
  return !!(type && type.shape && (type.class === 'array' || String(type.class).startsWith('array{')));
}

function findMethodPosition(source, method) {
  if (!method) {
    return null;
  }
  const getter = `get${method[0].toUpperCase()}${method.slice(1)}`;
  for (const name of [method, getter]) {
    const re = new RegExp(`function\\s+${name}\\s*\\(`);
    const m = re.exec(source);
    if (!m) {
      continue;
    }
    const before = source.slice(0, m.index);
    const line = before.split('\n').length - 1;
    const lineStart = before.lastIndexOf('\n') + 1;
    const character = m.index - lineStart + m[0].indexOf(name);
    return { line, character };
  }
  return null;
}

function locationFor(workspaceRoot, fqcn, methodName) {
  const classFile = findClassFile(workspaceRoot, fqcn);
  if (!classFile) {
    return null;
  }
  const source = fs.readFileSync(classFile, 'utf8');
  if (methodName) {
    const pos = findMethodPosition(source, methodName);
    if (pos) {
      return new vscode.Location(
        vscode.Uri.file(classFile),
        new vscode.Position(pos.line, pos.character),
      );
    }
  }
  const classMatch = /class\s+(\w+)/.exec(source);
  if (classMatch) {
    const beforeCls = source.slice(0, classMatch.index);
    const lineNo = beforeCls.split('\n').length - 1;
    const lineStart = beforeCls.lastIndexOf('\n') + 1;
    const character = classMatch.index - lineStart + classMatch[0].indexOf(classMatch[1]);
    return new vscode.Location(
      vscode.Uri.file(classFile),
      new vscode.Position(lineNo, character),
    );
  }
  return new vscode.Location(vscode.Uri.file(classFile), new vscode.Position(0, 0));
}

function shortClass(fqcn) {
  const clean = fqcn.replace(/^\\+/, '');
  const parts = clean.split('\\');
  return parts[parts.length - 1] || clean;
}

function fqcnLabel(fqcn) {
  return '\\' + fqcn.replace(/^\\+/, '');
}

/**
 * @param {string} workspaceRoot
 * @param {string} fqcn
 * @param {string | null} methodName
 * @returns {string | null}
 */
function symbolLink(workspaceRoot, fqcn, methodName) {
  const loc = locationFor(workspaceRoot, fqcn, methodName);
  if (!loc) {
    return null;
  }
  return loc.uri.with({ fragment: `L${loc.range.start.line + 1}` }).toString();
}

/**
 * @param {string} workspaceRoot
 * @param {string} fqcn
 * @param {{name:string,return:string,params:string[]}} method
 */
function hoverForMethod(workspaceRoot, fqcn, method) {
  const short = shortClass(fqcn);
  const fq = fqcnLabel(fqcn);
  const params = method.params.join(', ');
  const ret = method.return || 'mixed';
  const md = new vscode.MarkdownString(undefined, true);
  md.isTrusted = true;
  md.supportHtml = true;

  md.appendMarkdown(`### $(symbol-method) \`${method.name}()\``);
  md.appendMarkdown('\n\n');
  md.appendCodeblock(`${short}::${method.name}(${params}): ${ret}`, 'php');

  const methodUrl = symbolLink(workspaceRoot, fqcn, method.name);
  const classUrl = symbolLink(workspaceRoot, fqcn, null);
  md.appendMarkdown('\n\n');
  if (methodUrl) {
    md.appendMarkdown(`[$(link-external) Přejít na definici](${methodUrl})`);
  }
  if (classUrl) {
    md.appendMarkdown(`&nbsp;&nbsp;·&nbsp;&nbsp;[$(symbol-class) ${short}](${classUrl})`);
  }
  md.appendMarkdown(`\n\n\`${fq}\``);

  return md;
}

/**
 * @param {string} workspaceRoot
 * @param {string} varName
 * @param {{ class: string, array: boolean }} type
 */
function hoverForVariable(workspaceRoot, varName, type) {
  const md = new vscode.MarkdownString(undefined, true);
  md.isTrusted = true;
  md.supportHtml = true;

  md.appendMarkdown(`### $(symbol-variable) \`${varName}\``);
  md.appendMarkdown('\n\n');

  if (isShapeType(type)) {
    const keys = Object.keys(type.shape || {});
    const label = type.array
      ? `array{${keys.join(', ')}}[]`
      : `array{${keys.join(', ')}}`;
    md.appendCodeblock(`${varName}: ${label}`, 'php');
    md.appendMarkdown('\n\n**Klíče**\n\n');
    for (const [k, v] of Object.entries(type.shape || {})) {
      md.appendMarkdown(`- $(symbol-field) \`${k}\` → \`${v}\`\n`);
    }
    return md;
  }

  if (SCALAR.has(type.class.toLowerCase())) {
    md.appendCodeblock(`${varName}: ${type.class}`, 'php');
    return md;
  }

  const fq = fqcnLabel(type.class);
  const short = shortClass(type.class);
  const shown = type.array ? `${short}[]` : short;
  md.appendCodeblock(`${varName}: ${shown}`, 'php');

  const classUrl = symbolLink(workspaceRoot, type.class, null);
  md.appendMarkdown('\n\n');
  if (classUrl) {
    md.appendMarkdown(`[$(symbol-class) ${fq}${type.array ? '[]' : ''}](${classUrl})`);
  } else {
    md.appendMarkdown(`\`${fq}${type.array ? '[]' : ''}\``);
  }

  const methods = methodsOf(type.class).filter((m) => !m.name.startsWith('set'));
  if (methods.length) {
    md.appendMarkdown('\n\n---\n\n**Metody**\n\n');
    for (const m of methods.slice(0, 10)) {
      const url = symbolLink(workspaceRoot, type.class, m.name);
      const ret = m.return || 'mixed';
      if (url) {
        md.appendMarkdown(`- [$(symbol-method) \`${m.name}()\`](${url}) → \`${ret}\`\n`);
      } else {
        md.appendMarkdown(`- \`${m.name}()\` → \`${ret}\`\n`);
      }
    }
    if (methods.length > 10) {
      md.appendMarkdown(`\n_${methods.length - 10} dalších…_`);
    }
  }

  return md;
}

function twigFunctionAt(document, position) {
  const line = document.lineAt(position.line).text;
  const wordRange = document.getWordRangeAtPosition(position, /[A-Za-z_][A-Za-z0-9_]*/);
  if (!wordRange) {
    return null;
  }
  const word = document.getText(wordRange);
  if (!Object.prototype.hasOwnProperty.call(TWIG_TO_RUNTIME, word)) {
    return null;
  }
  if (!/^\s*\(/.test(line.slice(wordRange.end.character))) {
    return null;
  }
  if (/\.\s*$/.test(line.slice(0, wordRange.start.character))) {
    return null;
  }
  return { word, wordRange };
}

/**
 * Kontext: member podle wordRange (hover na metodě za tečkou).
 */
function cursorContext(document, position) {
  const line = document.lineAt(position.line).text;
  const wordRange = document.getWordRangeAtPosition(position, /[A-Za-z_][A-Za-z0-9_]*/);
  if (!wordRange) {
    return null;
  }

  const word = document.getText(wordRange);
  const before = line.slice(0, wordRange.start.character);

  if (/[A-Za-z_][A-Za-z0-9_]*\s*\.\s*$/.test(before)) {
    const obj = before.match(/([A-Za-z_][A-Za-z0-9_]*)\s*\.\s*$/);
    return { kind: 'member', object: obj[1], member: word, wordRange };
  }

  return { kind: 'variable', name: word, wordRange };
}

function activate(context) {
  log = vscode.window.createOutputChannel('GEREQ Twig Goto');
  context.subscriptions.push(log);
  log.appendLine('activate');

  const root = () => vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;

  const folders = vscode.workspace.workspaceFolders;
  if (folders) {
    loadPsr4(folders[0].uri.fsPath);
    loadIndex(folders[0].uri.fsPath);
  }

  const definition = {
    provideDefinition(document, position) {
      if (!document.fileName.endsWith('.twig')) {
        return null;
      }
      const workspaceRoot = root();
      if (!workspaceRoot) {
        return null;
      }

      const fn = twigFunctionAt(document, position);
      if (fn) {
        const method = TWIG_TO_RUNTIME[fn.word];
        const runtimeUri = vscode.Uri.joinPath(
          vscode.workspace.workspaceFolders[0].uri,
          'src',
          'View',
          'TwigRuntime.php',
        );
        if (!fs.existsSync(runtimeUri.fsPath)) {
          return null;
        }
        const source = fs.readFileSync(runtimeUri.fsPath, 'utf8');
        const pos = findMethodPosition(source, method);
        return pos
          ? new vscode.Location(runtimeUri, new vscode.Position(pos.line, pos.character))
          : null;
      }

      const vars = varsForDocument(document, workspaceRoot);
      const ctx = cursorContext(document, position);
      if (!ctx) {
        return null;
      }

      if (ctx.kind === 'member') {
        const t = resolveVarType(document, position, ctx.object, vars);
        if (!t || SCALAR.has(t.class.toLowerCase())) {
          return null;
        }
        return locationFor(workspaceRoot, t.class, ctx.member || null);
      }

      const t = resolveVarType(document, position, ctx.name, vars);
      if (!t || SCALAR.has(t.class.toLowerCase())) {
        return null;
      }
      return locationFor(workspaceRoot, t.class, null);
    },
  };

  const hover = {
    provideHover(document, position) {
      if (!document.fileName.endsWith('.twig')) {
        return null;
      }
      const workspaceRoot = root();
      if (!workspaceRoot) {
        return null;
      }
      const vars = varsForDocument(document, workspaceRoot);
      const ctx = cursorContext(document, position);
      if (!ctx) {
        return null;
      }

      if (ctx.kind === 'member') {
        const t = resolveVarType(document, position, ctx.object, vars);
        if (!t) {
          return null;
        }
        if (isShapeType(t)) {
          const key = (t.shape && t.shape[ctx.member]) || null;
          if (!key) {
            const md = new vscode.MarkdownString(undefined, true);
            md.appendMarkdown(`Neznámý klíč \`${ctx.member}\` v poli`);
            return new vscode.Hover(md, ctx.wordRange);
          }
          const md = new vscode.MarkdownString(undefined, true);
          md.isTrusted = true;
          md.appendMarkdown(`### $(symbol-field) \`${ctx.member}\``);
          md.appendMarkdown('\n\n');
          md.appendCodeblock(`${ctx.object}.${ctx.member}: ${key}`, 'php');
          md.appendMarkdown('\n\n_array shape_');
          return new vscode.Hover(md, ctx.wordRange);
        }
        if (SCALAR.has(t.class.toLowerCase())) {
          return null;
        }
        const method = methodsOf(t.class).find((x) => x.name === ctx.member);
        if (!method) {
          const md = new vscode.MarkdownString(undefined, true);
          md.appendMarkdown(`Neznámá metoda \`${ctx.member}\` na \`${shortClass(t.class)}\``);
          return new vscode.Hover(md, ctx.wordRange);
        }
        return new vscode.Hover(hoverForMethod(workspaceRoot, t.class, method), ctx.wordRange);
      }

      const t = resolveVarType(document, position, ctx.name, vars);
      if (!t) {
        return null;
      }
      return new vscode.Hover(hoverForVariable(workspaceRoot, ctx.name, t), ctx.wordRange);
    },
  };

  const completion = {
    provideCompletionItems(document, position) {
      if (!document.fileName.endsWith('.twig')) {
        return null;
      }
      const workspaceRoot = root();
      if (!workspaceRoot) {
        return null;
      }
      const line = document.lineAt(position.line).text;
      const before = line.slice(0, position.character);
      const m = before.match(/([A-Za-z_][A-Za-z0-9_]*)\s*\.\s*([A-Za-z_][A-Za-z0-9_]*)?$/);
      if (!m) {
        return null;
      }
      const vars = varsForDocument(document, workspaceRoot);
      const t = resolveVarType(document, position, m[1], vars);
      if (!t || t.array) {
        return null;
      }
      const prefix = m[2] || '';

      if (isShapeType(t)) {
        return shapeKeys(t)
          .filter((x) => x.name.toLowerCase().startsWith(prefix.toLowerCase()))
          .map((x) => {
            const item = new vscode.CompletionItem(x.name, vscode.CompletionItemKind.Field);
            item.detail = x.return || 'mixed';
            item.insertText = x.name;
            item.documentation = new vscode.MarkdownString(
              '```php\n' + `${m[1]}.${x.name}: ${x.return}` + '\n```',
            );
            return item;
          });
      }

      if (SCALAR.has(t.class.toLowerCase())) {
        return null;
      }

      return methodsOf(t.class)
        .filter((x) => x.name.toLowerCase().startsWith(prefix.toLowerCase()))
        .map((x) => {
          const item = new vscode.CompletionItem(x.name, vscode.CompletionItemKind.Method);
          item.detail = `${x.return || 'mixed'}`;
          item.insertText = new vscode.SnippetString(
            x.params.length ? `${x.name}($0)` : `${x.name}()`,
          );
          const doc = new vscode.MarkdownString(undefined, true);
          doc.isTrusted = true;
          const url = symbolLink(workspaceRoot, t.class, x.name);
          doc.appendCodeblock(
            `${shortClass(t.class)}::${x.name}(${x.params.join(', ')}): ${x.return || 'mixed'}`,
            'php',
          );
          if (url) {
            doc.appendMarkdown(`\n\n[$(link-external) Přejít na definici](${url})`);
          }
          item.documentation = doc;
          return item;
        });
    },
  };

  const selector = { pattern: '**/*.twig' };
  context.subscriptions.push(
    vscode.languages.registerDefinitionProvider(selector, definition),
    vscode.languages.registerHoverProvider(selector, hover),
    vscode.languages.registerCompletionItemProvider(selector, completion, '.'),
    vscode.commands.registerCommand('gereq.twigGoto.reindex', async () => {
      const workspaceRoot = root();
      if (!workspaceRoot) {
        return;
      }
      try {
        rebuildIndex(workspaceRoot);
        loadIndex(workspaceRoot);
        vscode.window.showInformationMessage(
          `GEREQ Twig: ${Object.keys(templateIndex).length} šablon → .cache/twig/`,
        );
      } catch (e) {
        vscode.window.showErrorMessage(`Reindex selhal: ${e}`);
      }
    }),
  );

  // sleduj controllery → soft reload cache (bez zápisu do šablon)
  const watcher = vscode.workspace.createFileSystemWatcher('**/*Controller.php');
  const reload = () => {
    const workspaceRoot = root();
    if (!workspaceRoot) {
      return;
    }
    try {
      rebuildIndex(workspaceRoot);
      loadIndex(workspaceRoot);
    } catch (e) {
      log.appendLine(`watch rebuild: ${e}`);
    }
  };
  watcher.onDidChange(reload);
  watcher.onDidCreate(reload);
  context.subscriptions.push(watcher);
}

function deactivate() {}

module.exports = { activate, deactivate };
