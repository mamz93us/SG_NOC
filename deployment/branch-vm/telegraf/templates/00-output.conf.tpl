# ─────────────────────────────────────────────────────────────────────────
# Branch-wide Telegraf output: push every metric to the NOC's
# VictoriaMetrics over HTTPS via Prometheus remote_write.
#
# Rendered by install.sh / update.sh — placeholders __XXX__ are replaced
# with the values from /etc/sg-noc-branch.env.
# ─────────────────────────────────────────────────────────────────────────

[global_tags]
  branch = "__BRANCH_ID__"

[agent]
  interval         = "60s"
  round_interval   = true
  metric_batch_size = 1000
  metric_buffer_limit = 10000
  flush_interval   = "30s"
  flush_jitter     = "5s"
  hostname         = "__BRANCH_ID__-noc"
  omit_hostname    = false
  collection_jitter = "5s"
  precision        = ""

# ─── Self-monitoring (helps the NOC see if Telegraf itself is healthy) ───
[[inputs.internal]]
  collect_memstats = true

# ─── Push to NOC VictoriaMetrics via Prometheus remote_write ─────────────
[[outputs.http]]
  url               = "__NOC_METRICS_URL__"
  data_format       = "prometheusremotewrite"
  username          = "__NOC_METRICS_USER__"
  password          = "__NOC_METRICS_PASSWORD__"
  method            = "POST"
  timeout           = "30s"
  insecure_skip_verify = false

  # Telegraf's default [[inputs.system]] emits a string field
  # `uptime_format` (e.g. "23:28") that prometheus_remote_write can't
  # serialize. Drop it (and any other string-typed system fields) here
  # so the rest of the batch gets through cleanly.
  # IMPORTANT: keep this BEFORE the [outputs.http.headers] sub-table —
  # TOML parses any keys after a sub-table opens as belonging to that
  # sub-table, which causes a "cannot unmarshal TOML array" error.
  fieldexclude = ["uptime_format"]

  [outputs.http.headers]
    Content-Type                      = "application/x-protobuf"
    Content-Encoding                  = "snappy"
    X-Prometheus-Remote-Write-Version = "0.1.0"
