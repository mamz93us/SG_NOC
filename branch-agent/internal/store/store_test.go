package store

import (
	"testing"
	"time"
)

func writeAndSync(t *testing.T, dir string, entries []Entry) {
	t.Helper()
	m, err := Open(dir, 0)
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	for _, e := range entries {
		m.Write(e)
	}
	// Close drains the buffer and flushes — gives us a deterministic state.
	if err := m.Close(); err != nil {
		t.Fatalf("close: %v", err)
	}
}

func TestWriteSearchRoundTrip(t *testing.T) {
	dir := t.TempDir()
	now := time.Now().UTC()
	writeAndSync(t, dir, []Entry{
		{ReceivedAt: now, Source: "fw01", SourceIP: "10.3.0.1", Program: "sophos", Severity: 3, Message: "denied 1.2.3.4"},
		{ReceivedAt: now, Source: "ucm01", SourceIP: "10.3.0.2", Program: "asterisk", Severity: 6, Message: "call started"},
	})

	m, err := Open(dir, 0)
	if err != nil {
		t.Fatalf("reopen: %v", err)
	}
	defer m.Close()

	// Free-text filter.
	res := m.Search(map[string]string{"q": "denied"}, 100)
	if res.Total != 1 || len(res.Results) != 1 {
		t.Fatalf("q=denied → total %d, want 1", res.Total)
	}
	if res.Results[0].Program != "sophos" {
		t.Errorf("got program %q, want sophos", res.Results[0].Program)
	}

	// Severity filter.
	if r := m.Search(map[string]string{"severity": "6"}, 100); r.Total != 1 {
		t.Errorf("severity=6 → %d, want 1", r.Total)
	}

	// source_ip exact.
	if r := m.Search(map[string]string{"source_ip": "10.3.0.1"}, 100); r.Total != 1 {
		t.Errorf("source_ip filter → %d, want 1", r.Total)
	}

	// No filter → all.
	if r := m.Search(nil, 100); r.Total != 2 {
		t.Errorf("no filter → %d, want 2", r.Total)
	}
}

func TestAggregate(t *testing.T) {
	dir := t.TempDir()
	now := time.Now().UTC()
	writeAndSync(t, dir, []Entry{
		{ReceivedAt: now, Program: "sophos", Source: "fw01"},
		{ReceivedAt: now, Program: "sophos", Source: "fw01"},
		{ReceivedAt: now, Program: "asterisk", Source: "ucm01"},
	})

	m, _ := Open(dir, 0)
	defer m.Close()

	agg := m.Aggregate(map[string]string{"field": "program"}, 10)
	if len(agg.Buckets) != 2 {
		t.Fatalf("buckets = %d, want 2", len(agg.Buckets))
	}
	// Sorted by count desc → sophos(2) first.
	if agg.Buckets[0].Key != "sophos" || agg.Buckets[0].Count != 2 {
		t.Errorf("top bucket = %+v, want sophos/2", agg.Buckets[0])
	}
}

func TestPruneByAge(t *testing.T) {
	dir := t.TempDir()
	now := time.Now().UTC()
	old := now.AddDate(0, 0, -40)
	writeAndSync(t, dir, []Entry{
		{ReceivedAt: now, Program: "today", Message: "fresh"},
		{ReceivedAt: old, Program: "old", Message: "stale"},
	})

	m, _ := Open(dir, 0)
	defer m.Close()

	// Two day files should exist before pruning.
	if got := len(m.dayFiles()); got != 2 {
		t.Fatalf("day files before prune = %d, want 2", got)
	}

	removed := m.Prune(30, 0) // 30-day retention
	if len(removed) != 1 {
		t.Fatalf("removed %d files, want 1", len(removed))
	}
	if got := len(m.dayFiles()); got != 1 {
		t.Errorf("day files after prune = %d, want 1", got)
	}

	// The remaining data is today's.
	if r := m.Search(nil, 100); r.Total != 1 || r.Results[0].Program != "today" {
		t.Errorf("after prune search = %+v", r.Results)
	}
}
