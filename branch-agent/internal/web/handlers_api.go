package web

import (
	"crypto/subtle"
	"encoding/json"
	"net/http"
	"os"
	"strconv"
	"strings"
)

// tokenAuth gates the machine endpoints the NOC calls (log search, stats).
// The shared secret is the same api_token issued at enrollment.
func (s *Server) tokenAuth(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		want := s.Cfg.NOCToken()
		got := bearer(r)
		if want == "" || got == "" || subtle.ConstantTimeCompare([]byte(want), []byte(got)) != 1 {
			writeJSON(w, http.StatusUnauthorized, map[string]any{"ok": false, "error": "unauthorized"})
			return
		}
		next(w, r)
	}
}

func bearer(r *http.Request) string {
	h := r.Header.Get("Authorization")
	if strings.HasPrefix(h, "Bearer ") {
		return strings.TrimSpace(h[7:])
	}
	return ""
}

// handleAPISearch — GET /api/logs/search. Mirrors the branch-vm contract the
// NOC's BranchLogClient expects: {ok, results, total}.
func (s *Server) handleAPISearch(w http.ResponseWriter, r *http.Request) {
	if s.Store == nil {
		writeJSON(w, http.StatusOK, map[string]any{"ok": true, "results": []any{}, "total": 0})
		return
	}
	params := queryParams(r)
	limit := atoiDefault(r.URL.Query().Get("limit"), 200)
	writeJSON(w, http.StatusOK, s.Store.Search(params, limit))
}

// handleAPIAggregate — GET /api/logs/aggregate → {ok, buckets}.
func (s *Server) handleAPIAggregate(w http.ResponseWriter, r *http.Request) {
	if s.Store == nil {
		writeJSON(w, http.StatusOK, map[string]any{"ok": true, "buckets": []any{}})
		return
	}
	params := queryParams(r)
	limit := atoiDefault(r.URL.Query().Get("limit"), 30)
	writeJSON(w, http.StatusOK, s.Store.Aggregate(params, limit))
}

// handleAPIStats — GET /api/stats. Shape consumed by the NOC's
// BranchLogCollectorController test/refresh.
func (s *Server) handleAPIStats(w http.ResponseWriter, r *http.Request) {
	hostname, _ := os.Hostname()
	resp := map[string]any{
		"ok":   true,
		"host": hostname,
	}
	if s.Store != nil {
		st := s.Store.Stats()
		resp["disk"] = map[string]any{"used_pct": st.DiskUsedPct}
		resp["db"] = map[string]any{"size_gb": round1(st.SizeGB), "rows": st.Rows}
		resp["ingestion"] = map[string]any{"rows_last_5min": st.RowsLast5Min, "dropped": st.Dropped}
	}
	// RAM comes from the platform health snapshot (Linux only).
	if h := s.Health(); h != nil {
		if ram, ok := h["ram_pct"]; ok {
			resp["ram"] = map[string]any{"used_pct": ram}
		}
	}
	writeJSON(w, http.StatusOK, resp)
}

// queryParams pulls the forwarded log filters from the query string.
func queryParams(r *http.Request) map[string]string {
	q := r.URL.Query()
	keys := []string{"from", "to", "q", "source", "source_ip", "program", "severity", "field"}
	out := map[string]string{}
	for _, k := range keys {
		if v := q.Get(k); v != "" {
			out[k] = v
		}
	}
	return out
}

func writeJSON(w http.ResponseWriter, code int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(v)
}

func atoiDefault(s string, def int) int {
	if n, err := strconv.Atoi(s); err == nil {
		return n
	}
	return def
}

func round1(f float64) float64 {
	return float64(int(f*10+0.5)) / 10
}
