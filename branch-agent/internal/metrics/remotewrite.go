// Package metrics pushes time-series to VictoriaMetrics via the Prometheus
// remote_write protocol (snappy-compressed protobuf), matching the endpoint
// the branch Telegraf collectors already use (…/api/v1/write).
//
// The WriteRequest protobuf is small and stable, so we hand-encode it rather
// than pull in the (very large) prometheus/prometheus dependency:
//
//	WriteRequest { repeated TimeSeries timeseries = 1; }
//	TimeSeries   { repeated Label labels = 1; repeated Sample samples = 2; }
//	Label        { string name = 1; string value = 2; }
//	Sample       { double value = 1; int64 timestamp = 2; }
package metrics

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/binary"
	"fmt"
	"io"
	"math"
	"net/http"
	"sort"
	"time"

	"github.com/golang/snappy"
)

// Sample is one datapoint: a metric name, label set, value and ms timestamp.
type Sample struct {
	Metric      string
	Labels      map[string]string
	Value       float64
	TimestampMs int64
}

// Writer posts batches to a remote_write endpoint with optional basic auth.
type Writer struct {
	url      string
	username string
	password string
	http     *http.Client
}

// NewWriter builds a remote_write client. TLS is verified normally (the metrics
// host is a public HTTPS endpoint, unlike the in-tunnel NOC API).
func NewWriter(url, username, password string) *Writer {
	return &Writer{
		url:      url,
		username: username,
		password: password,
		http: &http.Client{
			Timeout:   30 * time.Second,
			Transport: &http.Transport{TLSClientConfig: &tls.Config{MinVersion: tls.VersionTLS12}},
		},
	}
}

// Write encodes, compresses and POSTs the samples. A nil/empty batch is a no-op.
func (w *Writer) Write(ctx context.Context, samples []Sample) error {
	if w.url == "" || len(samples) == 0 {
		return nil
	}
	body := encodeWriteRequest(samples)
	compressed := snappy.Encode(nil, body)

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, w.url, bytes.NewReader(compressed))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Encoding", "snappy")
	req.Header.Set("Content-Type", "application/x-protobuf")
	req.Header.Set("X-Prometheus-Remote-Write-Version", "0.1.0")
	if w.username != "" {
		req.SetBasicAuth(w.username, w.password)
	}

	resp, err := w.http.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode/100 != 2 {
		b, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return fmt.Errorf("remote_write HTTP %d: %s", resp.StatusCode, string(b))
	}
	return nil
}

// ─── Protobuf encoding (proto3 wire format) ──────────────────────────

func encodeWriteRequest(samples []Sample) []byte {
	var b []byte
	for _, s := range samples {
		b = appendMessageField(b, 1, encodeTimeSeries(s)) // WriteRequest.timeseries
	}
	return b
}

func encodeTimeSeries(s Sample) []byte {
	// Labels must include __name__ and be sorted by name (remote_write rule).
	labels := make([]label, 0, len(s.Labels)+1)
	labels = append(labels, label{"__name__", s.Metric})
	for k, v := range s.Labels {
		labels = append(labels, label{k, v})
	}
	sort.Slice(labels, func(i, j int) bool { return labels[i].name < labels[j].name })

	var b []byte
	for _, l := range labels {
		b = appendMessageField(b, 1, encodeLabel(l)) // TimeSeries.labels
	}
	ts := s.TimestampMs
	if ts == 0 {
		ts = time.Now().UnixMilli()
	}
	b = appendMessageField(b, 2, encodeSample(s.Value, ts)) // TimeSeries.samples
	return b
}

type label struct{ name, value string }

func encodeLabel(l label) []byte {
	var b []byte
	b = appendStringField(b, 1, l.name)
	b = appendStringField(b, 2, l.value)
	return b
}

func encodeSample(value float64, tsMs int64) []byte {
	var b []byte
	// field 1: double (wire type 1, fixed64)
	b = appendVarint(b, fieldKey(1, 1))
	var buf [8]byte
	binary.LittleEndian.PutUint64(buf[:], math.Float64bits(value))
	b = append(b, buf[:]...)
	// field 2: int64 (wire type 0, varint)
	b = appendVarint(b, fieldKey(2, 0))
	b = appendVarint(b, uint64(tsMs))
	return b
}

// ─── wire helpers ────────────────────────────────────────────────────

func fieldKey(field, wireType uint64) uint64 { return field<<3 | wireType }

func appendVarint(b []byte, v uint64) []byte {
	for v >= 0x80 {
		b = append(b, byte(v)|0x80)
		v >>= 7
	}
	return append(b, byte(v))
}

func appendStringField(b []byte, field uint64, s string) []byte {
	b = appendVarint(b, fieldKey(field, 2)) // length-delimited
	b = appendVarint(b, uint64(len(s)))
	return append(b, s...)
}

func appendMessageField(b []byte, field uint64, msg []byte) []byte {
	b = appendVarint(b, fieldKey(field, 2)) // length-delimited
	b = appendVarint(b, uint64(len(msg)))
	return append(b, msg...)
}
