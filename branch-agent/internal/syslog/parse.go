// Package syslog listens for device syslog (UDP+TCP) and writes parsed records
// to the local store.
package syslog

import (
	"strconv"
	"strings"
	"time"

	"github.com/samirgroup/sg-branch-agent/internal/store"
)

// Parse turns a raw syslog line into a store.Entry. srcIP is the network peer
// (authoritative — more reliable than the in-message hostname). now is the
// receive time. The parser is deliberately tolerant: anything it can't classify
// still lands as a message so no log is silently lost.
func Parse(raw, srcIP string, now time.Time) store.Entry {
	e := store.Entry{
		ReceivedAt: now,
		SourceIP:   srcIP,
		Raw:        raw,
		Facility:   -1,
		Severity:   -1,
	}

	s := raw
	// <PRI>
	if strings.HasPrefix(s, "<") {
		if idx := strings.IndexByte(s, '>'); idx > 1 && idx <= 5 {
			if pri, err := strconv.Atoi(s[1:idx]); err == nil {
				e.Facility = pri / 8
				e.Severity = pri % 8
			}
			s = s[idx+1:]
		}
	}

	switch {
	case strings.HasPrefix(s, "1 "): // RFC 5424
		parse5424(&e, s[2:], now)
	default: // RFC 3164 (BSD) or freeform
		parse3164(&e, s, now)
	}

	if e.Facility < 0 {
		e.Facility = 0
	}
	if e.Severity < 0 {
		e.Severity = 6 // info
	}
	if e.Source == "" {
		e.Source = srcIP
	}
	return e
}

// parse3164: "Mmm dd hh:mm:ss host tag[pid]: message"
func parse3164(e *store.Entry, s string, now time.Time) {
	if len(s) >= 15 {
		if t, err := time.Parse("Jan _2 15:04:05", s[:15]); err == nil {
			dt := time.Date(now.Year(), t.Month(), t.Day(), t.Hour(), t.Minute(), t.Second(), 0, time.UTC)
			e.DeviceTime = &dt
			s = strings.TrimSpace(s[15:])
		}
	}

	// host
	host, rest := nextToken(s)
	e.Source = host
	rest = strings.TrimSpace(rest)

	// tag[pid]: message
	e.Program, e.Message = splitTag(rest)
}

// parse5424: "timestamp host app procid msgid SD msg"
func parse5424(e *store.Entry, s string, now time.Time) {
	fields := strings.SplitN(s, " ", 6)
	if len(fields) < 6 {
		e.Message = s
		return
	}
	if t, err := time.Parse(time.RFC3339, fields[0]); err == nil {
		ut := t.UTC()
		e.DeviceTime = &ut
	}
	e.Source = nilDash(fields[1])
	e.Program = nilDash(fields[2])
	e.Message = stripStructuredData(fields[5])
}

// splitTag pulls "tag[pid]:" off the front, returning (program, message).
func splitTag(s string) (string, string) {
	if i := strings.IndexByte(s, ':'); i > 0 && i < 64 {
		tag := s[:i]
		msg := strings.TrimSpace(s[i+1:])
		// strip [pid] suffix from the tag
		if b := strings.IndexByte(tag, '['); b > 0 {
			tag = tag[:b]
		}
		if isTagLike(tag) {
			return tag, msg
		}
	}
	return "", strings.TrimSpace(s)
}

func isTagLike(t string) bool {
	if t == "" || len(t) > 48 {
		return false
	}
	for _, r := range t {
		if r == ' ' {
			return false
		}
	}
	return true
}

func nextToken(s string) (string, string) {
	s = strings.TrimSpace(s)
	if i := strings.IndexByte(s, ' '); i >= 0 {
		return s[:i], s[i+1:]
	}
	return s, ""
}

func nilDash(s string) string {
	if s == "-" {
		return ""
	}
	return s
}

// stripStructuredData removes a leading RFC5424 SD block ("-" or one/more
// "[...]") and returns the human message.
func stripStructuredData(s string) string {
	s = strings.TrimSpace(s)
	if strings.HasPrefix(s, "-") {
		return strings.TrimSpace(s[1:])
	}
	for strings.HasPrefix(s, "[") {
		depth := 0
		end := -1
		for i, r := range s {
			switch r {
			case '[':
				depth++
			case ']':
				depth--
				if depth == 0 {
					end = i
				}
			}
			if end == i {
				break
			}
		}
		if end < 0 {
			break
		}
		s = strings.TrimSpace(s[end+1:])
	}
	return s
}
