// Package nocclient is the agent's HTTP client for the NOC's
// /api/branch-agents/* and /api/branch-config/* endpoints.
package nocclient

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
)

// Client talks to one NOC base URL with one bearer token. Both are mutable so
// the caller can update them after enrollment without rebuilding the client.
type Client struct {
	baseURL string
	token   string
	http    *http.Client
}

// New builds a client. TLS verification is skipped because branch↔NOC traffic
// rides the IPsec tunnel (mirrors config('branches.http.verify_tls') = false).
func New(baseURL, token string) *Client {
	return &Client{
		baseURL: strings.TrimRight(baseURL, "/"),
		token:   token,
		http: &http.Client{
			Timeout: 20 * time.Second,
			Transport: &http.Transport{
				TLSClientConfig: &tls.Config{InsecureSkipVerify: true},
			},
		},
	}
}

// SetToken updates the bearer token (after enrollment).
func (c *Client) SetToken(t string) { c.token = t }

// RuntimeConfig is the config block the NOC returns on enroll/heartbeat.
type RuntimeConfig struct {
	BranchCode         string  `json:"branch_code"`
	FQDN               string  `json:"fqdn"`
	DDNSEnabled        bool    `json:"ddns_enabled"`
	LogRetentionDays   int     `json:"log_retention_days"`
	LogMaxTotalGB      float64 `json:"log_max_total_gb"`
	SNMPPollIntervalS  int     `json:"snmp_poll_interval_s"`
	DiscoveryIntervalS int     `json:"discovery_interval_s"`
	HeartbeatIntervalS int     `json:"heartbeat_interval_s"`
	DDNSCheckIntervalS int     `json:"ddns_check_interval_s"`

	MetricsURL      string `json:"metrics_url"`
	MetricsUser     string `json:"metrics_user"`
	MetricsPassword string `json:"metrics_password"`

	// Self-update: the NOC advertises the version it's hosting + where to get
	// it. When AutoUpdate is on and AgentTargetVersion differs from the running
	// version, the agent downloads + swaps itself and restarts.
	AutoUpdate         bool   `json:"auto_update"`
	AgentTargetVersion string `json:"agent_target_version"`
	AgentBinaryURL     string `json:"agent_binary_url"`
	AgentBinarySHA256  string `json:"agent_binary_sha256"`
}

// EnrollResult is the response to a successful enrollment.
type EnrollResult struct {
	OK     bool   `json:"ok"`
	Token  string `json:"token"`
	Branch struct {
		Code string `json:"code"`
		Name string `json:"name"`
	} `json:"branch"`
	Config RuntimeConfig `json:"config"`
}

// Enroll performs the one-time handshake. baseURL is taken as-is (the agent
// isn't linked yet, so we don't use c.baseURL). Returns the issued token.
func Enroll(ctx context.Context, baseURL, code, hostname, agentVersion string) (*EnrollResult, error) {
	tmp := New(baseURL, "")
	body := map[string]string{
		"code":          code,
		"hostname":      hostname,
		"agent_version": agentVersion,
	}
	var out EnrollResult
	if err := tmp.do(ctx, http.MethodPost, "/api/branch-agents/enroll", body, &out); err != nil {
		return nil, err
	}
	if !out.OK || out.Token == "" {
		return nil, fmt.Errorf("enroll rejected by NOC")
	}
	return &out, nil
}

// Heartbeat posts a health snapshot and returns the latest runtime config.
func (c *Client) Heartbeat(ctx context.Context, agentVersion string, health map[string]any) (*RuntimeConfig, error) {
	body := map[string]any{
		"agent_version": agentVersion,
		"health":        health,
	}
	var out struct {
		OK     bool          `json:"ok"`
		Config RuntimeConfig `json:"config"`
	}
	if err := c.do(ctx, http.MethodPost, "/api/branch-agents/heartbeat", body, &out); err != nil {
		return nil, err
	}
	return &out.Config, nil
}

// FetchConfig pulls the runtime config without sending health.
func (c *Client) FetchConfig(ctx context.Context) (*RuntimeConfig, error) {
	var out struct {
		OK     bool          `json:"ok"`
		Config RuntimeConfig `json:"config"`
	}
	if err := c.do(ctx, http.MethodGet, "/api/branch-agents/config", nil, &out); err != nil {
		return nil, err
	}
	return &out.Config, nil
}

// do performs an authenticated JSON request and decodes into out (if non-nil).
func (c *Client) do(ctx context.Context, method, path string, body any, out any) error {
	var reader io.Reader
	if body != nil {
		buf, err := json.Marshal(body)
		if err != nil {
			return fmt.Errorf("marshal body: %w", err)
		}
		reader = bytes.NewReader(buf)
	}

	url := c.baseURL + path
	req, err := http.NewRequestWithContext(ctx, method, url, reader)
	if err != nil {
		return fmt.Errorf("build request: %w", err)
	}
	req.Header.Set("Accept", "application/json")
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	if c.token != "" {
		req.Header.Set("Authorization", "Bearer "+c.token)
	}

	resp, err := c.http.Do(req)
	if err != nil {
		return fmt.Errorf("request %s %s: %w", method, path, err)
	}
	defer resp.Body.Close()

	data, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("NOC %s %s → HTTP %d: %s", method, path, resp.StatusCode, snippet(data))
	}
	if out != nil {
		if err := json.Unmarshal(data, out); err != nil {
			return fmt.Errorf("decode response: %w", err)
		}
	}
	return nil
}

func snippet(b []byte) string {
	s := strings.TrimSpace(string(b))
	if len(s) > 300 {
		return s[:300]
	}
	return s
}
