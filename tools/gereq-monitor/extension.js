const path = require('path');
const { createServer } = require('./lib/server');
const { Store } = require('./lib/store');
const { classifyEdit } = require('./lib/diff');

/** @type {{ appendLine: Function }} */
let log = { appendLine() {} };
/** @type {Store | null} */
let store = null;
/** @type {{ broadcast: Function, close: Function } | null} */
let server = null;
/** @type {Map<string, string>} */
const docSnapshots = new Map();
/** @type {Map<string, number>} */
const focusStarted = new Map();
let sessionId = '';

function nowIso() {
  return new Date().toISOString();
}

function rel(root, uriPath) {
  const r = path.relative(root, uriPath).replace(/\\/g, '/');
  if (r.startsWith('..') || path.isAbsolute(r)) {
    return uriPath;
  }
  return r;
}

function shouldIgnore(relPath) {
  if (!relPath || relPath.startsWith('..')) {
    return true;
  }
  const parts = relPath.split('/');
  const skip = new Set([
    'node_modules', 'vendor', '.git', 'dist', '.cache', 'storage',
    '.phpunit.cache', '.idea', '.cursor',
  ]);
  return parts.some((p) => skip.has(p));
}

function lineDiffStats(before, after) {
  if (before === after) {
    return { added: 0, removed: 0 };
  }
  const a = before.split('\n');
  const b = after.split('\n');
  if (a.length === b.length) {
    let changed = 0;
    for (let i = 0; i < a.length; i++) {
      if (a[i] !== b[i]) {
        changed++;
      }
    }
    return { added: changed, removed: changed };
  }
  if (b.length > a.length) {
    return { added: b.length - a.length, removed: 0 };
  }
  return { added: 0, removed: a.length - b.length };
}

