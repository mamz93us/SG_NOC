-- ────────────────────────────────────────────────────────────────────────
-- SG_NOC Branch VM — MariaDB schema.
--
-- Idempotent: install.sh runs this on first install AND on every update,
-- so every statement uses IF NOT EXISTS / ALTER ... ADD IF NOT EXISTS.
--
-- Partition strategy: one partition per day, named pYYYYMMDD. The
-- partition-rotate.sh cron creates tomorrow's partition each night and
-- drops anything older than RETENTION_DAYS.
-- ────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS syslog_messages (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    received_at  DATETIME(3)     NOT NULL,
    device_time  DATETIME(3)     NULL,
    branch       VARCHAR(8)      NOT NULL,
    source       VARCHAR(64)     NOT NULL,
    source_ip    VARCHAR(45)     NOT NULL,
    program      VARCHAR(128)    NULL,
    facility     TINYINT UNSIGNED NOT NULL DEFAULT 1,
    severity     TINYINT UNSIGNED NOT NULL DEFAULT 6,
    message      TEXT            NOT NULL,

    -- Pre-parsed Sophos fields (NULL when message isn't Sophos)
    sophos_log_type     VARCHAR(32)  NULL,
    sophos_log_subtype  VARCHAR(32)  NULL,
    sophos_log_component VARCHAR(64) NULL,
    sophos_src_ip       VARCHAR(45)  NULL,
    sophos_dst_ip       VARCHAR(45)  NULL,
    sophos_src_port     INT UNSIGNED NULL,
    sophos_dst_port     INT UNSIGNED NULL,
    sophos_protocol     VARCHAR(8)   NULL,
    sophos_fw_rule_name VARCHAR(64)  NULL,
    sophos_user_name    VARCHAR(64)  NULL,
    sophos_application  VARCHAR(64)  NULL,

    PRIMARY KEY (id, received_at),
    KEY idx_received    (received_at),
    KEY idx_source      (source, received_at),
    KEY idx_severity    (severity, received_at),
    KEY idx_program     (program, received_at),
    KEY idx_sophos_sub  (sophos_log_subtype, received_at),
    KEY idx_sophos_dst  (sophos_dst_ip, received_at)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
ROW_FORMAT=COMPRESSED  KEY_BLOCK_SIZE=8
PARTITION BY RANGE (TO_DAYS(received_at)) (
    PARTITION pmax VALUES LESS THAN MAXVALUE
);

-- Track which schema migrations have been applied so future updates can
-- be incremental. Each migration script writes its own row.
CREATE TABLE IF NOT EXISTS schema_migrations (
    version   VARCHAR(32) NOT NULL PRIMARY KEY,
    applied_at DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO schema_migrations (version) VALUES ('20260506_initial');
