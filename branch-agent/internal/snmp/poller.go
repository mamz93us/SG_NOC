// Package snmp polls the branch's managed devices and reports metrics +
// up/down status. The device list comes from the NOC; results are pushed to
// VictoriaMetrics and summarised in the heartbeat / Devices UI.
package snmp

import (
	"fmt"
	"strings"
	"sync"
	"time"

	"github.com/gosnmp/gosnmp"
	"github.com/samirgroup/sg-branch-agent/internal/metrics"
)

// Standard OIDs.
const (
	oidSysDescr      = "1.3.6.1.2.1.1.1.0"
	oidSysUpTime     = "1.3.6.1.2.1.1.3.0"
	oidSysName       = "1.3.6.1.2.1.1.5.0"
	oidIfName        = "1.3.6.1.2.1.31.1.1.1.1"
	oidIfHCInOctets  = "1.3.6.1.2.1.31.1.1.1.6"
	oidIfHCOutOctets = "1.3.6.1.2.1.31.1.1.1.10"
)

// Device is one host to poll.
type Device struct {
	Name      string
	Host      string
	Community string
	Version   string
	Port      int
}

// Status is the latest poll outcome for a device (for the UI / heartbeat).
type Status struct {
	Name     string    `json:"name"`
	Host     string    `json:"host"`
	Up       bool      `json:"up"`
	SysName  string    `json:"sys_name,omitempty"`
	SysDescr string    `json:"sys_descr,omitempty"`
	LastPoll time.Time `json:"last_poll"`
	Err      string    `json:"err,omitempty"`
}

// Poller holds per-device status across polls.
type Poller struct {
	Branch string

	mu     sync.Mutex
	status map[string]*Status // host → status
}

// NewPoller creates a poller tagging metrics with the branch code.
func NewPoller(branch string) *Poller {
	return &Poller{Branch: branch, status: map[string]*Status{}}
}

// Poll queries one device and returns its samples plus the updated status.
func (p *Poller) Poll(d Device) ([]metrics.Sample, Status) {
	now := time.Now()
	st := Status{Name: d.Name, Host: d.Host, LastPoll: now}
	baseLabels := map[string]string{"host": d.Host, "name": d.Name, "branch": p.Branch}

	client := &gosnmp.GoSNMP{
		Target:    d.Host,
		Port:      uint16(portOr(d.Port)),
		Community: communityOr(d.Community),
		Version:   snmpVersion(d.Version),
		Timeout:   3 * time.Second,
		Retries:   1,
		MaxOids:   gosnmp.MaxOids,
	}

	var samples []metrics.Sample
	if err := client.Connect(); err != nil {
		st.Up = false
		st.Err = err.Error()
		samples = append(samples, upSample(baseLabels, false, now))
		p.record(&st)
		return samples, st
	}
	defer client.Conn.Close()

	// System scalars.
	res, err := client.Get([]string{oidSysUpTime, oidSysName, oidSysDescr})
	if err != nil || res == nil || len(res.Variables) == 0 {
		st.Up = false
		if err != nil {
			st.Err = err.Error()
		} else {
			st.Err = "no SNMP response"
		}
		samples = append(samples, upSample(baseLabels, false, now))
		p.record(&st)
		return samples, st
	}

	st.Up = true
	samples = append(samples, upSample(baseLabels, true, now))
	for _, v := range res.Variables {
		switch {
		case strings.HasPrefix(v.Name, oidSysUpTime):
			if secs, ok := timeTicksSeconds(v); ok {
				samples = append(samples, metrics.Sample{
					Metric: "snmp_sysuptime_seconds", Labels: baseLabels, Value: secs, TimestampMs: now.UnixMilli(),
				})
			}
		case strings.HasPrefix(v.Name, oidSysName):
			st.SysName = toStr(v)
		case strings.HasPrefix(v.Name, oidSysDescr):
			st.SysDescr = toStr(v)
		}
	}

	// Interface counters (best-effort; ignore walk errors).
	ifNames := walkStrings(client, oidIfName)
	for idx, val := range walkCounters(client, oidIfHCInOctets) {
		samples = append(samples, ifSample("snmp_if_in_octets", baseLabels, ifNames, idx, val, now))
	}
	for idx, val := range walkCounters(client, oidIfHCOutOctets) {
		samples = append(samples, ifSample("snmp_if_out_octets", baseLabels, ifNames, idx, val, now))
	}

	p.record(&st)
	return samples, st
}

