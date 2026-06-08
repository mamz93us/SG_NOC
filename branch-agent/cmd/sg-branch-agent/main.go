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
	"github.com/samirgroup/sg-branch-agent/internal/version"
	"github.com/samirgroup/sg-branch-agent/internal/web"
)

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

	started := time.Now()
	srv.Health = func() map[string]any { return collectHealth(started) }

	go heartbeatLoop(cfg, noc, started)

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
func heartbeatLoop(cfg *config.Config, noc *nocclient.Client, started time.Time) {
	// Small initial delay so setup can complete first on a fresh box.
	time.Sleep(10 * time.Second)

	for {
		interval := cfg.HeartbeatInterval()

		if cfg.Linked() {
			ctx, cancel := context.WithTimeout(context.Background(), 25*time.Second)
			rc, err := noc.Heartbeat(ctx, version.Version, collectHealth(started))
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

// collectHealth builds the heartbeat health snapshot. Disk/RAM/log-rate are
// filled in by later phases; Phase 2 reports version + uptime.
func collectHealth(started time.Time) map[string]any {
	h := map[string]any{
		"agent_version": version.Version,
		"uptime_s":      int(time.Since(started).Seconds()),
	}
	enrichHealth(h) // platform-specific (disk/ram) where available
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
