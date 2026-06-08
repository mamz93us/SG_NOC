package nocclient

import (
	"context"
	"net/http"
)

// SnmpDevice is one device the NOC tells this branch to monitor.
type SnmpDevice struct {
	Name             string `json:"name"`
	Host             string `json:"host"`
	SNMPVersion      string `json:"snmp_version"`
	SNMPCommunity    string `json:"snmp_community"`
	SNMPPort         int    `json:"snmp_port"`
	DeviceType       string `json:"device_type"`
	PollingIntervalS int    `json:"polling_interval_s"`
}

// SnmpDevices pulls the branch's managed device list from the NOC.
func (c *Client) SnmpDevices(ctx context.Context) ([]SnmpDevice, error) {
	var out struct {
		OK      bool         `json:"ok"`
		Devices []SnmpDevice `json:"devices"`
	}
	if err := c.do(ctx, http.MethodGet, "/api/branch-config/snmp-devices", nil, &out); err != nil {
		return nil, err
	}
	return out.Devices, nil
}

// DiscoveredDevice is one host found by a subnet scan.
type DiscoveredDevice struct {
	Host           string `json:"host"`
	SysDescr       string `json:"sys_descr,omitempty"`
	SysName        string `json:"sys_name,omitempty"`
	MAC            string `json:"mac,omitempty"`
	SNMPResponding bool   `json:"snmp_responding"`
}

// PostDiscovered reports scan findings. The NOC upserts by (branch, host) and
// skips hosts already managed.
func (c *Client) PostDiscovered(ctx context.Context, devices []DiscoveredDevice) error {
	body := map[string]any{"devices": devices}
	return c.do(ctx, http.MethodPost, "/api/branch-config/discovered-devices", body, nil)
}

// DDNSResult reports whether the NOC acted on the WAN IP.
type DDNSResult struct {
	OK            bool `json:"ok"`
	Changed       bool `json:"changed"`
	AppliedDNS    bool `json:"applied_dns"`
	AppliedTunnel bool `json:"applied_tunnel"`
}

// ReportDDNS posts the current WAN IP. The NOC updates DNS + the VPN tunnel on
// a change (and dedups unchanged IPs cheaply).
func (c *Client) ReportDDNS(ctx context.Context, wanIP string) (*DDNSResult, error) {
	var out DDNSResult
	if err := c.do(ctx, http.MethodPost, "/api/branch-agents/ddns", map[string]string{"wan_ip": wanIP}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}
