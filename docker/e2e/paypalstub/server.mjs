// PayPal IPN postback stub for the E2E sandbox (compose service "paypalstub").
//
//   :9000 HTTPS  POST /cgi-bin/webscr  -> "VERIFIED" | "INVALID" | HTTP 500 (mode "down")
//   :9001 HTTP   POST /control {mode}  -> sets the mode ('verified' | 'invalid' | 'down')
//                GET  /last             -> {mode, count, last: {body, headers, at} | null}
//                POST /reset            -> mode 'verified', counters cleared
//                GET  /health           -> "ok"
//
// TLS uses a throwaway self-signed cert (e2ecerts service); only the e2eapp container trusts it.
/* global Buffer, process, console -- Node script, linted by the repo ESLint config without Node globals. */
import { createServer as createHttps } from 'node:https';
import { createServer as createHttp } from 'node:http';
import { readFileSync } from 'node:fs';

const MODES = new Set(['verified', 'invalid', 'down']);
const state = { mode: 'verified', count: 0, last: null };

const readBody = (req) =>
  new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on('data', (c) => {
      size += c.length;
      if (size > 256 * 1024) {
        reject(new Error('body too large'));
        req.destroy();
      } else {
        chunks.push(c);
      }
    });
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });

const send = (res, status, body, type = 'text/plain') => {
  res.writeHead(status, { 'content-type': type, connection: 'close' });
  res.end(typeof body === 'string' ? body : JSON.stringify(body));
};

const webscr = async (req, res) => {
  if (req.method !== 'POST' || req.url !== '/cgi-bin/webscr') return send(res, 404, 'not found');
  const body = await readBody(req);
  state.count += 1;
  state.last = { body, headers: req.headers, at: new Date().toISOString() };
  if (state.mode === 'down') return send(res, 500, 'stub down');
  return send(res, 200, state.mode === 'verified' ? 'VERIFIED' : 'INVALID');
};

const control = async (req, res) => {
  if (req.method === 'GET' && req.url === '/health') return send(res, 200, 'ok');
  if (req.method === 'GET' && req.url === '/last') return send(res, 200, state, 'application/json');
  if (req.method === 'POST' && req.url === '/reset') {
    Object.assign(state, { mode: 'verified', count: 0, last: null });
    return send(res, 200, state, 'application/json');
  }
  if (req.method === 'POST' && req.url === '/control') {
    let mode;
    try {
      mode = JSON.parse(await readBody(req)).mode;
    } catch {
      return send(res, 400, { error: 'invalid json' }, 'application/json');
    }
    if (!MODES.has(mode)) return send(res, 400, { error: 'mode must be verified|invalid|down' }, 'application/json');
    state.mode = mode;
    return send(res, 200, state, 'application/json');
  }
  return send(res, 404, 'not found');
};

const guard = (handler) => (req, res) => handler(req, res).catch((e) => send(res, 500, String(e.message)));
const certDir = process.env.CERT_DIR ?? '/certs';
createHttps({ key: readFileSync(`${certDir}/stub.key`), cert: readFileSync(`${certDir}/stub.crt`) }, guard(webscr)).listen(9000);
createHttp(guard(control)).listen(9001);
console.log('paypalstub: https :9000 (webscr), http :9001 (control)');
