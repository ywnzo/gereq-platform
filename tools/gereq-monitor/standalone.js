#!/usr/bin/env node
/**
 * Standalone Pulse server (bez VS Code) — pro test UI.
 * Usage: node tools/gereq-monitor/standalone.js
 */
const path = require('path');
const { Store } = require('./lib/store');
const { createServer } = require('./lib/server');

const workspaceRoot = path.resolve(__dirname, '../..');
const log = { appendLine: (m) => console.log(m) };
const store = new Store(path.join(workspaceRoot, '.cache', 'worklog'), log);

// demo event pokud prázdné
if (store.readEvents({ limit: 1 }).length === 0) {
  store.append({
    type: 'session_start',
    ts: new Date().toISOString(),
    session: 'standalone',
    workspace: workspaceRoot,
  });
  store.append({
    type: 'edit',
    ts: new Date().toISOString(),
    file: 'README.md',
    linesAdded: 3,
    linesRemoved: 1,
    chars: 120,
    source: 'user',
    language: 'markdown',
  });
}

createServer({
  port: 8772,
  store,
  uiDir: path.join(__dirname, 'ui', 'dist'),
  workspaceRoot,
  log,
});

console.log('GEREQ Pulse standalone → http://127.0.0.1:8772');