function activate(context) {
  const vscode = require('vscode');
  log = vscode.window.createOutputChannel('GEREQ Pulse');
  context.subscriptions.push(log);
  log.appendLine(`[${nowIso()}] activate`);

  const root = () => vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
  const workspaceRoot = root();
  if (!workspaceRoot) {
    log.appendLine('no workspace');
    return;
  }

  sessionId = `s-${Date.now().toString(36)}`;
  const dataDir = path.join(workspaceRoot, '.cache', 'worklog');
  store = new Store(dataDir, log);
  store.append({
    type: 'session_start',
    ts: nowIso(),
    session: sessionId,
    workspace: workspaceRoot,
  });

  const push = (event) => {
    store.append(event);
    if (server) {
      server.broadcast(event);
    }
  };

  try {
    server = createServer({
      port: 8772,
      store,
      uiDir: path.join(__dirname, 'ui', 'dist'),
      workspaceRoot,
      log,
    });
    log.appendLine(`[${nowIso()}] UI http://127.0.0.1:8772`);
  } catch (e) {
    log.appendLine(`server failed: ${e}`);
    vscode.window.showWarningMessage(`GEREQ Pulse: server :8772 — ${e}`);
  }

  const trackOpen = (document) => {
    if (document.uri.scheme !== 'file') {
      return;
    }
    const file = rel(workspaceRoot, document.uri.fsPath);
    if (shouldIgnore(file)) {
      return;
    }
    docSnapshots.set(document.uri.fsPath, document.getText());
    push({
      type: 'open',
      ts: nowIso(),
      session: sessionId,
      file,
      language: document.languageId,
      lines: document.lineCount,
    });
  };

  context.subscriptions.push(
    vscode.workspace.onDidOpenTextDocument((doc) => trackOpen(doc)),
    vscode.workspace.onDidCloseTextDocument((doc) => {
      if (doc.uri.scheme !== 'file') {
        return;
      }
      const file = rel(workspaceRoot, doc.uri.fsPath);
      if (shouldIgnore(file)) {
        return;
      }
      docSnapshots.delete(doc.uri.fsPath);
      push({ type: 'close', ts: nowIso(), session: sessionId, file });
    }),
    vscode.workspace.onDidSaveTextDocument((doc) => {
      if (doc.uri.scheme !== 'file') {
        return;
      }
      const file = rel(workspaceRoot, doc.uri.fsPath);
      if (shouldIgnore(file)) {
        return;
      }
      push({
        type: 'save',
        ts: nowIso(),
        session: sessionId,
        file,
        lines: doc.lineCount,
      });
    }),
    vscode.workspace.onDidChangeTextDocument((e) => {
      const doc = e.document;
      if (doc.uri.scheme !== 'file' || e.contentChanges.length === 0) {
        return;
      }
      const file = rel(workspaceRoot, doc.uri.fsPath);
      if (shouldIgnore(file)) {
        return;
      }

      let chars = 0;
      for (const ch of e.contentChanges) {
        chars += ch.text.length + (ch.rangeLength || 0);
      }

      const prev = docSnapshots.get(doc.uri.fsPath) ?? '';
      const nextText = doc.getText();
      const { added: linesAdded, removed: linesRemoved } = lineDiffStats(prev, nextText);
      docSnapshots.set(doc.uri.fsPath, nextText);

      if (linesAdded === 0 && linesRemoved === 0 && chars < 2) {
        return;
      }

      const source = classifyEdit({
        linesAdded,
        linesRemoved,
        changeCount: e.contentChanges.length,
        totalChars: chars,
        reason: e.reason,
      });

      push({
        type: 'edit',
        ts: nowIso(),
        session: sessionId,
        file,
        linesAdded,
        linesRemoved,
        chars,
        source,
        language: doc.languageId,
      });
    }),
    vscode.window.onDidChangeActiveTextEditor((editor) => {
      const now = Date.now();
      for (const [file, start] of focusStarted) {
        const sec = Math.round((now - start) / 1000);
        if (sec >= 2) {
          push({
            type: 'focus',
            ts: nowIso(),
            session: sessionId,
            file,
            seconds: sec,
          });
        }
        focusStarted.delete(file);
      }
      if (editor?.document?.uri?.scheme === 'file') {
        const file = rel(workspaceRoot, editor.document.uri.fsPath);
        if (!shouldIgnore(file)) {
          focusStarted.set(file, now);
        }
      }
    }),
    vscode.workspace.onDidCreateFiles((e) => {
      for (const f of e.files) {
        const file = rel(workspaceRoot, f.fsPath);
        if (!shouldIgnore(file)) {
          push({ type: 'create', ts: nowIso(), session: sessionId, file });
        }
      }
    }),
    vscode.workspace.onDidDeleteFiles((e) => {
      for (const f of e.files) {
        const file = rel(workspaceRoot, f.fsPath);
        if (!shouldIgnore(file)) {
          push({ type: 'delete', ts: nowIso(), session: sessionId, file });
        }
      }
    }),
    vscode.workspace.onDidRenameFiles((e) => {
      for (const f of e.files) {
        push({
          type: 'rename',
          ts: nowIso(),
          session: sessionId,
          file: rel(workspaceRoot, f.newUri.fsPath),
          from: rel(workspaceRoot, f.oldUri.fsPath),
        });
      }
    }),
  );

  for (const doc of vscode.workspace.textDocuments) {
    trackOpen(doc);
  }

  const gitTimer = setInterval(() => {
    try {
      const { execFileSync } = require('child_process');
      const shortstat = execFileSync('git', ['diff', '--shortstat', 'HEAD'], {
        cwd: workspaceRoot,
        timeout: 5000,
        encoding: 'utf8',
      }).trim();
      const porcelain = execFileSync('git', ['status', '--porcelain'], {
        cwd: workspaceRoot,
        timeout: 5000,
        encoding: 'utf8',
      }).trim();
      push({
        type: 'git',
        ts: nowIso(),
        session: sessionId,
        shortstat: shortstat || 'clean',
        dirtyFiles: porcelain ? porcelain.split('\n').length : 0,
      });
    } catch {
      /* not git */
    }
  }, 60000);
  context.subscriptions.push({ dispose: () => clearInterval(gitTimer) });

  context.subscriptions.push(
    vscode.commands.registerCommand('gereq.pulse.open', () => {
      vscode.env.openExternal(vscode.Uri.parse('http://127.0.0.1:8772'));
    }),
    vscode.commands.registerCommand('gereq.pulse.reset', () => {
      store.resetToday();
      vscode.window.showInformationMessage('GEREQ Pulse: dnešní log vymazán');
      if (server) {
        server.broadcast({ type: 'reset', ts: nowIso() });
      }
    }),
  );

  const status = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Right, 100);
  status.text = '$(heart) Pulse';
  status.tooltip = 'GEREQ Pulse — dashboard :8772';
  status.command = 'gereq.pulse.open';
  status.show();
  context.subscriptions.push(status);

  log.appendLine(`[${nowIso()}] initialized session=${sessionId}`);
}

function deactivate() {
  if (store) {
    store.append({ type: 'session_end', ts: nowIso(), session: sessionId });
  }
  if (server) {
    server.close();
    server = null;
  }
}

module.exports = { activate, deactivate };
