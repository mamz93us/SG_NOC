/**
 * SG NOC — Telnet WebSocket Proxy
 *
 * Bridges browser WebSocket connections to raw Telnet TCP sockets.
 * Token validation calls the Laravel internal API to fetch session data.
 *
 * Start:  node server.js
 * Config via environment:
 *   WS_PORT          WebSocket listen port  (default: 8765)
 *   LARAVEL_URL      Internal Laravel URL   (default: http://127.0.0.1)
 *   INTERNAL_SECRET  Shared secret header   (must match TELNET_INTERNAL_SECRET in .env)
 */

'use strict';

const net        = require('net');
const http       = require('http');
const https      = require('https');
const { Client: SshClient } = require('ssh2');
const { WebSocketServer, OPEN } = require('ws');
const url        = require('url');

const WS_PORT       = parseInt(process.env.WS_PORT       || '8765', 10);
const LARAVEL_URL   = (process.env.LARAVEL_URL           || 'http://127.0.0.1').replace(/\/$/, '');
const SECRET        = process.env.INTERNAL_SECRET        || 'changeme';

// ─── Telnet IAC constants ──────────────────────────────────────────────────
const IAC  = 0xFF;
const WILL = 0xFB;
const WONT = 0xFC;
const DO   = 0xFD;
const DONT = 0xFE;
const SB   = 0xFA;
const SE   = 0xF0;

/**
 * Strip Telnet IAC option-negotiation sequences from inbound data.
 * Returns { clean: Buffer, response: Buffer } where response contains
 * the DONT/WONT replies to send back to the Telnet server.
 */
function processTelnet(data) {
    const clean    = [];
    const response = [];
    let i = 0;

    while (i < data.length) {
        if (data[i] !== IAC) {
            clean.push(data[i++]);
            continue;
        }

        // IAC byte — need at least one more byte
        if (i + 1 >= data.length) { i++; break; }

        const cmd = data[i + 1];

        if (cmd === SB) {
            // Subnegotiation — skip until IAC SE
            i += 2;
            while (i < data.length) {
                if (data[i] === IAC && i + 1 < data.length && data[i + 1] === SE) {
                    i += 2; break;
                }
                i++;
            }
        } else if (cmd === WILL) {
            // Server offers to enable option — decline with DONT
            if (i + 2 < data.length) {
                response.push(IAC, DONT, data[i + 2]);
                i += 3;
            } else { i += 2; }
        } else if (cmd === DO) {
            // Server requests we enable option — refuse with WONT
            if (i + 2 < data.length) {
                response.push(IAC, WONT, data[i + 2]);
                i += 3;
            } else { i += 2; }
        } else if (cmd === WONT || cmd === DONT) {
            // Server declining our offer — just skip
            i += (i + 2 < data.length) ? 3 : 2;
        } else {
            i += 2; // Other two-byte IAC commands
        }
    }

    return {
        clean:    Buffer.from(clean),
        response: Buffer.from(response),
    };
}

// ─── Laravel token validation ─────────────────────────────────────────────
function fetchSession(token) {
    return new Promise((resolve, reject) => {
        const endpoint = `${LARAVEL_URL}/internal/telnet-token/${encodeURIComponent(token)}`;
        const lib      = endpoint.startsWith('https') ? https : http;

        lib.get(endpoint, { headers: { 'X-Telnet-Secret': SECRET } }, (res) => {
            let body = '';
            res.on('data', chunk => body += chunk);
            res.on('end', () => {
                if (res.statusCode !== 200) {
                    reject(new Error(`HTTP ${res.statusCode}`));
                    return;
                }
                try { resolve(JSON.parse(body)); }
                catch (e) { reject(e); }
            });
        }).on('error', reject);
    });
}

// ─── WebSocket server ─────────────────────────────────────────────────────
const wss = new WebSocketServer({ port: WS_PORT, host: '127.0.0.1' });

