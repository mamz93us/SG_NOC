// Command sg-branch-agent is the consolidated per-branch agent: local web UI,
// log collection, device monitoring and DDNS reporting, in one static binary.
//
// Phase 2 wires up config, the NOC enrollment/heartbeat link and the local UI
// with its setup wizard. Later phases attach the syslog store, SNMP poller and
// DDNS reporter onto the same Server/heartbeat scaffolding.
package main

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"log"
	"net/http"
	"os"
	"time"

	"github.com/samirgroup/sg-branch-agent/internal/config"
	"github.com/samirgroup/sg-branch-agent/internal/nocclient"
	"github.com/samirgroup/sg-branch-agent/internal/store"
	"github.com/samirgroup/sg-branch-agent/internal/syslog"
	"github.com/samirgroup/sg-branch-agent/internal/version"
	"github.com/samirgroup/sg-branch-agent/internal/web"
)

// minFreeBytes is the low-disk floor: below it the store stops ingesting and
// reports it, instead of filling the partition.
const minFreeBytes = 1 << 30 // 1 GiB

func main() {
	log.SetFlags(log.LstdFlags | log.Lmsgprefix)
	log.SetPrefix("[sg-agent] ")

	configPath := env("SG_AGENT_CONFIG", config.DefaultPath)
	cfg, err := config.Load(configPath)
	if err != nil {
		log.Fatalf("load config: %v", err)
	}

	// Dev/test overrides so the agent runs without root or /etc access.
	if v := os.Getenv("SG_AGENT_LISTEN"); v != "" {
		cfg.Listen = v
	}
	if v := os.Getenv("SG_AGENT_DATA_DIR"); v != "" {
		cfg.DataDir = v
	}

	// Local log store (daily-rolling SQLite) + syslog ingest + retention.
	st, err := store.Open(cfg.DataDir, minFreeBytes)
	if err != nil {
		log.Fatalf("open log store: %v", err)
	}
	defer st.Close()

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	syslogAddr := env("SG_AGENT_SYSLOG", ":514")
	sl := &syslog.Listener{Addr: syslogAddr, Store: st}
	if err := sl.Start(ctx); err != nil {
		log.Printf("syslog listener: %v (continuing; check the port/privileges)", err)
	}
	go retentionLoop(ctx, cfg, st)

	noc := nocclient.New(cfg.NOC.BaseURL, cfg.NOC.Token)

	// Until setup completes, mint a one-time token that the wizard requires.
	// Printed here so it shows up in `journalctl -u sg-branch-agent` and the
	// installer output.
	setupToken := ""
	if !cfg.SetupComplete {
		setupToken = randToken()
		log.Printf("SETUP REQUIRED — open http://<branch-ip>%s/setup", cfg.Listen)
		log.Printf("SETUP TOKEN: %s", setupToken)
	}

	srv, err := web.NewServer(cfg, noc, version.Version, setupToken)
	if err != nil {
		log.Fatalf("init web server: %v", err)
	}
	srv.Store = st

	started := time.Now()
	srv.Health = func() map[string]any { return collectHealth(started, st) }

	go heartbeatLoop(cfg, noc, started, st)

	httpSrv := &http.Server{
		Addr:              cfg.Listen,
		Handler:           srv.Handler(),
		ReadHeaderTimeout: 10 * time.Second,
	}
	log.Printf("sg-branch-agent %s listening on %s (data dir %s)", version.Version, cfg.Listen, cfg.DataDir)
	if err := httpSrv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		log.Fatalf("http server: %v", err)
	}
}

// heartbeatLoop periodically reports health to the NOC and applies any runtime
// config the NOC returns. It no-ops until the agent is linked.
func heartbeatLoop(cfg *config.Config, noc *nocclient.Client, started time.Time, st *store.Manager) {
	// Small initial delay so setup can complete first on a fresh box.
	time.Sleep(10 * time.Second)

	for {
		interval := cfg.HeartbeatInterval()

		if cfg.Linked() {
			ctx, cancel := context.WithTimeout(context.Background(), 25*time.Second)
			rc, err := noc.Heartbeat(ctx, version.Version, collectHealth(started, st))
			cancel()
			if err != nil {
				log.Printf("heartbeat: %v", err)
			} else if rc != nil {
				_ = cfg.Update(func(c *config.Config) { applyRuntimeConfig(c, rc) })
			}
		}

		time.Sleep(interval)
	}
}

// retentionLoop enforces the log retention policy hourly (and once shortly
// after boot). Dropping whole day files reclaims space immediately.
func retentionLoop(ctx context.Context, cfg *config.Config, st *store.Manager) {
	tick := time.NewTicker(time.Hour)
	defer tick.Stop()
	prune := func() {
		days, maxBytes := cfg.Retention()
		if removed := st.Prune(days, maxBytes); len(removed) > 0 {
			log.Printf("retention: removed %d day file(s): %v", len(removed), removed)
		}
	}
	// First pass a minute after boot, then hourly.
	select {
	case <-time.After(time.Minute):
		prune()
	case <-ctx.Done():
		return
	}
	for {
		select {
		case <-tick.C:
			prune()
		case <-ctx.Done():
			return
		}
	}
}

// applyRuntimeConfig mirrors web.applyRuntime, copying non-zero NOC values.
func applyRuntimeConfig(c *config.Config, rc *nocclient.RuntimeConfig) {
	if rc.LogRetentionDays > 0 {
		c.Runtime.LogRetentionDays = rc.LogRetentionDays
	}
	if rc.LogMaxTotalGB > 0 {
		c.Runtime.LogMaxTotalGB = rc.LogMaxTotalGB
	}
	if rc.SNMPPollIntervalS > 0 {
		c.Runtime.SNMPPollIntervalS = rc.SNMPPollIntervalS
	}
	if rc.DiscoveryIntervalS > 0 {
		c.Runtime.DiscoveryIntervalS = rc.DiscoveryIntervalS
	}
	if rc.HeartbeatIntervalS > 0 {
		c.Runtime.HeartbeatIntervalS = rc.HeartbeatIntervalS
	}
	if rc.DDNSCheckIntervalS > 0 {
		c.Runtime.DDNSCheckIntervalS = rc.DDNSCheckIntervalS
	}
}

// collectHealth builds the heartbeat health snapshot: version + uptime, disk/
// RAM (Linux), and the log-store summary (size, rows, ingest rate, drops).
func collectHealth(started time.Time, st *store.Manager) map[string]any {
	h := map[string]any{
		"agent_version": version.Version,
		"uptime_s":      int(time.Since(started).Seconds()),
	}
	enrichHealth(h) // platform-specific (disk/ram) where available
	if st != nil {
		s := st.Stats()
		h["db_size_gb"] = s.SizeGB
		h["db_rows"] = s.Rows
		h["log_rate_5min"] = s.RowsLast5Min
		h["dropped"] = s.Dropped
		if _, ok := h["disk_pct"]; !ok && s.DiskUsedPct > 0 {
			h["disk_pct"] = s.DiskUsedPct
		}
	}
	return h
}

func env(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

func randToken() string {
	b := make([]byte, 4)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}
