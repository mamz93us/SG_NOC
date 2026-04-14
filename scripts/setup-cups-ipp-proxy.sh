#!/bin/bash
# ============================================================
# CUPS IPP Proxy Print Server — VPS Setup Script
# Run once on the VPS to configure CUPS as an IPP proxy.
#
# Prerequisites:
#   - Ubuntu/Debian-based VPS
#   - VPN tunnel to branch networks already configured
#   - Root or sudo access
#
# Usage:
#   chmod +x setup-cups-ipp-proxy.sh
#   sudo ./setup-cups-ipp-proxy.sh
# ============================================================

set -euo pipefail

echo "============================================"
echo " CUPS IPP Proxy Setup — SG NOC"
echo "============================================"
echo ""

# 1. Install CUPS
echo "[1/5] Installing CUPS..."
apt-get update -qq
apt-get install -y cups cups-client cups-bsd

# 2. Configure CUPS to listen on all interfaces
echo "[2/5] Configuring CUPS to accept remote connections..."

CUPSD_CONF="/etc/cups/cupsd.conf"
cp "$CUPSD_CONF" "$CUPSD_CONF.bak.$(date +%s)"

# Listen on all interfaces instead of just localhost
sed -i 's/^Listen localhost:631$/Listen 0.0.0.0:631/' "$CUPSD_CONF"

# Add ServerAlias and access permissions if not already present
if ! grep -q 'ServerAlias \*' "$CUPSD_CONF"; then
    cat >> "$CUPSD_CONF" << 'CUPSEOF'

# ── SG NOC IPP Proxy Configuration ──
ServerAlias *

# Allow remote access to the server
<Location />
  Order allow,deny
  Allow all
</Location>

# Allow remote administration (optional — restrict to VPN subnet)
<Location /admin>
  Order allow,deny
  Allow @LOCAL
</Location>

# Allow remote access to printer configuration
<Location /admin/conf>
  AuthType Default
  Require user @SYSTEM
  Order allow,deny
  Allow @LOCAL
</Location>
CUPSEOF
fi

# 3. Configure sudoers for www-data (Laravel web server user)
echo "[3/5] Configuring sudoers for web server user..."

SUDOERS_FILE="/etc/sudoers.d/cups-noc"
cat > "$SUDOERS_FILE" << 'SUDOEOF'
# Allow www-data (Laravel) to manage CUPS printers without password
www-data ALL=(root) NOPASSWD: /usr/sbin/lpadmin
www-data ALL=(root) NOPASSWD: /usr/sbin/cupsenable
www-data ALL=(root) NOPASSWD: /usr/sbin/cupsdisable
www-data ALL=(root) NOPASSWD: /usr/sbin/cupsaccept
www-data ALL=(root) NOPASSWD: /usr/sbin/cupsreject
www-data ALL=(root) NOPASSWD: /usr/bin/lp
www-data ALL=(root) NOPASSWD: /usr/bin/cancel
SUDOEOF
chmod 0440 "$SUDOERS_FILE"

# 4. Restart and enable CUPS
echo "[4/5] Restarting CUPS service..."
systemctl restart cups
systemctl enable cups

# 5. Open firewall port (if ufw is active)
echo "[5/5] Configuring firewall..."
if command -v ufw &> /dev/null && ufw status | grep -q "Status: active"; then
    ufw allow 631/tcp comment "CUPS IPP Print Server"
    echo "  Firewall: port 631/tcp opened."
else
    echo "  Firewall: ufw not active or not installed. Ensure port 631 is open."
fi

echo ""
echo "============================================"
echo " CUPS IPP Print Server is ready!"
echo ""
echo " IPP URL: ipp://$(hostname -I | awk '{print $1}'):631"
echo " Web UI:  https://$(hostname -I | awk '{print $1}'):631"
echo ""
echo " Next steps:"
echo "   1. Configure cups_ipp_domain in SG NOC Settings"
echo "   2. Add printers via SG NOC Print Manager UI"
echo "   3. Test from a client device"
echo "============================================"
