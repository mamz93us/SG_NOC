// Package store is the agent's local log store: daily-rolling SQLite files
// under <data>/logs/agent-YYYY-MM-DD.db.
//
// Why daily files instead of one growing DB: retention becomes "delete the old
// file" — instant, no DELETE + VACUUM lock, no 2x free-space requirement. This
// is the design choice that keeps a small branch VM from filling its disk (the
// failure mode that sank the previous collectors).
package store

import (
	"database/sql"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	_ "modernc.org/sqlite"
)

const (
	dayLayout    = "2006-01-02"
	tsLayout     = "2006-01-02 15:04:05"
	filePrefix   = "agent-"
	fileSuffix   = ".db"
	flushEvery   = time.Second
	flushMaxRows = 500
	chanBuffer   = 8192
)

// Entry is one parsed syslog record.
type Entry struct {
	ReceivedAt time.Time
	DeviceTime *time.Time
	Source     string
	SourceIP   string
	Program    string
	Facility   int
	Severity   int
	Message    string
	Raw        string
}

// Manager owns the day-file handles and the background writer.
type Manager struct {
	dir          string
	minFreeBytes uint64

	mu      sync.Mutex
	handles map[string]*sql.DB // date → db

	in      chan Entry
	dropped atomic.Int64
	closed  chan struct{}
	wg      sync.WaitGroup
}

// Open prepares the logs directory and starts the writer goroutine.
// minFreeBytes is the low-disk floor: below it, ingest is dropped (counted)
// rather than filling the partition.
func Open(dataDir string, minFreeBytes uint64) (*Manager, error) {
	dir := filepath.Join(dataDir, "logs")
	if err := os.MkdirAll(dir, 0o750); err != nil {
		return nil, fmt.Errorf("create logs dir: %w", err)
	}
	m := &Manager{
		dir:          dir,
		minFreeBytes: minFreeBytes,
		handles:      map[string]*sql.DB{},
		in:           make(chan Entry, chanBuffer),
		closed:       make(chan struct{}),
	}
	m.wg.Add(1)
	go m.writeLoop()
	return m, nil
}

// Write enqueues an entry. Non-blocking: if the buffer is full (writer behind)
// the entry is dropped and counted, so a burst never blocks the syslog reader.
func (m *Manager) Write(e Entry) {
	select {
	case m.in <- e:
	default:
		m.dropped.Add(1)
	}
}

// Dropped returns the count of entries dropped (buffer-full or low-disk).
func (m *Manager) Dropped() int64 { return m.dropped.Load() }

func (m *Manager) writeLoop() {
	defer m.wg.Done()
	ticker := time.NewTicker(flushEvery)
	defer ticker.Stop()

	batch := make([]Entry, 0, flushMaxRows)
	flush := func() {
		if len(batch) == 0 {
			return
		}
		if err := m.flush(batch); err != nil {
			// On a write failure, drop the batch rather than spin — the rows
			// are best-effort telemetry, not transactional data.
			m.dropped.Add(int64(len(batch)))
		}
		batch = batch[:0]
	}

	for {
		select {
		case <-m.closed:
			// Drain what's buffered, then exit.
			for {
				select {
				case e := <-m.in:
					batch = append(batch, e)
					if len(batch) >= flushMaxRows {
						flush()
					}
				default:
					flush()
					return
				}
			}
		case e := <-m.in:
			batch = append(batch, e)
			if len(batch) >= flushMaxRows {
				flush()
			}
		case <-ticker.C:
			flush()
		}
	}
}