// Statuses returns a snapshot of all device statuses.
func (p *Poller) Statuses() []Status {
	p.mu.Lock()
	defer p.mu.Unlock()
	out := make([]Status, 0, len(p.status))
	for _, s := range p.status {
		out = append(out, *s)
	}
	return out
}

// Summary returns up/down counts for the heartbeat.
func (p *Poller) Summary() (up, down int) {
	p.mu.Lock()
	defer p.mu.Unlock()
	for _, s := range p.status {
		if s.Up {
			up++
		} else {
			down++
		}
	}
	return
}

func (p *Poller) record(st *Status) {
	p.mu.Lock()
	p.status[st.Host] = st
	p.mu.Unlock()
}

// ─── helpers ─────────────────────────────────────────────────────────

func ifSample(metric string, base map[string]string, names map[string]string, ifIndex string, val float64, now time.Time) metrics.Sample {
	labels := map[string]string{"ifindex": ifIndex}
	for k, v := range base {
		labels[k] = v
	}
	if n, ok := names[ifIndex]; ok && n != "" {
		labels["ifname"] = n
	}
	return metrics.Sample{Metric: metric, Labels: labels, Value: val, TimestampMs: now.UnixMilli()}
}

func upSample(base map[string]string, up bool, now time.Time) metrics.Sample {
	v := 0.0
	if up {
		v = 1
	}
	return metrics.Sample{Metric: "snmp_up", Labels: base, Value: v, TimestampMs: now.UnixMilli()}
}

func walkStrings(c *gosnmp.GoSNMP, root string) map[string]string {
	out := map[string]string{}
	_ = c.BulkWalk(root, func(pdu gosnmp.SnmpPDU) error {
		out[lastIndex(pdu.Name, root)] = toStr(pdu)
		return nil
	})
	return out
}

func walkCounters(c *gosnmp.GoSNMP, root string) map[string]float64 {
	out := map[string]float64{}
	_ = c.BulkWalk(root, func(pdu gosnmp.SnmpPDU) error {
		if f, ok := toFloat(pdu); ok {
			out[lastIndex(pdu.Name, root)] = f
		}
		return nil
	})
	return out
}

func lastIndex(oid, root string) string {
	s := strings.TrimPrefix(oid, root)
	s = strings.TrimPrefix(s, ".")
	if i := strings.IndexByte(s, '.'); i >= 0 {
		return s[:i]
	}
	return s
}

func toStr(v gosnmp.SnmpPDU) string {
	switch x := v.Value.(type) {
	case []byte:
		return string(x)
	case string:
		return x
	default:
		return fmt.Sprintf("%v", x)
	}
}

func toFloat(v gosnmp.SnmpPDU) (float64, bool) {
	switch x := v.Value.(type) {
	case int:
		return float64(x), true
	case int64:
		return float64(x), true
	case uint:
		return float64(x), true
	case uint64:
		return float64(x), true
	case uint32:
		return float64(x), true
	default:
		return 0, false
	}
}

func timeTicksSeconds(v gosnmp.SnmpPDU) (float64, bool) {
	if f, ok := toFloat(v); ok {
		return f / 100.0, true // TimeTicks are hundredths of a second
	}
	return 0, false
}

func portOr(p int) int {
	if p <= 0 {
		return 161
	}
	return p
}

func communityOr(c string) string {
	if c == "" {
		return "public"
	}
	return c
}

func snmpVersion(v string) gosnmp.SnmpVersion {
	switch strings.TrimSpace(v) {
	case "1":
		return gosnmp.Version1
	case "3":
		return gosnmp.Version3
	default:
		return gosnmp.Version2c
	}
}
