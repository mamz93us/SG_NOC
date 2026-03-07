# Infrastructure Command Center Setup Guide

To complete the setup of the VPN Hub and Network Monitoring, please follow these steps on your Linux server.

## 1. strongSwan Wrapper Installation

The system uses a secure wrapper script to interact with `swanctl`. 

1. Link the wrapper script to `/usr/local/bin/` (re-run this if using an old move-based setup):
   ```bash
   sudo ln -sf /home/azureuser/phonebook2/sg-vpn-control.sh /usr/local/bin/sg-vpn-control
   sudo chmod +x /usr/local/bin/sg-vpn-control
   ```

2. Configure `sudoers` to allow `www-data` to run the actions:
   Edit the sudoers file:
   ```bash
   sudo visudo /etc/sudoers.d/vpn-control
   ```
   Add/Update the following line:
   ```text
   www-data ALL=(ALL) NOPASSWD: /usr/local/bin/sg-vpn-control status, /usr/local/bin/sg-vpn-control up *, /usr/local/bin/sg-vpn-control down *, /usr/local/bin/sg-vpn-control reload, /usr/local/bin/sg-vpn-control logs
   ```

## 2. strongSwan Config Directory

Ensure the web server user (`www-data`) can write to the `swanctl` configuration directory (or use a staging directory):

```bash
sudo mkdir -p /etc/swanctl/conf.d/
sudo chown www-data:www-data /etc/swanctl/conf.d/
```

## 3. SNMP Support

To enable SNMP polling, the PHP SNMP extension must be installed:

```bash
sudo apt-get install php-snmp snmp
sudo service php8.2-fpm restart  # or your PHP version
```

## 4. Background Jobs

Ensure your Laravel scheduler is running in `crontab -e`:

```text
* * * * * cd /path/to/phonebook2 && php artisan schedule:run >> /dev/null 2>&1
```

The following jobs have been scheduled:
- `CheckVpnStatusJob`: Every minute (Checks tunnel status via `swanctl`)
- `CollectMetricsJob`: Every 5 minutes (Polls SNMP and Pings hosts)
- `IdentitySync`: Twice daily (6 AM and 6 PM)

## 5. Directory Permissions

Ensure the `storage/app/mibs` directory exists for MIB uploads:

```bash
php artisan storage:link
mkdir -p storage/app/mibs
chmod -R 775 storage/app/mibs
```
