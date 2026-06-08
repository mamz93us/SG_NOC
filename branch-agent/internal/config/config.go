// Package config loads and persists the agent's on-disk configuration.
//
// The whole point of the single-binary design is that the operator never
// hand-edits this file: the web setup wizard writes it. We still use YAML so
// that a human *can* read it for debugging.
package config

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"os"
	"path/filepath"
	"sync"
	"time"

	"gopkg.in/yaml.v3"
)

// Default on-disk locations (Linux/Ubuntu 24.04 target). Overridable via env
// for local development.
const (
	DefaultPath    = "/etc/sg-branch-agent/config.yaml"
	DefaultDataDir = "/var/lib/sg-branch-agent"
)

// NOC holds the link to the central NOC.
type NOC struct {
	BaseURL    string `yaml:"base_url"`
	Token      string `yaml:"token"` // issued at enrollment
	BranchCode string `yaml:"branch_code"`
	FQDN       string `yaml:"fqdn"`
}

// Monitoring holds SNMP/discovery settings the agent applies locally.
type Monitoring struct {
	SNMPCommunity string   `yaml:"snmp_community"`
	SNMPVersion   string   `yaml:"snmp_version"`
	ScanSubnets   []string `yaml:"scan_subnets"`
}

// Runtime mirrors the config block the NOC serves back on enroll/heartbeat.
// Intervals are seconds.
type Runtime struct {
	LogRetentionDays   int     `yaml:"log_retention_days"`
	LogMaxTotalGB      float64 `yaml:"log_max_total_gb"`
	SNMPPollIntervalS  int     `yaml:"snmp_poll_interval_s"`
	DiscoveryIntervalS int     `yaml:"discovery_interval_s"`
	HeartbeatIntervalS int     `yaml:"heartbeat_interval_s"`
	DDNSCheckIntervalS int     `yaml:"ddns_check_interval_s"`

	// VictoriaMetrics remote_write target (NOC-managed: pushed in the config
	// payload so creds aren't hand-entered on each branch).
	MetricsURL      string `yaml:"metrics_url"`
	MetricsUser     string `yaml:"metrics_user"`
	MetricsPassword string `yaml:"metrics_password"`
}

// Config is the full agent configuration.
type Config struct {
	Listen            string     `yaml:"listen"`
	DataDir           string     `yaml:"data_dir"`
	SetupComplete     bool       `yaml:"setup_complete"`
	AdminPasswordHash string     `yaml:"admin_password_hash"`
	SessionSecret     string     `yaml:"session_secret"`
	NOC               NOC        `yaml:"noc"`
	Monitoring        Monitoring `yaml:"monitoring"`
	Runtime           Runtime    `yaml:"runtime"`

	path string       // where this was loaded from / saves to
	mu   sync.RWMutex // guards concurrent Save() from background loops + HTTP
}

// Defaults returns a fresh config with sane starting values.
func Defaults() *Config {
	return &Config{
		Listen:        ":8080",
		DataDir:       DefaultDataDir,
		SetupComplete: false,
		Monitoring: Monitoring{
			SNMPCommunity: "public",
			SNMPVersion:   "2c",
		},
		Runtime: Runtime{
			LogRetentionDays:   30,
			LogMaxTotalGB:      50,
			SNMPPollIntervalS:  60,
			DiscoveryIntervalS: 3600,
			HeartbeatIntervalS: 60,
			DDNSCheckIntervalS: 300,
		},
	}
}

// Load reads the config from path. A missing file is not an error: it returns
// defaults bound to that path so the first Save() creates it. On first ever
// load it also generates a session secret.
func Load(path string) (*Config, error) {
	c := Defaults()
	c.path = path

	data, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			if err := c.ensureSessionSecret(); err != nil {
				return nil, err
			}
			return c, c.Save()
		}
		return nil, fmt.Errorf("read config: %w", err)
	}

	if err := yaml.Unmarshal(data, c); err != nil {
		return nil, fmt.Errorf("parse config: %w", err)
	}
	c.path = path
	if err := c.ensureSessionSecret(); err != nil {
		return nil, err
	}
	return c, nil
}

func (c *Config) ensureSessionSecret() error {
	if c.SessionSecret != "" {
		return nil
	}
	b := make([]byte, 32)
	if _, err := rand.Read(b); err != nil {
		return fmt.Errorf("generate session secret: %w", err)
	}
	c.SessionSecret = hex.EncodeToString(b)
	return nil
}

