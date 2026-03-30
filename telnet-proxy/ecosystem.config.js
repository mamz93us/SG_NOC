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
                NODE_ENV:          'production',
                WS_PORT:           '8765',
                LARAVEL_URL:       'http://127.0.0.1',
                INTERNAL_SECRET:   'changeme_replace_with_random_string',  // match TELNET_INTERNAL_SECRET in .env
            },
            log_date_format: 'YYYY-MM-DD HH:mm:ss',
            error_file:  '/var/log/sg-noc-telnet-error.log',
            out_file:    '/var/log/sg-noc-telnet-out.log',
        },
    ],
};
