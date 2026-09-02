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

// Single source of truth for the shared secret: read it from the Laravel app's
// .env (TELNET_INTERNAL_SECRET) when not provided via the environment, so it
// can't drift from config('telnet.internal_secret'). Core modules only — no
// dotenv dependency.
function fromLaravelEnv(key) {
    try {
        const fs   = require('fs');
        const path = require('path');
        const txt  = fs.readFileSync(path.join(__dirname, '..', '.env'), 'utf8');
        const m    = txt.match(new RegExp('^' + key + '=(.*)$', 'm'));
        return m ? m[1].trim().replace(/^["']|["']$/g, '') : '';
    } catch (e) {
        return '';
    }
}

const WS_PORT       = parseInt(process.env.WS_PORT       || '8765', 10);
// Default to the public hostname so the token call reaches the app vhost over a
// VALID TLS cert AND over loopback (so the internal.ip guard passes). This
// requires `127.0.0.1 noc.samirgroup.net` in /etc/hosts on the NOC host —
// plain http://127.0.0.1 hits the wrong nginx vhost (server_name mismatch).
const LARAVEL_URL   = (process.env.LARAVEL_URL           || 'https://noc.samirgroup.net').replace(/\/$/, '');
const SECRET        = process.env.INTERNAL_SECRET        || fromLaravelEnv('TELNET_INTERNAL_SECRET') || 'changeme';

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

// ─── Deploy run report-back ───────────────────────────────────────────────
// The proxy reports an exec run's result, not the browser: a deploy that
// outlives the tab still lands a complete transcript and a trustworthy exit
// code, and neither can be forged from the client.
function reportRun(reportUrl, payload) {
    return new Promise((resolve) => {
        if (!reportUrl) { resolve(false); return; }

        const endpoint = `${LARAVEL_URL}${reportUrl}`;
        const body     = JSON.stringify(payload);
        const lib      = endpoint.startsWith('https') ? https : http;

        const req = lib.request(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type':    'application/json',
                'Content-Length':  Buffer.byteLength(body),
                'Accept':          'application/json',
                'X-Telnet-Secret': SECRET,
            },
        }, (res) => {
            res.resume();                       // drain so the socket frees
            res.on('end', () => resolve(res.statusCode === 200));
        });

        req.on('error', (err) => {
            console.error('[SG-NOC Telnet Proxy] Run report failed:', err.message);
            resolve(false);
        });

        req.write(body);
        req.end();
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

    const {
        host, port, protocol = 'telnet',
        username = null, password = null,
        // Deployment servers add these: key auth, and a one-shot command whose
        // output is streamed AND reported back to Laravel when it exits.
        privateKey = null, passphrase = null,
        exec = null, reportUrl = null, timeout = null,
    } = session;
    const effectivePort = port || (protocol === 'ssh' ? 22 : 23);

    ws.send(JSON.stringify({ type: 'status', message: `Connecting to ${host}:${effectivePort} via ${protocol.toUpperCase()}…` }));

    if (protocol === 'ssh') {
        // ── SSH connection ────────────────────────────────────────────────
        // Two shapes off one branch:
        //   exec === null  → interactive shell (devices, telnet client, and the
        //                    deployment "SSH Terminal" button).
        //   exec is a cmd  → one-shot run: output is streamed to the browser
        //                    AND buffered, then reported to Laravel with the
        //                    exit code. The run deliberately survives the tab
        //                    being closed, so ws close does NOT tear it down.
        const ssh      = new SshClient();
        const isExec   = typeof exec === 'string' && exec.length > 0;
        const MAX_BUF  = 2 * 1024 * 1024;

        let stream    = null;
        let chunks    = [];
        let bufLen    = 0;
        let truncated = false;
        let reported  = false;
        let timer     = null;
        const startedAt = Date.now();

        const cleanup = () => {
            if (timer) { clearTimeout(timer); timer = null; }
            try { ssh.end(); } catch (_) {}
        };

        const capture = (data) => {
            if (!isExec || truncated) return;
            if (bufLen + data.length > MAX_BUF) {
                chunks.push(Buffer.from('\n\n… output truncated at ' + MAX_BUF + ' bytes …\n'));
                truncated = true;
                return;
            }
            chunks.push(data);
            bufLen += data.length;
        };

        // Report exactly once, whatever path we exit through.
        const finish = async (status, code) => {
            if (!isExec || reported) return;
            reported = true;

            await reportRun(reportUrl, {
                status:      status,
                exit_code:   typeof code === 'number' ? code : null,
                output:      Buffer.concat(chunks).toString('utf8'),
                duration_ms: Date.now() - startedAt,
            });
        };

        ssh.on('ready', () => {
            ws.send(JSON.stringify({ type: 'connected', message: `SSH connected to ${host}:${effectivePort}` }));

            const onStream = (err, s) => {
                if (err) {
                    if (ws.readyState === OPEN)
                        ws.send(JSON.stringify({ type: 'error', message: `SSH ${isExec ? 'exec' : 'shell'} error: ${err.message}` }));
                    finish('failed', null).finally(cleanup);
                    ws.close();
                    return;
                }
                stream = s;

                stream.on('data', (data) => {
                    capture(data);
                    if (ws.readyState === OPEN) ws.send(data);
                });

                // With pty:true ssh2 folds stderr into the main stream, but the
                // shell path has no pty option set here, so keep both wired.
                if (stream.stderr) {
                    stream.stderr.on('data', (data) => {
                        capture(data);
                        if (ws.readyState === OPEN) ws.send(data);
                    });
                }

                stream.on('close', (code) => {
                    const exitCode = typeof code === 'number' ? code : null;

                    if (ws.readyState === OPEN) {
                        if (isExec) ws.send(JSON.stringify({ type: 'exit', code: exitCode }));
                        ws.send(JSON.stringify({ type: 'disconnected', message: 'SSH session closed.' }));
                        ws.close();
                    }

                    finish(exitCode === 0 ? 'success' : 'failed', exitCode).finally(cleanup);
                });
            };

            if (isExec) {
                // pty so git/composer render progress and colour the way they
                // would in a real terminal.
                ssh.exec(exec, { pty: { term: 'xterm-256color', cols: 220, rows: 50 } }, onStream);

                const limitSeconds = Number(timeout) > 0 ? Number(timeout) : 600;
                timer = setTimeout(() => {
                    if (ws.readyState === OPEN)
                        ws.send(JSON.stringify({ type: 'error', message: `Command exceeded its ${limitSeconds}s timeout.` }));
                    finish('timeout', null).finally(() => {
                        try { ssh.end(); } catch (_) {}
                        try { ws.close(); } catch (_) {}
                    });
                }, limitSeconds * 1000);
            } else {
                ssh.shell({ term: 'xterm-256color', cols: 220, rows: 50 }, onStream);
            }
        });

        ssh.on('error', (err) => {
            if (ws.readyState === OPEN)
                ws.send(JSON.stringify({ type: 'error', message: `SSH error: ${err.message}` }));
            finish('failed', null).finally(cleanup);
            ws.close();
        });

        // Only one credential is offered: a key when we have one, otherwise the
        // password. Passing an empty string for the other makes ssh2 attempt a
        // method the server may then count against MaxAuthTries.
        const connectOpts = {
            host:              host,
            port:              effectivePort,
            username:          username || '',
            readyTimeout:      15000,
            keepaliveInterval: 10000,
        };

        if (privateKey) {
            // ssh2 parses OpenSSH, classic PEM and PuTTY .ppk natively, so an
            // uploaded .ppk needs no conversion.
            connectOpts.privateKey = privateKey;
            if (passphrase) connectOpts.passphrase = passphrase;
        } else {
            connectOpts.password = password || '';
        }

        ssh.connect(connectOpts);

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
            // A deploy button is not an input shell — drop keystrokes.
            if (isExec) return;
            stream.write(isBinary ? msg : msg.toString());
        });

        // An exec run must outlive the browser tab: closing the socket here
        // would kill the deploy halfway and lose the report. The timeout, and
        // the command exiting, are what end it.
        if (!isExec) {
            ws.on('close', cleanup);
            ws.on('error', cleanup);
        }

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
