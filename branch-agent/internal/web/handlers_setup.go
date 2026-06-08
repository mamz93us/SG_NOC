package web

import (
	"context"
	"net"
	"net/http"
	"os"
	"strings"
	"time"

	"github.com/samirgroup/sg-branch-agent/internal/config"
	"github.com/samirgroup/sg-branch-agent/internal/nocclient"
)

// handleSetupForm renders the first-run wizard. Once setup is complete the
// wizard is sealed off and redirects to the dashboard.
func (s *Server) handleSetupForm(w http.ResponseWriter, r *http.Request) {
	if s.Cfg.SetupComplete {
		http.Redirect(w, r, "/", http.StatusSeeOther)
		return
	}
	s.render(w, "setup.html", pageData{
		Title: "Set up branch agent",
		Data: map[string]any{
			"DetectedHost": outboundIP(),
		},
	})
}

// handleSetupSubmit processes the wizard: verifies the one-time setup token,
// sets the local admin password, enrolls with the NOC, and persists config.
func (s *Server) handleSetupSubmit(w http.ResponseWriter, r *http.Request) {
	if s.Cfg.SetupComplete {
		http.Redirect(w, r, "/", http.StatusSeeOther)
		return
	}
	if err := r.ParseForm(); err != nil {
		s.setupError(w, "Invalid form submission.")
		return
	}

	// Step 1 — bind setup to whoever holds the one-time token printed at boot.
	if s.SetupToken != "" && r.FormValue("setup_token") != s.SetupToken {
		s.setupError(w, "Setup token does not match the one printed in the installer output / journalctl.")
		return
	}

	// Step 1 — admin password.
	pw := r.FormValue("password")
	pw2 := r.FormValue("password_confirm")
	if len(pw) < 8 {
		s.setupError(w, "Admin password must be at least 8 characters.")
		return
	}
	if pw != pw2 {
		s.setupError(w, "Password confirmation does not match.")
		return
	}
	hash, err := hashPassword(pw)
	if err != nil {
		s.setupError(w, "Could not hash the password.")
		return
	}

	// Step 2 — link to the NOC via enrollment code.
	baseURL := strings.TrimSpace(r.FormValue("noc_url"))
	code := strings.TrimSpace(r.FormValue("enrollment_code"))
	if baseURL == "" || code == "" {
		s.setupError(w, "NOC URL and enrollment code are required.")
		return
	}

	ctx, cancel := context.WithTimeout(r.Context(), 25*time.Second)
	defer cancel()
	res, err := nocclient.Enroll(ctx, baseURL, code, outboundIP(), s.Version)
	if err != nil {
		s.setupError(w, "Enrollment failed: "+err.Error())
		return
	}

	// Steps 3–4 — monitoring + logs.
	community := strings.TrimSpace(r.FormValue("snmp_community"))
	if community == "" {
		community = "public"
	}
	subnets := splitLines(r.FormValue("scan_subnets"))

	// Persist everything atomically.
	if err := s.Cfg.Update(func(c *config.Config) {
		c.AdminPasswordHash = hash
		c.NOC.BaseURL = strings.TrimRight(baseURL, "/")
		c.NOC.Token = res.Token
		c.NOC.BranchCode = res.Branch.Code
		c.NOC.FQDN = res.Config.FQDN
		c.Monitoring.SNMPCommunity = community
		c.Monitoring.ScanSubnets = subnets
		applyRuntime(c, res.Config)
		c.SetupComplete = true
	}); err != nil {
		s.setupError(w, "Could not save configuration: "+err.Error())
		return
	}

	// Point the live NOC client at the new link and clear the setup token.
	s.NOC.SetToken(res.Token)
	s.SetupToken = ""

	// Log the operator straight in.
	id := s.sessions.create()
	setSessionCookie(w, id, sessionTTL)
	http.Redirect(w, r, "/", http.StatusSeeOther)
}

func (s *Server) setupError(w http.ResponseWriter, msg string) {
	s.render(w, "setup.html", pageData{
		Title: "Set up branch agent",
		Error: msg,
		Data:  map[string]any{"DetectedHost": outboundIP()},
	})
}

// applyRuntime copies the NOC-provided runtime config into local config,
// ignoring zero values so a partial payload never wipes good defaults.
func applyRuntime(c *config.Config, rc nocclient.RuntimeConfig) {
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

// outboundIP returns the IP the host would use to reach the internet, which is
// the agent's tunnel/LAN address the NOC should record. Falls back to hostname.
func outboundIP() string {
	conn, err := net.Dial("udp", "8.8.8.8:80")
	if err == nil {
		defer conn.Close()
		if a, ok := conn.LocalAddr().(*net.UDPAddr); ok {
			return a.IP.String()
		}
	}
	if h, err := os.Hostname(); err == nil {
		return h
	}
	return ""
}

func splitLines(s string) []string {
	var out []string
	for _, line := range strings.Split(s, "\n") {
		if v := strings.TrimSpace(line); v != "" {
			out = append(out, v)
		}
	}
	return out
}
