package syslog

import (
	"strings"
	"testing"
	"time"
)

func TestParseRFC3164(t *testing.T) {
	now := time.Date(2026, 6, 8, 12, 0, 0, 0, time.UTC)
	e := Parse("<13>Jun  8 10:00:00 testhost myprog: hello world", "10.3.0.9", now)

	if e.Facility != 1 || e.Severity != 5 {
		t.Fatalf("PRI parse: facility=%d severity=%d (want 1/5)", e.Facility, e.Severity)
	}
	if e.Source != "testhost" {
		t.Errorf("source = %q, want testhost", e.Source)
	}
	if e.Program != "myprog" {
		t.Errorf("program = %q, want myprog", e.Program)
	}
	if e.Message != "hello world" {
		t.Errorf("message = %q, want 'hello world'", e.Message)
	}
	if e.SourceIP != "10.3.0.9" {
		t.Errorf("source_ip = %q, want 10.3.0.9", e.SourceIP)
	}
	if e.DeviceTime == nil || e.DeviceTime.Month() != time.June || e.DeviceTime.Day() != 8 {
		t.Errorf("device_time not parsed: %v", e.DeviceTime)
	}
}

func TestParseRFC3164WithPID(t *testing.T) {
	e := Parse("<11>Jun  8 10:00:01 fw01 sshd[1234]: failed login", "1.1.1.1", time.Now().UTC())
	if e.Program != "sshd" {
		t.Errorf("program = %q, want sshd (pid stripped)", e.Program)
	}
	if e.Severity != 3 {
		t.Errorf("severity = %d, want 3 (err)", e.Severity)
	}
	if e.Message != "failed login" {
		t.Errorf("message = %q", e.Message)
	}
}

func TestParseRFC5424(t *testing.T) {
	line := `<34>1 2026-06-08T22:14:15.003Z mymachine su 1234 ID47 - su root failed`
	e := Parse(line, "10.0.0.1", time.Now().UTC())
	if e.Source != "mymachine" {
		t.Errorf("source = %q, want mymachine", e.Source)
	}
	if e.Program != "su" {
		t.Errorf("program = %q, want su", e.Program)
	}
	if e.Message != "su root failed" {
		t.Errorf("message = %q, want 'su root failed'", e.Message)
	}
	if e.DeviceTime == nil {
		t.Errorf("device_time not parsed for 5424")
	}
}

func TestParseSophosKVPreservesMessage(t *testing.T) {
	// Sophos XGS lines are key="value" blobs with no BSD host/tag. The full
	// message (incl. leading device=/date=/time=) must survive so the NOC's
	// columnar view can parse src_ip, log_subtype, etc.
	line := `<134>device="SFW" date=2026-06-08 time=16:47:53 timezone="+0300" ` +
		`device_model="XGS2100" log_type="Content Filtering" log_subtype="Denied" ` +
		`fw_rule_id="22" src_ip="10.9.8.145" dst_ip="34.96.106.127" protocol="TCP"`
	e := Parse(line, "10.3.0.1", time.Now().UTC())

	for _, needle := range []string{`device="SFW"`, `src_ip="10.9.8.145"`, `log_subtype="Denied"`, `protocol="TCP"`} {
		if !strings.Contains(e.Message, needle) {
			t.Errorf("message lost %q; got %q", needle, e.Message)
		}
	}
	if e.SourceIP != "10.3.0.1" {
		t.Errorf("source_ip = %q", e.SourceIP)
	}
}

func TestParseFreeform(t *testing.T) {
	// No PRI, no recognisable header — must still land without losing data.
	raw := "just some text"
	e := Parse(raw, "2.2.2.2", time.Now().UTC())
	if e.Message == "" {
		t.Errorf("freeform message lost")
	}
	if e.Raw != raw {
		t.Errorf("raw not preserved: %q", e.Raw)
	}
	// Defaults applied when PRI absent.
	if e.Severity != 6 || e.Facility != 0 {
		t.Errorf("freeform defaults: facility=%d severity=%d (want 0/6)", e.Facility, e.Severity)
	}
}
