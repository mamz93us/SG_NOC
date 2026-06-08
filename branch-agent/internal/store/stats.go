package store

import (
	"os"
	"path/filepath"
	"time"
)

// Stats summarises the local store for the heartbeat and the NOC's /api/stats
// probe.
type Stats struct {
	SizeGB       float64 // total size of all day files
	Rows         int64   // total rows across day files
	RowsLast5Min int64   // ingest rate proxy
	Dropped      int64
	DiskUsedPct  int
}

// Stats computes the current store statistics.
func (m *Manager) Stats() Stats {
	st := Stats{Dropped: m.dropped.Load()}

	// Size: sum file sizes (cheap, no queries).
	var totalBytes int64
	if entries, err := os.ReadDir(m.dir); err == nil {
		for _, e := range entries {
			if info, err := e.Info(); err == nil {
				totalBytes += info.Size()
			}
		}
	}
	st.SizeGB = float64(totalBytes) / (1 << 30)

	// Rows + recent ingest: query each day file (rare call — only on probe).
	cutoff := time.Now().UTC().Add(-5 * time.Minute).Format(tsLayout)
	for _, day := range m.dayFiles() {
		db, fresh := m.openReadDB(day)
		if db == nil {
			continue
		}
		var n int64
		if err := db.QueryRow(`SELECT COUNT(*) FROM logs`).Scan(&n); err == nil {
			st.Rows += n
		}
		var recent int64
		if err := db.QueryRow(`SELECT COUNT(*) FROM logs WHERE received_at >= ?`, cutoff).Scan(&recent); err == nil {
			st.RowsLast5Min += recent
		}
		if fresh {
			_ = db.Close()
		}
	}

	if pct, ok := diskUsedPct(m.dir); ok {
		st.DiskUsedPct = pct
	}
	return st
}

// Prune enforces retention: drop whole day files older than retentionDays, and
// (oldest-first) until the total size is under maxTotalBytes. Returns the names
// of files removed. Dropping a whole file reclaims space instantly — no DELETE
// or VACUUM.
func (m *Manager) Prune(retentionDays int, maxTotalBytes int64) []string {
	var removed []string

	cutoff := time.Now().UTC().AddDate(0, 0, -retentionDays).Format(dayLayout)
	days := m.dayFiles() // newest first

	// 1) Age-based.
	for _, day := range days {
		if retentionDays > 0 && day < cutoff {
			if m.removeDay(day) {
				removed = append(removed, day)
			}
		}
	}

	// 2) Size-based: while total > cap, drop the oldest remaining.
	if maxTotalBytes > 0 {
		remaining := m.dayFiles() // refresh after age prune
		// oldest first
		for i := len(remaining) - 1; i >= 0; i-- {
			if m.totalBytes() <= maxTotalBytes {
				break
			}
			if m.removeDay(remaining[i]) {
				removed = append(removed, remaining[i])
			}
		}
	}
	return removed
}

func (m *Manager) totalBytes() int64 {
	var total int64
	entries, err := os.ReadDir(m.dir)
	if err != nil {
		return 0
	}
	for _, e := range entries {
		if info, err := e.Info(); err == nil {
			total += info.Size()
		}
	}
	return total
}

// removeDay closes any cached handle and deletes the day file plus its WAL/SHM
// sidecars.
func (m *Manager) removeDay(day string) bool {
	m.mu.Lock()
	if db, ok := m.handles[day]; ok {
		_ = db.Close()
		delete(m.handles, day)
	}
	m.mu.Unlock()

	base := filepath.Join(m.dir, filePrefix+day+fileSuffix)
	ok := false
	for _, suffix := range []string{"", "-wal", "-shm"} {
		if err := os.Remove(base + suffix); err == nil {
			ok = true
		}
	}
	return ok
}
