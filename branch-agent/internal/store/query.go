package store

import (
	"sort"
	"strconv"
	"strings"
)

// Row is one search result, JSON-shaped to match what the NOC's
// BranchLogClient + log views expect from a branch (received_at, source,
// source_ip, program, severity, message, ...).
type Row struct {
	ReceivedAt string `json:"received_at"`
	DeviceTime string `json:"device_time,omitempty"`
	Source     string `json:"source"`
	SourceIP   string `json:"source_ip"`
	Program    string `json:"program"`
	Facility   int    `json:"facility"`
	Severity   int    `json:"severity"`
	Message    string `json:"message"`
}

// SearchResult mirrors the branch log API contract.
type SearchResult struct {
	OK      bool  `json:"ok"`
	Results []Row `json:"results"`
	Total   int   `json:"total"`
}

// Bucket is one aggregate group.
type Bucket struct {
	Key   string `json:"key"`
	Count int    `json:"count"`
}

// AggregateResult mirrors the branch log aggregate contract.
type AggregateResult struct {
	OK      bool     `json:"ok"`
	Buckets []Bucket `json:"buckets"`
}

// Search runs the query across the day files overlapping [from,to], newest
// first, stopping once limit rows are collected. params are the same keys the
// NOC forwards: from, to, q, source, source_ip, program, severity.
func (m *Manager) Search(params map[string]string, limit int) SearchResult {
	if limit <= 0 || limit > 1000 {
		limit = 200
	}
	where, args := buildWhere(params)
	sqlStr := `SELECT received_at, COALESCE(device_time,''), COALESCE(source,''),
		COALESCE(source_ip,''), COALESCE(program,''), COALESCE(facility,0),
		COALESCE(severity,0), COALESCE(message,'') FROM logs` + where +
		` ORDER BY received_at DESC, id DESC LIMIT ?`

	var out []Row
	for _, day := range m.daysInRange(params["from"], params["to"]) {
		if len(out) >= limit {
			break
		}
		db, fresh := m.openReadDB(day)
		if db == nil {
			continue
		}
		rows, err := db.Query(sqlStr, append(append([]any{}, args...), limit-len(out))...)
		if err != nil {
			if fresh {
				_ = db.Close()
			}
			continue
		}
		for rows.Next() {
			var r Row
			if err := rows.Scan(&r.ReceivedAt, &r.DeviceTime, &r.Source, &r.SourceIP,
				&r.Program, &r.Facility, &r.Severity, &r.Message); err == nil {
				out = append(out, r)
			}
		}
		_ = rows.Close()
		if fresh {
			_ = db.Close()
		}
	}

	// Already per-file newest-first; merge-sort the combined set to be safe.
	sort.SliceStable(out, func(i, j int) bool { return out[i].ReceivedAt > out[j].ReceivedAt })
	if len(out) > limit {
		out = out[:limit]
	}
	return SearchResult{OK: true, Results: out, Total: len(out)}
}

// Aggregate groups by one column (source|source_ip|program|severity) and sums
// counts across day files.
func (m *Manager) Aggregate(params map[string]string, limit int) AggregateResult {
	if limit <= 0 || limit > 200 {
		limit = 30
	}
	col := aggColumn(params["field"])
	where, args := buildWhere(params)
	sqlStr := `SELECT COALESCE(` + col + `,'') AS k, COUNT(*) FROM logs` + where +
		` GROUP BY k`

	merged := map[string]int{}
	for _, day := range m.daysInRange(params["from"], params["to"]) {
		db, fresh := m.openReadDB(day)
		if db == nil {
			continue
		}
		rows, err := db.Query(sqlStr, args...)
		if err != nil {
			if fresh {
				_ = db.Close()
			}
			continue
		}
		for rows.Next() {
			var k string
			var c int
			if err := rows.Scan(&k, &c); err == nil {
				merged[k] += c
			}
		}
		_ = rows.Close()
		if fresh {
			_ = db.Close()
		}
	}

	buckets := make([]Bucket, 0, len(merged))
	for k, c := range merged {
		buckets = append(buckets, Bucket{Key: k, Count: c})
	}
	sort.Slice(buckets, func(i, j int) bool { return buckets[i].Count > buckets[j].Count })
	if len(buckets) > limit {
		buckets = buckets[:limit]
	}
	return AggregateResult{OK: true, Buckets: buckets}
}

