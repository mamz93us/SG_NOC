// Package web serves the agent's local UI (setup wizard, dashboard, settings)
// and, in later phases, the machine APIs the NOC queries (log search, stats).
package web

import (
	"embed"
	"html/template"
	"io/fs"
	"log"
	"net/http"
	"time"

	"github.com/samirgroup/sg-branch-agent/internal/config"
	"github.com/samirgroup/sg-branch-agent/internal/nocclient"
)

//go:embed templates/*.html
var templatesFS embed.FS

//go:embed static/*
var staticFS embed.FS

const sessionTTL = 12 * time.Hour

// Server holds everything the HTTP handlers need. Later phases attach the log
// store and SNMP manager via the exported fields.
type Server struct {
	Cfg        *config.Config
	NOC        *nocclient.Client
	Version    string
	SetupToken string // one-time, in-memory; printed at startup until setup completes

	sessions *sessionStore
	tmpl     *template.Template

	// Health returns the current health snapshot for the dashboard. main wires
	// this so the web layer stays decoupled from collectors.
	Health func() map[string]any
}

// NewServer parses templates and returns a ready server.
func NewServer(cfg *config.Config, noc *nocclient.Client, version, setupToken string) (*Server, error) {
	tmpl, err := template.New("").Funcs(template.FuncMap{
		"upper": func(s string) string { return s },
	}).ParseFS(templatesFS, "templates/*.html")
	if err != nil {
		return nil, err
	}
	return &Server{
		Cfg:        cfg,
		NOC:        noc,
		Version:    version,
		SetupToken: setupToken,
		sessions:   newSessionStore(sessionTTL),
		tmpl:       tmpl,
		Health:     func() map[string]any { return map[string]any{} },
	}, nil
}

// Handler builds the route table.
func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()

	// Static assets.
	sub, _ := fs.Sub(staticFS, "static")
	mux.Handle("GET /static/", http.StripPrefix("/static/", http.FileServer(http.FS(sub))))

	// Liveness — no auth (used by systemd / installer to confirm it's up).
	mux.HandleFunc("GET /healthz", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"ok":true}`))
	})

	// Setup wizard (only reachable until setup completes).
	mux.HandleFunc("GET /setup", s.handleSetupForm)
	mux.HandleFunc("POST /setup", s.handleSetupSubmit)

	// Auth.
	mux.HandleFunc("GET /login", s.handleLoginForm)
	mux.HandleFunc("POST /login", s.handleLoginSubmit)
	mux.HandleFunc("POST /logout", s.handleLogout)

	// Authenticated UI.
	mux.HandleFunc("GET /{$}", s.requireAuth(s.handleDashboard))
	mux.HandleFunc("GET /settings", s.requireAuth(s.handleSettingsForm))
	mux.HandleFunc("POST /settings", s.requireAuth(s.handleSettingsSubmit))

	return logRequests(mux)
}

// ─── Middleware ──────────────────────────────────────────────────────

// requireAuth gates a handler behind a valid session. If setup hasn't run yet
// it redirects to the wizard; otherwise to the login page.
func (s *Server) requireAuth(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if !s.Cfg.SetupComplete {
			http.Redirect(w, r, "/setup", http.StatusSeeOther)
			return
		}
		if c, err := r.Cookie(sessionCookie); err == nil && s.sessions.valid(c.Value) {
			next(w, r)
			return
		}
		http.Redirect(w, r, "/login", http.StatusSeeOther)
	}
}

func logRequests(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		start := time.Now()
		next.ServeHTTP(w, r)
		// Quietly skip noisy asset/liveness lines.
		if r.URL.Path == "/healthz" || hasPrefix(r.URL.Path, "/static/") {
			return
		}
		log.Printf("%s %s (%s)", r.Method, r.URL.Path, time.Since(start).Round(time.Millisecond))
	})
}

func hasPrefix(s, p string) bool { return len(s) >= len(p) && s[:len(p)] == p }

// ─── Render helper ───────────────────────────────────────────────────

type pageData struct {
	Title   string
	Version string
	Linked  bool
	Flash   string
	Error   string
	Data    map[string]any
}

func (s *Server) render(w http.ResponseWriter, name string, pd pageData) {
	pd.Version = s.Version
	pd.Linked = s.Cfg.Linked()
	if pd.Data == nil {
		pd.Data = map[string]any{}
	}
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	if err := s.tmpl.ExecuteTemplate(w, name, pd); err != nil {
		log.Printf("template %s: %v", name, err)
		http.Error(w, "template error", http.StatusInternalServerError)
	}
}