wss.on('connection', async (ws, req) => {
    const params = new URLSearchParams(url.parse(req.url).query);
    const token  = params.get('token');

    if (!token) {
        ws.send(JSON.stringify({ type: 'error', message: 'No token provided.' }));
        ws.close();
        return;
    }

    // ── Validate token with Laravel ──────────────────────────────────────
    let session;
    try {
        session = await fetchSession(token);
    } catch (err) {
        ws.send(JSON.stringify({ type: 'error', message: `Token validation failed: ${err.message}` }));
        ws.close();
        return;
    }

    const { host, port, protocol = 'telnet', username = null, password = null } = session;
    const effectivePort = port || (protocol === 'ssh' ? 22 : 23);

    ws.send(JSON.stringify({ type: 'status', message: `Connecting to ${host}:${effectivePort} via ${protocol.toUpperCase()}…` }));

    if (protocol === 'ssh') {
        // ── SSH connection ────────────────────────────────────────────────
        const ssh = new SshClient();
        let stream = null;

        const cleanup = () => { try { ssh.end(); } catch (_) {} };

        ssh.on('ready', () => {
            ws.send(JSON.stringify({ type: 'connected', message: `SSH connected to ${host}:${effectivePort}` }));

            ssh.shell({ term: 'xterm-256color', cols: 220, rows: 50 }, (err, s) => {
                if (err) {
                    if (ws.readyState === OPEN)
                        ws.send(JSON.stringify({ type: 'error', message: `SSH shell error: ${err.message}` }));
                    cleanup();
                    ws.close();
                    return;
                }
                stream = s;

                stream.on('data',  (data) => { if (ws.readyState === OPEN) ws.send(data); });
                stream.stderr.on('data', (data) => { if (ws.readyState === OPEN) ws.send(data); });
                stream.on('close', () => {
                    if (ws.readyState === OPEN) {
                        ws.send(JSON.stringify({ type: 'disconnected', message: 'SSH session closed.' }));
                        ws.close();
                    }
                    cleanup();
                });
            });
        });

        ssh.on('error', (err) => {
            if (ws.readyState === OPEN)
                ws.send(JSON.stringify({ type: 'error', message: `SSH error: ${err.message}` }));
            ws.close();
        });

        ssh.connect({
            host:              host,
            port:              effectivePort,
            username:          username || '',
            password:          password || '',
            readyTimeout:      15000,
            keepaliveInterval: 10000,
        });

        // ── WebSocket → SSH ───────────────────────────────────────────────
        ws.on('message', (msg, isBinary) => {
            if (!stream) return;
            if (!isBinary) {
                try {
                    const ctrl = JSON.parse(msg.toString());
                    if (ctrl !== null && typeof ctrl === 'object' && ctrl.type === 'resize') {
                        stream.setWindow(ctrl.rows || 50, ctrl.cols || 220, 0, 0);
                        return;
                    }
                } catch (_) {}
            }
            stream.write(isBinary ? msg : msg.toString());
        });

        ws.on('close', cleanup);
        ws.on('error', cleanup);

    } else {
        // ── Telnet connection ─────────────────────────────────────────────
        const telnet = new net.Socket();
        let connected = false;

        const cleanup = () => { if (!telnet.destroyed) telnet.destroy(); };

        telnet.setTimeout(10000);

        telnet.connect(effectivePort, host, () => {
            connected = true;
            ws.send(JSON.stringify({ type: 'connected', message: `Telnet connected to ${host}:${effectivePort}` }));
            telnet.setTimeout(0);
        });

        // ── Telnet → WebSocket ────────────────────────────────────────────
        telnet.on('data', (data) => {
            if (ws.readyState !== OPEN) return;
            const { clean, response } = processTelnet(data);
            if (response.length > 0) telnet.write(response);
            if (clean.length > 0)    ws.send(clean);
        });

        telnet.on('error', (err) => {
            if (ws.readyState === OPEN)
                ws.send(JSON.stringify({ type: 'error', message: `Telnet error: ${err.message}` }));
            cleanup();
            ws.close();
        });

        telnet.on('timeout', () => {
            if (!connected) {
                if (ws.readyState === OPEN)
                    ws.send(JSON.stringify({ type: 'error', message: `Connection to ${host}:${effectivePort} timed out.` }));
                cleanup();
                ws.close();
            }
        });

        telnet.on('close', () => {
            if (ws.readyState === OPEN) {
                ws.send(JSON.stringify({ type: 'disconnected', message: 'Remote host closed the connection.' }));
                ws.close();
            }
        });

        // ── WebSocket → Telnet ────────────────────────────────────────────
        ws.on('message', (msg, isBinary) => {
            if (telnet.destroyed || !connected) return;
            if (!isBinary) {
                try {
                    const ctrl = JSON.parse(msg.toString());
                    if (ctrl !== null && typeof ctrl === 'object' && ctrl.type === 'resize') {
                        const cols = Math.min(ctrl.cols || 220, 65535);
                        const rows = Math.min(ctrl.rows || 50,  65535);
                        telnet.write(Buffer.from([
                            IAC, SB, 31,
                            (cols >> 8) & 0xFF, cols & 0xFF,
                            (rows >> 8) & 0xFF, rows & 0xFF,
                            IAC, SE,
                        ]));
                        return;
                    }
                } catch (_) {}
            }
            telnet.write(isBinary ? msg : msg.toString());
        });

        ws.on('close', cleanup);
        ws.on('error', cleanup);
    }
});

wss.on('listening', () => {
    console.log(`[SG-NOC Telnet Proxy] Listening on ws://127.0.0.1:${WS_PORT}`);
    console.log(`[SG-NOC Telnet Proxy] Validating tokens via ${LARAVEL_URL}/internal/telnet-token/{token}`);
});

wss.on('error', (err) => {
    console.error('[SG-NOC Telnet Proxy] Server error:', err.message);
    process.exit(1);
});

process.on('SIGINT',  () => { console.log('\nShutting down…'); process.exit(0); });
process.on('SIGTERM', () => { process.exit(0); });
