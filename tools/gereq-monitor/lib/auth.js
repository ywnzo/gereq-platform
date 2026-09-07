const crypto = require('crypto');

/** Dočasné hardcoded credentials — později napojíme na API. */
const DEMO_USER = {
  email: 'test@gereq.cz',
  password: 'heslo',
  name: 'Test GEREQ',
};

/** @type {Map<string, { email: string, name: string, exp: number }>} */
const sessions = new Map();

const TTL_MS = 7 * 24 * 60 * 60 * 1000;

function parseCookies(req) {
  /** @type {Record<string, string>} */
  const out = {};
  const raw = req.headers.cookie || '';
  for (const part of raw.split(';')) {
    const i = part.indexOf('=');
    if (i < 0) {
      continue;
    }
    const k = part.slice(0, i).trim();
    const v = part.slice(i + 1).trim();
    out[k] = decodeURIComponent(v);
  }
  return out;
}

function createSession(email, name) {
  const token = crypto.randomBytes(24).toString('hex');
  sessions.set(token, {
    email,
    name,
    exp: Date.now() + TTL_MS,
  });
  return token;
}

function destroySession(token) {
  if (token) {
    sessions.delete(token);
  }
}

function sessionFromReq(req) {
  const cookies = parseCookies(req);
  const token = cookies.pulse_session;
  if (!token) {
    return null;
  }
  const s = sessions.get(token);
  if (!s) {
    return null;
  }
  if (s.exp < Date.now()) {
    sessions.delete(token);
    return null;
  }
  return { token, user: { email: s.email, name: s.name } };
}

function sessionCookie(token) {
  return `pulse_session=${encodeURIComponent(token)}; Path=/; HttpOnly; SameSite=Lax; Max-Age=${Math.floor(TTL_MS / 1000)}`;
}

function clearSessionCookie() {
  return 'pulse_session=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0';
}

/**
 * @param {string} email
 * @param {string} password
 * @returns {{ ok: true, user: { email: string, name: string } } | { ok: false, error: string }}
 */
function tryLogin(email, password) {
  const e = String(email || '').trim().toLowerCase();
  const p = String(password || '');
  // TODO: nahradit voláním GEREQ API
  if (e === DEMO_USER.email && p === DEMO_USER.password) {
    return { ok: true, user: { email: DEMO_USER.email, name: DEMO_USER.name } };
  }
  return { ok: false, error: 'Neplatný e-mail nebo heslo' };
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
      const raw = Buffer.concat(chunks).toString('utf8');
      if (!raw) {
        resolve({});
        return;
      }
      try {
        resolve(JSON.parse(raw));
      } catch (e) {
        reject(e);
      }
    });
    req.on('error', reject);
  });
}

module.exports = {
  DEMO_USER,
  tryLogin,
  createSession,
  destroySession,
  sessionFromReq,
  sessionCookie,
  clearSessionCookie,
  readBody,
};
