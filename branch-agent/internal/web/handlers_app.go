package web

import (
	"net/http"
	"strings"

	"github.com/samirgroup/sg-branch-agent/internal/config"
)

// ─── Auth ────────────────────────────────────────────────────────────

func (s *Server) handleLoginForm(w http.ResponseWriter, r *http.Request) {
	if !s.Cfg.SetupComplete {
		http.Redirect(w, r, "/setup", http.StatusSeeOther)
		return
	}
	s.render(w, "login.html", pageData{Title: "Sign in"})
}

func (s *Server) handleLoginSubmit(w http.ResponseWriter, r *http.Request) {
	if err := r.ParseForm(); err != nil {
		s.render(w, "login.html", pageData{Title: "Sign in", Error: "Invalid form."})
		return
	}
	if !checkPassword(s.Cfg.AdminPasswordHash, r.FormValue("password")) {
		s.render(w, "login.html", pageData{Title: "Sign in", Error: "Incorrect password."})
		return
	}
	id := s.sessions.create()
	setSessionCookie(w, id, sessionTTL)
	http.Redirect(w, r, "/", http.StatusSeeOther)
}

func (s *Server) handleLogout(w http.ResponseWriter, r *http.Request) {
	if c, err := r.Cookie(sessionCookie); err == nil {
		s.sessions.destroy(c.Value)
	}
	clearSessionCookie(w)
	http.Redirect(w, r, "/login", http.StatusSeeOther)
}

// ─── Dashboard ───────────────────────────────────────────────────────

func (s *Server) handleDashboard(w http.ResponseWriter, r *http.Request) {
	s.render(w, "dashboard.html", pageData{
		Title: "Dashboard",
		Data: map[string]any{
			"BranchCode": s.Cfg.NOC.BranchCode,
			"FQDN":       s.Cfg.NOC.FQDN,
			"NOCURL":     s.Cfg.NOC.BaseURL,
			"Health":     s.Health(),
			"Runtime":    s.Cfg.Runtime,
		},
	})
}

// ─── Settings ────────────────────────────────────────────────────────

func (s *Server) handleSettingsForm(w http.ResponseWriter, r *http.Request) {
	s.render(w, "settings.html", pageData{
		Title: "Settings",
		Flash: r.URL.Query().Get("saved"),
		Data: map[string]any{
			"SNMPCommunity": s.Cfg.Monitoring.SNMPCommunity,
			"SNMPVersion":   s.Cfg.Monitoring.SNMPVersion,
			"ScanSubnets":   strings.Join(s.Cfg.Monitoring.ScanSubnets, "\n"),
			"NOCURL":        s.Cfg.NOC.BaseURL,
			"BranchCode":    s.Cfg.NOC.BranchCode,
			"Runtime":       s.Cfg.Runtime,
		},
	})
}

func (s *Server) handleSettingsSubmit(w http.ResponseWriter, r *http.Request) {
	if err := r.ParseForm(); err != nil {
		http.Error(w, "bad form", http.StatusBadRequest)
		return
	}

	community := strings.TrimSpace(r.FormValue("snmp_community"))
	version := strings.TrimSpace(r.FormValue("snmp_version"))
	subnets := splitLines(r.FormValue("scan_subnets"))

	_ = s.Cfg.Update(func(c *config.Config) {
		if community != "" {
			c.Monitoring.SNMPCommunity = community
		}
		if version != "" {
			c.Monitoring.SNMPVersion = version
		}
		c.Monitoring.ScanSubnets = subnets
	})

	http.Redirect(w, r, "/settings?saved=Settings+saved.", http.StatusSeeOther)
}
