const http = require('http');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const {
  tryLogin,
  createSession,
  destroySession,
  sessionFromReq,
  sessionCookie,
  clearSessionCookie,
  readBody,
} = require('./auth');

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.woff2': 'font/woff2',
  '.ico': 'image/x-icon',
};

/**
 * @param {{ port: number, store: import('./store').Store, uiDir: string, workspaceRoot: string, log: { appendLine: Function } }} opts
 */
function createServer(opts) {
  const { port, store, uiDir, workspaceRoot, log } = opts;
  /** @type {Set<import('http').ServerResponse>} */
  const sseClients = new Set();

  function broadcast(event) {
    const payload = `data: ${JSON.stringify(event)}\n\n`;
    for (const res of sseClients) {
      try {
        res.write(payload);
      } catch {
        sseClients.delete(res);
      }
    }
  }

  function sendJson(res, code, data, extraHeaders = {}) {
    res.writeHead(code, {
      'Content-Type': 'application/json; charset=utf-8',
      'Access-Control-Allow-Origin': res.req?.headers?.origin || '*',
      'Access-Control-Allow-Credentials': 'true',
      'Cache-Control': 'no-store',
      ...extraHeaders,
    });
    res.end(JSON.stringify(data));
  }

  function requireAuth(req, res) {
    const s = sessionFromReq(req);
    if (!s) {
      sendJson(res, 401, { error: 'unauthorized' });
      return null;
    }
    return s;
  }

  function serveStatic(res, filePath) {
    if (!fs.existsSync(filePath) || !fs.statSync(filePath).isFile()) {
      return false;
    }
    const ext = path.extname(filePath);
    res.writeHead(200, { 'Content-Type': MIME[ext] || 'application/octet-stream' });
    fs.createReadStream(filePath).pipe(res);
    return true;
  }

  const server = http.createServer(async (req, res) => {
    res.req = req;
    const url = new URL(req.url || '/', `http://127.0.0.1:${port}`);
    const pathname = url.pathname;

    if (req.method === 'OPTIONS') {
      res.writeHead(204, {
        'Access-Control-Allow-Origin': req.headers.origin || '*',
        'Access-Control-Allow-Credentials': 'true',
        'Access-Control-Allow-Methods': 'GET,POST,OPTIONS',
        'Access-Control-Allow-Headers': 'Content-Type',
      });
      res.end();
      return;
    }

    if (pathname === '/api/health') {
      sendJson(res, 200, { ok: true, port, workspace: workspaceRoot, auth: 'session' });
      return;
    }

    if (pathname === '/api/auth/me' && req.method === 'GET') {
      const s = sessionFromReq(req);
      if (!s) {
        sendJson(res, 401, { authenticated: false });
        return;
      }
      sendJson(res, 200, { authenticated: true, user: s.user });
      return;
    }

    if (pathname === '/api/auth/login' && req.method === 'POST') {
      try {
        const body = await readBody(req);
        const result = tryLogin(body.email, body.password);
        if (!result.ok) {
          sendJson(res, 401, { error: result.error });
          return;
        }
        const token = createSession(result.user.email, result.user.name);
        log.appendLine(`auth login ${result.user.email}`);
        sendJson(res, 200, { ok: true, user: result.user }, {
          'Set-Cookie': sessionCookie(token),
        });
      } catch (e) {
        sendJson(res, 400, { error: 'invalid json' });
      }
      return;
    }

    if (pathname === '/api/auth/logout' && req.method === 'POST') {
      const s = sessionFromReq(req);
      if (s) {
        destroySession(s.token);
      }
      sendJson(res, 200, { ok: true }, { 'Set-Cookie': clearSessionCookie() });
      return;
    }

    if (pathname === '/api/stats') {
      if (!requireAuth(req, res)) {
        return;
      }
      const days = Number(url.searchParams.get('days') || 42);
      sendJson(res, 200, store.stats({ days }));
      return;
    }

    if (pathname === '/api/events') {
      if (!requireAuth(req, res)) {
        return;
      }
      const limit = Number(url.searchParams.get('limit') || 200);
      const type = url.searchParams.get('type') || undefined;
      const since = url.searchParams.get('since') || undefined;
      sendJson(res, 200, {
        events: store.readEvents({ limit, type, since }),
      });
      return;
    }

    if (pathname === '/api/diff') {
      if (!requireAuth(req, res)) {
        return;
      }
      try {
        const file = url.searchParams.get('file');
        const args = file
          ? ['diff', '--', file]
          : ['diff', '--stat', 'HEAD'];
        const out = execFileSync('git', args, {
          cwd: workspaceRoot,
          timeout: 8000,
          encoding: 'utf8',
          maxBuffer: 2 * 1024 * 1024,
        });
        sendJson(res, 200, { diff: out, file: file || null });
      } catch (e) {
        sendJson(res, 200, { diff: '', error: String(e.message || e) });
      }
      return;
    }

    if (pathname === '/api/stream') {
      if (!sessionFromReq(req)) {
        res.writeHead(401, { 'Content-Type': 'text/plain' });
        res.end('unauthorized');
        return;
      }
      res.writeHead(200, {
        'Content-Type': 'text/event-stream; charset=utf-8',
        'Cache-Control': 'no-cache',
        Connection: 'keep-alive',
        'Access-Control-Allow-Origin': req.headers.origin || '*',
        'Access-Control-Allow-Credentials': 'true',
      });
      res.write(`data: ${JSON.stringify({ type: 'hello', ts: new Date().toISOString() })}\n\n`);
      sseClients.add(res);
      req.on('close', () => sseClients.delete(res));
      return;
    }

    // static UI
    let filePath = path.join(uiDir, pathname === '/' ? 'index.html' : pathname);
    if (!filePath.startsWith(uiDir)) {
      res.writeHead(403);
      res.end('Forbidden');
      return;
    }
    if (serveStatic(res, filePath)) {
      return;
    }
    const index = path.join(uiDir, 'index.html');
    if (serveStatic(res, index)) {
      return;
    }
    res.writeHead(503, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(`<!doctype html><meta charset="utf-8"><title>GEREQ Pulse</title>
      <body style="font-family:system-ui;padding:3rem;background:#0f1419;color:#e8eef4">
      <h1>GEREQ Pulse</h1>
      <p>UI ještě není sestavené. Spusť:</p>
      <pre style="background:#1a2330;padding:1rem;border-radius:8px">cd tools/gereq-monitor/ui && bun install && bun run build</pre>
      </body>`);
  });

  server.listen(port, '0.0.0.0', () => {
    log.appendLine(`listening 0.0.0.0:${port} ui=${uiDir}`);
  });

  server.on('error', (err) => {
    log.appendLine(`server error: ${err}`);
    throw err;
  });

  return {
    broadcast,
    close() {
      for (const res of sseClients) {
        try {
          res.end();
        } catch {
          /* */
        }
      }
      sseClients.clear();
      server.close();
    },
  };
}

module.exports = { createServer };
