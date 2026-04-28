-- ============================================================================
-- SG-NOC RADIUS — MySQL user with least-privilege grants
-- ============================================================================
-- Run once on the VPS after `php artisan migrate` has created the radius_*
-- tables. Mirrors the rsyslog setup pattern.
--
--   sudo mysql phonebook2 < deployment/freeradius/setup.sql
--   (edit the IDENTIFIED BY line first; do NOT commit a real password.)
--
-- After running, also drop the matching password into
-- /etc/freeradius/3.0/mods-config/sql/secret.conf (mode 640 root:freerad).
-- ============================================================================

CREATE USER IF NOT EXISTS 'freeradius'@'localhost'
    IDENTIFIED BY 'REPLACE_ME_WITH_A_LONG_RANDOM_STRING';

-- Read-only on the data tables FreeRADIUS needs to evaluate authorize.
GRANT SELECT ON phonebook2.device_macs                  TO 'freeradius'@'localhost';
GRANT SELECT ON phonebook2.devices                       TO 'freeradius'@'localhost';
GRANT SELECT ON phonebook2.azure_devices                 TO 'freeradius'@'localhost';
GRANT SELECT ON phonebook2.radius_nas_clients            TO 'freeradius'@'localhost';
GRANT SELECT ON phonebook2.radius_branch_vlan_policy     TO 'freeradius'@'localhost';
GRANT SELECT ON phonebook2.radius_mac_overrides          TO 'freeradius'@'localhost';

-- MVP intentionally omits INSERT grants. v2 (audit-log table) will add:
--   GRANT INSERT ON phonebook2.radius_auth_logs    TO 'freeradius'@'localhost';

FLUSH PRIVILEGES;