// buildWhere assembles the WHERE clause + args from forwarded filters.
func buildWhere(p map[string]string) (string, []any) {
	var clauses []string
	var args []any

	if v := normTS(p["from"]); v != "" {
		clauses = append(clauses, "received_at >= ?")
		args = append(args, v)
	}
	if v := normTS(p["to"]); v != "" {
		clauses = append(clauses, "received_at <= ?")
		args = append(args, v)
	}
	if v := strings.TrimSpace(p["q"]); v != "" {
		clauses = append(clauses, "message LIKE ?")
		args = append(args, "%"+v+"%")
	}
	if v := strings.TrimSpace(p["source"]); v != "" {
		clauses = append(clauses, "source LIKE ?")
		args = append(args, "%"+v+"%")
	}
	if v := strings.TrimSpace(p["source_ip"]); v != "" {
		clauses = append(clauses, "source_ip = ?")
		args = append(args, v)
	}
	if v := strings.TrimSpace(p["program"]); v != "" {
		clauses = append(clauses, "program LIKE ?")
		args = append(args, "%"+v+"%")
	}
	if v := strings.TrimSpace(p["severity"]); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			clauses = append(clauses, "severity = ?")
			args = append(args, n)
		}
	}

	// Sophos-view filters — these live inside the raw message as KV pairs, so
	// they match against `message`. is_sophos narrows to firewall logs.
	// Sophos SFOS lines start with device="SFW" and carry log_type=; older /
	// other formats use log_component= or device_serial_id=. Match any of them
	// so the firewall view isn't empty when the device omits the latter two.
	if p["is_sophos"] == "1" {
		clauses = append(clauses, "(message LIKE ? OR message LIKE ? OR message LIKE ? OR message LIKE ?)")
		args = append(args, `%device="SFW"%`, "%log_type=%", "%log_component=%", "%device_serial_id=%")
	}
	if v := strings.TrimSpace(p["sophos_subtype"]); v != "" {
		clauses = append(clauses, "message LIKE ?")
		args = append(args, `%log_subtype="`+v+`"%`)
	}
	if v := strings.TrimSpace(p["sophos_src_ip"]); v != "" {
		clauses = append(clauses, "message LIKE ?")
		args = append(args, `%src_ip="`+v+`"%`)
	}
	if v := strings.TrimSpace(p["sophos_dst_ip"]); v != "" {
		clauses = append(clauses, "message LIKE ?")
		args = append(args, `%dst_ip="`+v+`"%`)
	}

	if len(clauses) == 0 {
		return "", nil
	}
	return " WHERE " + strings.Join(clauses, " AND "), args
}

func aggColumn(field string) string {
	switch field {
	case "source_ip":
		return "source_ip"
	case "program":
		return "program"
	case "severity":
		return "severity"
	default:
		return "source"
	}
}

// normTS accepts "YYYY-MM-DDTHH:MM[:SS]" (datetime-local) or already-spaced
// timestamps and returns the stored "YYYY-MM-DD HH:MM:SS" form.
func normTS(s string) string {
	s = strings.TrimSpace(s)
	if s == "" {
		return ""
	}
	s = strings.Replace(s, "T", " ", 1)
	if len(s) == 16 { // missing :ss
		s += ":00"
	}
	return s
}

// daysInRange returns the YYYY-MM-DD day files overlapping [from,to], newest
// first. Empty bounds mean all available days.
func (m *Manager) daysInRange(from, to string) []string {
	all := m.dayFiles()
	fromDay := dayPart(normTS(from))
	toDay := dayPart(normTS(to))
	if fromDay == "" && toDay == "" {
		return all
	}
	var out []string
	for _, d := range all {
		if fromDay != "" && d < fromDay {
			continue
		}
		if toDay != "" && d > toDay {
			continue
		}
		out = append(out, d)
	}
	return out
}

func dayPart(ts string) string {
	if len(ts) >= 10 {
		return ts[:10]
	}
	return ""
}
