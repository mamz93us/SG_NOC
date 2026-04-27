-- ─────────────────────────────────────────────────────────────────────
-- One-time setup for the rsyslog MySQL writer.
--
-- Creates a least-privilege user that only has INSERT on
-- syslog_messages. The Laravel app (separate user) handles SELECT /
-- UPDATE / DELETE for tagging and pruning.
--
-- Run as a MySQL admin (root or equivalent):
--   mysql -u root -p < deployment/rsyslog/setup.sql
--
-- Then edit /etc/rsyslog.d/90-sg-noc-syslog-secret.conf and set the
-- same password.
-- ─────────────────────────────────────────────────────────────────────

CREATE USER IF NOT EXISTS 'rsyslog'@'localhost'
    IDENTIFIED BY 'REPLACE_ME';

-- Only INSERT — rsyslog should never read or modify rows.
GRANT INSERT ON sg_noc.syslog_messages TO 'rsyslog'@'localhost';

FLUSH PRIVILEGES;

-- To rotate the password later:
--   ALTER USER 'rsyslog'@'localhost' IDENTIFIED BY 'NEW_PASSWORD';
--   FLUSH PRIVILEGES;
-- and update /etc/rsyslog.d/90-sg-noc-syslog-secret.conf to match.