// Save writes the config atomically with 0600 perms (it holds the NOC token
// and admin hash). Parent dir is created if missing.
func (c *Config) Save() error {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.saveLocked()
}

func (c *Config) saveLocked() error {
	if c.path == "" {
		return fmt.Errorf("config has no path")
	}
	if err := os.MkdirAll(filepath.Dir(c.path), 0o750); err != nil {
		return fmt.Errorf("create config dir: %w", err)
	}

	out, err := yaml.Marshal(c)
	if err != nil {
		return fmt.Errorf("marshal config: %w", err)
	}

	tmp := c.path + ".tmp"
	if err := os.WriteFile(tmp, out, 0o600); err != nil {
		return fmt.Errorf("write temp config: %w", err)
	}
	if err := os.Rename(tmp, c.path); err != nil {
		return fmt.Errorf("replace config: %w", err)
	}
	return nil
}

// Update runs fn under the write lock and persists the result. Use this for
// any mutation so concurrent background loops don't race the HTTP handlers.
func (c *Config) Update(fn func(*Config)) error {
	c.mu.Lock()
	defer c.mu.Unlock()
	fn(c)
	return c.saveLocked()
}

// Linked reports whether the agent has a NOC token (i.e. is enrolled).
func (c *Config) Linked() bool {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.NOC.BaseURL != "" && c.NOC.Token != ""
}

// HeartbeatInterval returns the configured heartbeat period under the read
// lock (the loop reads this every cycle while Update may rewrite it). Falls
// back to 60s if misconfigured.
func (c *Config) HeartbeatInterval() time.Duration {
	c.mu.RLock()
	defer c.mu.RUnlock()
	d := time.Duration(c.Runtime.HeartbeatIntervalS) * time.Second
	if d < 15*time.Second {
		return 60 * time.Second
	}
	return d
}

// NOCToken returns the issued NOC token under the read lock. This is also the
// shared secret the NOC presents when querying the agent's log API.
func (c *Config) NOCToken() string {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.NOC.Token
}

// Retention returns the log retention policy under the read lock: max age in
// days and max total bytes across day files.
func (c *Config) Retention() (days int, maxBytes int64) {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.Runtime.LogRetentionDays, int64(c.Runtime.LogMaxTotalGB * (1 << 30))
}

// MetricsTarget returns the VictoriaMetrics remote_write URL + basic-auth.
func (c *Config) MetricsTarget() (url, user, pass string) {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.Runtime.MetricsURL, c.Runtime.MetricsUser, c.Runtime.MetricsPassword
}

// SNMPParams returns the local SNMP scan settings (community, version, subnets).
func (c *Config) SNMPParams() (community, version string, subnets []string) {
	c.mu.RLock()
	defer c.mu.RUnlock()
	subs := append([]string(nil), c.Monitoring.ScanSubnets...)
	return c.Monitoring.SNMPCommunity, c.Monitoring.SNMPVersion, subs
}

// PollInterval / DiscoveryInterval return the SNMP loop cadences (with floors).
func (c *Config) PollInterval() time.Duration {
	c.mu.RLock()
	defer c.mu.RUnlock()
	d := time.Duration(c.Runtime.SNMPPollIntervalS) * time.Second
	if d < 15*time.Second {
		return 60 * time.Second
	}
	return d
}

func (c *Config) DiscoveryInterval() time.Duration {
	c.mu.RLock()
	defer c.mu.RUnlock()
	d := time.Duration(c.Runtime.DiscoveryIntervalS) * time.Second
	if d < time.Minute {
		return time.Hour
	}
	return d
}

func (c *Config) DDNSInterval() time.Duration {
	c.mu.RLock()
	defer c.mu.RUnlock()
	d := time.Duration(c.Runtime.DDNSCheckIntervalS) * time.Second
	if d < 60*time.Second {
		return 5 * time.Minute
	}
	return d
}

// BranchCode returns the enrolled branch code under the read lock.
func (c *Config) BranchCode() string {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.NOC.BranchCode
}

// LogsDir is where the daily-rolling SQLite log files live.
func (c *Config) LogsDir() string {
	return filepath.Join(c.DataDir, "logs")
}
