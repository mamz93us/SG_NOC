/**
 * PM2 ecosystem config for the SG NOC Telnet WebSocket proxy.
 *
 * Usage on the VPS:
 *   npm install -g pm2
 *   cd /path/to/app/telnet-proxy
 *   pm2 start ecosystem.config.js
 *   pm2 save          # persist across reboots
 *   pm2 startup       # install startup hook
 */
module.exports = {
    apps: [
        {
            name:         'sg-noc-telnet',
            script:       'server.js',
            cwd:          __dirname,
            instances:    1,
            autorestart:  true,
            watch:        false,
            max_memory_restart: '128M',
            env: {
                NODE_ENV: 'production',
                WS_PORT:  '8765',
                // LARAVEL_URL and INTERNAL_SECRET are intentionally NOT set here:
                //  - server.js defaults LARAVEL_URL to https://noc.samirgroup.net.
                //    Requires `127.0.0.1 noc.samirgroup.net` in /etc/hosts so the
                //    internal token call reaches Laravel over loopback (the
                //    internal.ip guard only allows 127.0.0.1) with a valid cert.
                //  - the shared secret is read from the app's ../.env
                //    (TELNET_INTERNAL_SECRET) by server.js — one source of truth,
                //    never committed to git.
            },
            log_date_format: 'YYYY-MM-DD HH:mm:ss',
            error_file:  '/home/azureuser/.pm2/logs/sg-noc-telnet-error.log',
            out_file:    '/home/azureuser/.pm2/logs/sg-noc-telnet-out.log',
        },
    ],
};