// flush writes a batch into the appropriate day file(s). Entries are grouped
// by their received date so a batch spanning midnight lands correctly.
func (m *Manager) flush(batch []Entry) error {
	// Low-disk guard: refuse to grow the partition past the floor.
	if free, ok := diskFreeBytes(m.dir); ok && free < m.minFreeBytes {
		m.dropped.Add(int64(len(batch)))
		return nil
	}

	byDay := map[string][]Entry{}
	for _, e := range batch {
		day := e.ReceivedAt.UTC().Format(dayLayout)
		byDay[day] = append(byDay[day], e)
	}

	for day, entries := range byDay {
		db, err := m.dayDB(day)
		if err != nil {
			return err
		}
		tx, err := db.Begin()
		if err != nil {
			return err
		}
		stmt, err := tx.Prepare(`INSERT INTO logs
			(received_at, device_time, source, source_ip, program, facility, severity, message, raw)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`)
		if err != nil {
			_ = tx.Rollback()
			return err
		}
		for _, e := range entries {
			var dt any
			if e.DeviceTime != nil {
				dt = e.DeviceTime.UTC().Format(tsLayout)
			}
			if _, err := stmt.Exec(
				e.ReceivedAt.UTC().Format(tsLayout), dt, e.Source, e.SourceIP,
				e.Program, e.Facility, e.Severity, e.Message, e.Raw,
			); err != nil {
				_ = stmt.Close()
				_ = tx.Rollback()
				return err
			}
		}
		_ = stmt.Close()
		if err := tx.Commit(); err != nil {
			return err
		}
	}
	return nil
}

// dayDB returns (opening + caching) the handle for a given YYYY-MM-DD.
func (m *Manager) dayDB(day string) (*sql.DB, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	if db, ok := m.handles[day]; ok {
		return db, nil
	}
	path := filepath.Join(m.dir, filePrefix+day+fileSuffix)
	db, err := sql.Open("sqlite", path+"?_pragma=busy_timeout(5000)&_pragma=journal_mode(WAL)&_pragma=synchronous(NORMAL)")
	if err != nil {
		return nil, err
	}
	db.SetMaxOpenConns(1) // SQLite single-writer; serialise access
	if _, err := db.Exec(schema); err != nil {
		_ = db.Close()
		return nil, err
	}
	m.handles[day] = db
	return db, nil
}

const schema = `
CREATE TABLE IF NOT EXISTS logs (
	id          INTEGER PRIMARY KEY,
	received_at TEXT NOT NULL,
	device_time TEXT,
	source      TEXT,
	source_ip   TEXT,
	program     TEXT,
	facility    INTEGER,
	severity    INTEGER,
	message     TEXT,
	raw         TEXT
);
CREATE INDEX IF NOT EXISTS idx_logs_received ON logs(received_at);
CREATE INDEX IF NOT EXISTS idx_logs_severity ON logs(severity);
CREATE INDEX IF NOT EXISTS idx_logs_program  ON logs(program);
CREATE INDEX IF NOT EXISTS idx_logs_srcip    ON logs(source_ip);
`

// Close stops the writer and closes all handles.
func (m *Manager) Close() error {
	close(m.closed)
	m.wg.Wait()
	m.mu.Lock()
	defer m.mu.Unlock()
	for _, db := range m.handles {
		_ = db.Close()
	}
	return nil
}

// dayFiles returns the YYYY-MM-DD dates present on disk, newest first.
func (m *Manager) dayFiles() []string {
	entries, err := os.ReadDir(m.dir)
	if err != nil {
		return nil
	}
	var days []string
	for _, e := range entries {
		n := e.Name()
		if strings.HasPrefix(n, filePrefix) && strings.HasSuffix(n, fileSuffix) {
			days = append(days, strings.TrimSuffix(strings.TrimPrefix(n, filePrefix), fileSuffix))
		}
	}
	sort.Sort(sort.Reverse(sort.StringSlice(days)))
	return days
}

// openReadDB returns a handle for querying a day file. If the day is the cached
// writer handle it returns (db, fresh=false) — the caller must NOT close it.
// For an on-disk-only day it opens a read-only handle and returns fresh=true,
// which the caller must Close. Missing day → (nil, false).
func (m *Manager) openReadDB(day string) (db *sql.DB, fresh bool) {
	m.mu.Lock()
	if h, ok := m.handles[day]; ok {
		m.mu.Unlock()
		return h, false
	}
	m.mu.Unlock()

	path := filepath.Join(m.dir, filePrefix+day+fileSuffix)
	if _, err := os.Stat(path); err != nil {
		return nil, false
	}
	h, err := sql.Open("sqlite", path+"?_pragma=busy_timeout(5000)&mode=ro")
	if err != nil {
		return nil, false
	}
	return h, true
}
