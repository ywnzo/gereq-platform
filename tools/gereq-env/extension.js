const vscode = require('vscode');
const path = require('path');
const fs = require('fs');

/** @type {Map<string, {type:string, values:string[], default:string|null, secret:boolean, required:boolean, description:string}>} */
let schema = new Map();
/** @type {vscode.DiagnosticCollection} */
let diagnostics;
/** @type {string|null} */
let schemaAbs = null;

const TABLE_RE = /^\[([A-Z][A-Z0-9_]*)\]$/;
const KV_RE = /^([a-z_]+)\s*=\s*(.+)$/;
const STR_RE = /"((?:\\.|[^"\\])*)"|'((?:\\.|[^'\\])*)'/g;

function unquote(raw) {
  const s = raw.trim();
  if ((s.startsWith('"') && s.endsWith('"')) || (s.startsWith("'") && s.endsWith("'"))) {
    return s.slice(1, -1).replace(/\\([\\'"n])/g, (_, c) => (c === 'n' ? '\n' : c));
  }
  return s;
}

function parseDefault(raw) {
  const s = raw.trim();
  if (s === 'true' || s === 'false' || /^-?\d+$/.test(s)) {
    return s;
  }
  if (s === '""' || s === "''") {
    return '';
  }
  return unquote(s);
}

function parseBool(raw) {
  return ['true', '1', 'yes'].includes(unquote(raw).toLowerCase());
}

function parseValues(raw) {
  const s = raw.trim();
  if (!s.startsWith('[') || !s.endsWith(']')) {
    return [];
  }
  const inner = s.slice(1, -1).trim();
  if (!inner) {
    return [];
  }
  const out = [];
  STR_RE.lastIndex = 0;
  let m;
  while ((m = STR_RE.exec(inner))) {
    out.push(m[1] !== undefined ? m[1] : m[2] || '');
  }
  return out;
}

function parseSchema(text) {
  /** @type {Map<string, any>} */
  const vars = new Map();
  let current = null;
  for (const line of text.split(/\r?\n/)) {
    const trim = line.trim();
    if (!trim || trim.startsWith('#')) {
      continue;
    }
    const tm = TABLE_RE.exec(trim);
    if (tm) {
      current = tm[1];
      vars.set(current, {
        type: 'string',
        values: [],
        default: null,
        secret: false,
        required: false,
        description: '',
      });
      continue;
    }
    if (!current) {
      continue;
    }
    const km = KV_RE.exec(trim);
    if (!km) {
      continue;
    }
    const field = km[1];
    const raw = km[2].trim();
    const meta = vars.get(current);
    if (field === 'type' || field === 'description') {
      meta[field] = unquote(raw);
    } else if (field === 'default') {
      meta.default = parseDefault(raw);
    } else if (field === 'secret' || field === 'required') {
      meta[field] = parseBool(raw);
    } else if (field === 'values') {
      meta.values = parseValues(raw);
    }
  }
  return vars;
}

function workspaceRoot() {
  const folder = vscode.workspace.workspaceFolders?.[0];
  return folder ? folder.uri.fsPath : null;
}

function resolveSchemaPath() {
  const root = workspaceRoot();
  if (!root) {
    return null;
  }
  const rel = vscode.workspace.getConfiguration('gereqEnv').get('schemaPath', 'config/env.schema.toml');
  return path.isAbsolute(rel) ? rel : path.join(root, rel);
}

function loadSchema() {
  const file = resolveSchemaPath();
  schemaAbs = file;
  if (!file || !fs.existsSync(file)) {
    schema = new Map();
    return false;
  }
  schema = parseSchema(fs.readFileSync(file, 'utf8'));
  return true;
}

function metaMarkdown(key, meta) {
  const lines = [`**${key}**`, ''];
  lines.push(`- type: \`${meta.type}\``);
  if (meta.values?.length) {
    lines.push(`- values: ${meta.values.map((v) => `\`${v || '(empty)'}\``).join(', ')}`);
  }
  if (meta.default !== null && meta.default !== undefined) {
    lines.push(`- default: \`${meta.default === '' ? '(empty)' : meta.default}\``);
  }
  if (meta.required) {
    lines.push('- required: yes');
  }
  if (meta.secret) {
    lines.push('- secret: yes');
  }
  if (meta.description) {
    lines.push('', meta.description);
  }
  return lines.join('\n');
}

function isEnvDocument(doc) {
  if (!doc) {
    return false;
  }
  const base = path.basename(doc.uri.fsPath);
  return base === '.env' || /^\.env\./.test(base) || doc.languageId === 'dotenv';
}

function parseEnvLine(line) {
  const trim = line.trim();
  if (!trim || trim.startsWith('#') || !trim.includes('=')) {
    return null;
  }
  const i = trim.indexOf('=');
  return { key: trim.slice(0, i).trim(), value: trim.slice(i + 1).trim() };
}

function refreshDiagnostics(doc) {
  if (!isEnvDocument(doc) || schema.size === 0) {
    return;
  }
  /** @type {vscode.Diagnostic[]} */
  const diags = [];
  const seen = new Set();
  for (let i = 0; i < doc.lineCount; i++) {
    const text = doc.lineAt(i).text;
    const parsed = parseEnvLine(text);
    if (!parsed) {
      continue;
    }
    seen.add(parsed.key);
    const range = new vscode.Range(i, 0, i, Math.min(parsed.key.length, text.length));
    const meta = schema.get(parsed.key);
    if (!meta) {
      diags.push(new vscode.Diagnostic(range, `Unknown env key ${parsed.key}`, vscode.DiagnosticSeverity.Warning));
      continue;
    }
    const raw = parsed.value.replace(/^["']|["']$/g, '');
    if (meta.required && raw === '') {
      diags.push(new vscode.Diagnostic(range, `${parsed.key} is required`, vscode.DiagnosticSeverity.Error));
    }
    if (meta.type === 'enum' && meta.values.length && raw !== '' && !meta.values.includes(raw)) {
      diags.push(
        new vscode.Diagnostic(
          range,
          `${parsed.key} must be one of: ${meta.values.join(', ')}`,
          vscode.DiagnosticSeverity.Error,
        ),
      );
    }
    if (meta.type === 'bool' && raw !== '' && !['true', 'false', '1', '0', '(true)', '(false)'].includes(raw.toLowerCase())) {
      diags.push(new vscode.Diagnostic(range, `${parsed.key} must be bool`, vscode.DiagnosticSeverity.Error));
    }
    if (meta.type === 'int' && raw !== '' && !/^-?\d+$/.test(raw)) {
      diags.push(new vscode.Diagnostic(range, `${parsed.key} must be int`, vscode.DiagnosticSeverity.Error));
    }
  }
  for (const [key, meta] of schema) {
    if (meta.required && !seen.has(key)) {
      diags.push(
        new vscode.Diagnostic(
          new vscode.Range(0, 0, 0, 1),
          `Missing required key ${key}`,
          vscode.DiagnosticSeverity.Warning,
        ),
      );
    }
  }
  diagnostics.set(doc.uri, diags);
}

function envCompletionProvider() {
  return {
    provideCompletionItems(document, position) {
      if (!isEnvDocument(document) || schema.size === 0) {
        return [];
      }
      const line = document.lineAt(position.line).text;
      if (line.includes('=') && position.character > line.indexOf('=')) {
        const key = line.slice(0, line.indexOf('=')).trim();
        const meta = schema.get(key);
        if (!meta?.values?.length) {
          return [];
        }
        return meta.values.map((v) => {
          const item = new vscode.CompletionItem(v === '' ? '(empty)' : v, vscode.CompletionItemKind.EnumMember);
          item.insertText = v;
          return item;
        });
      }
      const prefix = line.slice(0, position.character).trim();
      /** @type {vscode.CompletionItem[]} */
      const items = [];
      for (const [key, meta] of schema) {
        if (prefix && !key.startsWith(prefix.toUpperCase()) && !key.includes(prefix.toUpperCase())) {
          continue;
        }
        const item = new vscode.CompletionItem(key, vscode.CompletionItemKind.Variable);
        item.detail = meta.type + (meta.secret ? ' · secret' : '');
        item.documentation = new vscode.MarkdownString(metaMarkdown(key, meta));
        const def = meta.default === null || meta.default === undefined ? '' : String(meta.default);
        item.insertText = new vscode.SnippetString(`${key}=\${1:${def.replace(/\$/g, '\\$')}}`);
        items.push(item);
      }
      return items;
    },
  };
}

function phpCompletionProvider() {
  return {
    provideCompletionItems(document, position) {
      if (schema.size === 0) {
        return [];
      }
      const line = document.lineAt(position.line).text.slice(0, position.character);
      const m = line.match(/env_value\s*\(\s*(['"])([A-Z0-9_]*)$/);
      if (!m) {
        return [];
      }
      const quote = m[1];
      const typed = m[2];
      /** @type {vscode.CompletionItem[]} */
      const items = [];
      for (const [key, meta] of schema) {
        if (typed && !key.startsWith(typed)) {
          continue;
        }
        const item = new vscode.CompletionItem(key, vscode.CompletionItemKind.Constant);
        item.detail = meta.type;
        item.documentation = new vscode.MarkdownString(metaMarkdown(key, meta));
        item.range = new vscode.Range(
          position.line,
          position.character - typed.length,
          position.line,
          position.character,
        );
        item.insertText = key;
        // keep surrounding quotes from the user
        void quote;
        items.push(item);
      }
      return items;
    },
  };
}

function hoverProvider(selectorCheck) {
  return {
    provideHover(document, position) {
      if (schema.size === 0) {
        return null;
      }
      if (selectorCheck === 'env' && !isEnvDocument(document)) {
        return null;
      }
      const wordRange = document.getWordRangeAtPosition(position, /[A-Z][A-Z0-9_]*/);
      if (!wordRange) {
        return null;
      }
      const key = document.getText(wordRange);
      const meta = schema.get(key);
      if (!meta) {
        return null;
      }
      if (selectorCheck === 'php') {
        const line = document.lineAt(position.line).text;
        if (!/env_value\s*\(/.test(line) && !/\$_(?:ENV|SERVER)\s*\[/.test(line)) {
          return null;
        }
      }
      return new vscode.Hover(new vscode.MarkdownString(metaMarkdown(key, meta)), wordRange);
    },
  };
}

/**
 * @param {vscode.ExtensionContext} context
 */
function activate(context) {
  diagnostics = vscode.languages.createDiagnosticCollection('gereq-env');
  loadSchema();

  const reload = () => {
    const ok = loadSchema();
    vscode.window.showInformationMessage(
      ok ? `GEREQ Env: loaded ${schema.size} keys` : 'GEREQ Env: schema not found',
    );
    for (const doc of vscode.workspace.textDocuments) {
      if (isEnvDocument(doc)) {
        refreshDiagnostics(doc);
      }
    }
  };

  context.subscriptions.push(
    diagnostics,
    vscode.commands.registerCommand('gereq.env.reload', reload),
    vscode.commands.registerCommand('gereq.env.validate', async () => {
      const root = workspaceRoot();
      if (!root) {
        return;
      }
      const envFile = path.join(root, '.env');
      if (!fs.existsSync(envFile)) {
        vscode.window.showWarningMessage('No .env in workspace');
        return;
      }
      const doc = await vscode.workspace.openTextDocument(envFile);
      refreshDiagnostics(doc);
      const diags = diagnostics.get(doc.uri) || [];
      if (diags.length === 0) {
        vscode.window.showInformationMessage('GEREQ Env: .env OK');
      } else {
        vscode.window.showWarningMessage(`GEREQ Env: ${diags.length} issue(s) — see Problems`);
      }
    }),
    vscode.languages.registerCompletionItemProvider({ pattern: '**/.env*' }, envCompletionProvider()),
    vscode.languages.registerCompletionItemProvider({ language: 'php' }, phpCompletionProvider(), "'", '"'),
    vscode.languages.registerHoverProvider({ pattern: '**/.env*' }, hoverProvider('env')),
    vscode.languages.registerHoverProvider({ language: 'php' }, hoverProvider('php')),
    vscode.workspace.onDidChangeTextDocument((e) => {
      if (isEnvDocument(e.document)) {
        refreshDiagnostics(e.document);
      }
    }),
    vscode.workspace.onDidOpenTextDocument((doc) => {
      if (isEnvDocument(doc)) {
        refreshDiagnostics(doc);
      }
    }),
    vscode.workspace.onDidSaveTextDocument((doc) => {
      if (schemaAbs && doc.uri.fsPath === schemaAbs) {
        loadSchema();
      }
      if (isEnvDocument(doc)) {
        refreshDiagnostics(doc);
      }
    }),
  );

  for (const doc of vscode.workspace.textDocuments) {
    if (isEnvDocument(doc)) {
      refreshDiagnostics(doc);
    }
  }
}

function deactivate() {
  schema.clear();
}

module.exports = { activate, deactivate };
