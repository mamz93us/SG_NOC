// Package monitor orchestrates SNMP polling, metric push and subnet discovery
// using the device list the NOC serves.
package monitor

import (
	"context"
	"log"
	"time"

	"github.com/samirgroup/sg-branch-agent/internal/config"
	"github.com/samirgroup/sg-branch-agent/internal/metrics"
	"github.com/samirgroup/sg-branch-agent/internal/nocclient"
	"github.com/samirgroup/sg-branch-agent/internal/snmp"
)

// maxDiscoveryHosts caps a single discovery sweep so a wide subnet can't turn
// into a multi-thousand-host scan.
const maxDiscoveryHosts = 1024

// Monitor runs the device-monitoring loops.
type Monitor struct {
	cfg    *config.Config
	noc    *nocclient.Client
	poller *snmp.Poller
}

// New builds a Monitor bound to the branch code.
func New(cfg *config.Config, noc *nocclient.Client) *Monitor {
	return &Monitor{cfg: cfg, noc: noc, poller: snmp.NewPoller(cfg.BranchCode())}
}

// Statuses exposes the per-device status for the UI.
func (m *Monitor) Statuses() []snmp.Status { return m.poller.Statuses() }

// Summary returns up/down counts for the heartbeat.
func (m *Monitor) Summary() (up, down int) { return m.poller.Summary() }

// Run drives the poll + discovery loops until ctx is cancelled.
func (m *Monitor) Run(ctx context.Context) {
	pollTimer := time.NewTimer(15 * time.Second)
	discTimer := time.NewTimer(2 * time.Minute)
	defer pollTimer.Stop()
	defer discTimer.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-pollTimer.C:
			if m.cfg.Linked() {
				m.pollCycle(ctx)
			}
			pollTimer.Reset(m.cfg.PollInterval())
		case <-discTimer.C:
			if m.cfg.Linked() {
				m.discoveryCycle(ctx)
			}
			discTimer.Reset(m.cfg.DiscoveryInterval())
		}
	}
}

// pollCycle refreshes the device list, polls every device, and pushes the
// collected samples to VictoriaMetrics.
func (m *Monitor) pollCycle(ctx context.Context) {
	listCtx, cancel := context.WithTimeout(ctx, 15*time.Second)
	devices, err := m.noc.SnmpDevices(listCtx)
	cancel()
	if err != nil {
		log.Printf("monitor: device list: %v", err)
		return
	}

	var samples []metrics.Sample
	for _, d := range devices {
		s, _ := m.poller.Poll(snmp.Device{
			Name:      d.Name,
			Host:      d.Host,
			Community: d.SNMPCommunity,
			Version:   d.SNMPVersion,
			Port:      d.SNMPPort,
		})
		samples = append(samples, s...)
	}

	url, user, pass := m.cfg.MetricsTarget()
	if url == "" || len(samples) == 0 {
		return
	}
	w := metrics.NewWriter(url, user, pass)
	pushCtx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	if err := w.Write(pushCtx, samples); err != nil {
		log.Printf("monitor: remote_write: %v", err)
	}
}

// discoveryCycle scans the configured subnets and reports findings to the NOC.
func (m *Monitor) discoveryCycle(ctx context.Context) {
	community, version, subnets := m.cfg.SNMPParams()
	if len(subnets) == 0 {
		return
	}
	found := snmp.Scan(subnets, community, version, maxDiscoveryHosts)
	if len(found) == 0 {
		return
	}

	devices := make([]nocclient.DiscoveredDevice, 0, len(found))
	for _, d := range found {
		devices = append(devices, nocclient.DiscoveredDevice{
			Host:           d.Host,
			SysDescr:       d.SysDescr,
			SysName:        d.SysName,
			SNMPResponding: true,
		})
	}
	postCtx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()
	if err := m.noc.PostDiscovered(postCtx, devices); err != nil {
		log.Printf("monitor: post discovered: %v", err)
	} else {
		log.Printf("monitor: reported %d discovered device(s)", len(devices))
	}
}
