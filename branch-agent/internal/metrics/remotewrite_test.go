package metrics

import (
	"encoding/binary"
	"math"
	"testing"
)

// Minimal proto reader to validate our hand-rolled encoder by decoding it back.

type pbReader struct {
	b   []byte
	pos int
}

func (r *pbReader) varint() uint64 {
	var x uint64
	var shift uint
	for r.pos < len(r.b) {
		c := r.b[r.pos]
		r.pos++
		x |= uint64(c&0x7f) << shift
		if c < 0x80 {
			break
		}
		shift += 7
	}
	return x
}

func (r *pbReader) field() (field uint64, wire uint64) {
	key := r.varint()
	return key >> 3, key & 7
}

func (r *pbReader) bytesField() []byte {
	n := int(r.varint())
	out := r.b[r.pos : r.pos+n]
	r.pos += n
	return out
}

func (r *pbReader) fixed64() uint64 {
	v := binary.LittleEndian.Uint64(r.b[r.pos : r.pos+8])
	r.pos += 8
	return v
}

func (r *pbReader) done() bool { return r.pos >= len(r.b) }

func TestEncodeWriteRequestRoundTrip(t *testing.T) {
	samples := []Sample{{
		Metric:      "snmp_up",
		Labels:      map[string]string{"host": "10.3.0.1", "branch": "jed"},
		Value:       1,
		TimestampMs: 1717843200000,
	}}

	body := encodeWriteRequest(samples)

	// Decode: WriteRequest → one TimeSeries (field 1).
	wr := &pbReader{b: body}
	f, wire := wr.field()
	if f != 1 || wire != 2 {
		t.Fatalf("WriteRequest first field = %d/%d, want 1/2", f, wire)
	}
	ts := &pbReader{b: wr.bytesField()}

	labels := map[string]string{}
	var gotValue float64
	var gotTs int64
	for !ts.done() {
		f, wire := ts.field()
		switch {
		case f == 1 && wire == 2: // Label
			lb := &pbReader{b: ts.bytesField()}
			var name, val string
			for !lb.done() {
				lf, _ := lb.field()
				if lf == 1 {
					name = string(lb.bytesField())
				} else {
					val = string(lb.bytesField())
				}
			}
			labels[name] = val
		case f == 2 && wire == 2: // Sample
			sm := &pbReader{b: ts.bytesField()}
			for !sm.done() {
				sf, sw := sm.field()
				if sf == 1 && sw == 1 {
					gotValue = math.Float64frombits(sm.fixed64())
				} else if sf == 2 {
					gotTs = int64(sm.varint())
				}
			}
		default:
			t.Fatalf("unexpected TimeSeries field %d/%d", f, wire)
		}
	}

	if labels["__name__"] != "snmp_up" {
		t.Errorf("__name__ = %q, want snmp_up", labels["__name__"])
	}
	if labels["host"] != "10.3.0.1" || labels["branch"] != "jed" {
		t.Errorf("labels = %v", labels)
	}
	if gotValue != 1 {
		t.Errorf("value = %v, want 1", gotValue)
	}
	if gotTs != 1717843200000 {
		t.Errorf("timestamp = %d", gotTs)
	}
}
