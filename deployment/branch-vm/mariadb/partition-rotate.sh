#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────
# Daily partition rotation for syslog_messages.
#
# Run modes:
#   --bootstrap   : first-time setup — creates today + 7 days forward
#   (no flag)     : nightly cron — creates tomorrow, drops any partitions
#                   older than RETENTION_DAYS days.
#
# Reads /etc/sg-noc-branch.env for DB credentials and RETENTION_DAYS.
# ─────────────────────────────────────────────────────────────────────────
set -euo pipefail

ENV_FILE="/etc/sg-noc-branch.env"
[[ -f $ENV_FILE ]] || { echo "$ENV_FILE missing" >&2; exit 1; }
set -a; . "$ENV_FILE"; set +a

: "${DB_NAME:=sg_noc_branch}"
: "${DB_USER:=sg_noc}"
: "${RETENTION_DAYS:=60}"
: "${TIMEZONE:=Asia/Riyadh}"

MODE="${1:-cron}"

run_sql() {
    MYSQL_PWD="$DB_PASSWORD" mysql -u "$DB_USER" "$DB_NAME" --batch --skip-column-names -e "$1"
}

# Pick the dates we want partitions for — use the configured TIMEZONE.
today_offset_date() {
    # Args: offset days (e.g. 1 = tomorrow)
    TZ="$TIMEZONE" date -d "+$1 days" +%Y%m%d
}

partition_exists() {
    local name="$1"
    run_sql "SELECT COUNT(*) FROM information_schema.partitions
             WHERE table_schema='$DB_NAME' AND table_name='syslog_messages'
               AND partition_name='$name';" | tr -d '[:space:]'
}

create_partition_for_offset() {
    local offset="$1"
    local pname="p$(today_offset_date "$offset")"
    if [[ "$(partition_exists "$pname")" != "0" ]]; then
        echo "  $pname exists, skipping"
        return 0
    fi
    # Boundary = the day AFTER the partition's date (TO_DAYS goes by midnight)
    local boundary
    boundary=$(TZ="$TIMEZONE" date -d "+$((offset+1)) days" +%Y-%m-%d)
    echo "  creating $pname (rows < $boundary)"
    run_sql "ALTER TABLE syslog_messages
             REORGANIZE PARTITION pmax INTO (
                 PARTITION $pname VALUES LESS THAN (TO_DAYS('$boundary')),
                 PARTITION pmax  VALUES LESS THAN MAXVALUE
             );"
}

drop_old_partitions() {
    # List all pYYYYMMDD partitions, find any whose date is older than RETENTION_DAYS
    local cutoff
    cutoff=$(TZ="$TIMEZONE" date -d "-$RETENTION_DAYS days" +%Y%m%d)
    local victims
    victims=$(run_sql "
        SELECT partition_name FROM information_schema.partitions
        WHERE table_schema='$DB_NAME' AND table_name='syslog_messages'
          AND partition_name REGEXP '^p[0-9]{8}\$'
          AND CAST(SUBSTRING(partition_name,2) AS UNSIGNED) < $cutoff
        ORDER BY partition_name;
    ")

    if [[ -z "${victims// }" ]]; then
        echo "  no partitions older than $cutoff, nothing to drop"
        return 0
    fi

    while IFS= read -r p; do
        [[ -z "$p" ]] && continue
        echo "  dropping $p"
        run_sql "ALTER TABLE syslog_messages DROP PARTITION $p;"
    done <<<"$victims"
}

case "$MODE" in
    --bootstrap)
        echo "Bootstrap: creating partitions for today + 7 days"
        for i in $(seq 0 7); do
            create_partition_for_offset "$i"
        done
        ;;
    cron|--cron)
        echo "Nightly: creating tomorrow's partition + dropping old ones"
        create_partition_for_offset 1
        # Defensive: also create today if a node was down during last cron
        create_partition_for_offset 0
        drop_old_partitions
        ;;
    *)
        echo "Usage: $0 [--bootstrap|--cron]" >&2
        exit 2
        ;;
esac

echo "OK"
