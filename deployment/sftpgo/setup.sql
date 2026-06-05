-- SFTPGo data-provider database + least-privilege user.
--
-- Run once on the NOC VM as a MySQL admin:
--     sudo mysql < deployment/sftpgo/setup.sql
-- Then mirror the password into /etc/sftpgo/sftpgo.env (SFTPGO_DATA_PROVIDER__PASSWORD).
--
-- SFTPGo OWNS this schema — it runs CREATE/ALTER on its own tables across upgrades,
-- and its table names (users, admins, groups, shares, tasks) collide with the app.
-- So it gets a DEDICATED `sftpgo` database, never the app's `phonebook2`. This is a
-- deliberate divergence from the rsyslog/freeradius "grant on one app table" pattern
-- (those write an app-owned table; SFTPGo owns its entire schema).

CREATE DATABASE IF NOT EXISTS `sftpgo`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Change 'REPLACE_ME' to a strong password (and put the SAME value in sftpgo.env).
-- It must satisfy MySQL's validate_password policy (default MEDIUM): >= 8 chars with
-- upper + lower + digit + special, or CREATE USER fails with ERROR 1819. No quote (').
--
-- SFTPGo connects over TCP to 127.0.0.1, which MySQL treats as a DIFFERENT host
-- account than 'localhost' (the unix socket). Create BOTH so it works either way.
CREATE USER IF NOT EXISTS 'sftpgo'@'localhost' IDENTIFIED BY 'REPLACE_ME';
CREATE USER IF NOT EXISTS 'sftpgo'@'127.0.0.1' IDENTIFIED BY 'REPLACE_ME';

-- Full rights on ITS OWN database only (DDL included). No access to phonebook2.
GRANT ALL PRIVILEGES ON `sftpgo`.* TO 'sftpgo'@'localhost';
GRANT ALL PRIVILEGES ON `sftpgo`.* TO 'sftpgo'@'127.0.0.1';

FLUSH PRIVILEGES;
