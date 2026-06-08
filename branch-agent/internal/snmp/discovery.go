package snmp

import (
	"net"
	"sync"
	"time"

	"github.com/gosnmp/gosnmp"
)

// Discovered is a host that answered an SNMP probe during a scan.
type Discovered struct {
	Host     string
	SysDescr string
	SysName  string
}

const (
	scanConcurrency = 48
	scanTimeout     = 800 * time.Millisecond
)

// Scan probes every host in the given CIDR subnets with a quick SNMP Get of
// sysDescr/sysName. maxHosts caps the total probed so a fat subnet can't turn
// into a multi-thousand-host sweep. Returns hosts that responded.
func Scan(subnets []string, community, version string, maxHosts int) []Discovered {
	hosts := enumerate(subnets, maxHosts)
	if len(hosts) == 0 {
		return nil
	}

	sem := make(chan struct{}, scanConcurrency)
	var wg sync.WaitGroup
	var mu sync.Mutex
	var found []Discovered

	for _, h := range hosts {
		wg.Add(1)
		sem <- struct{}{}
		go func(host string) {
			defer wg.Done()
			defer func() { <-sem }()
			if d, ok := probe(host, communityOr(community), version); ok {
				mu.Lock()
				found = append(found, d)
				mu.Unlock()
			}
		}(h)
	}
	wg.Wait()
	return found
}

func probe(host, community, version string) (Discovered, bool) {
	c := &gosnmp.GoSNMP{
		Target:    host,
		Port:      161,
		Community: community,
		Version:   snmpVersion(version),
		Timeout:   scanTimeout,
		Retries:   0,
		MaxOids:   gosnmp.MaxOids,
	}
	if err := c.Connect(); err != nil {
		return Discovered{}, false
	}
	defer c.Conn.Close()

	res, err := c.Get([]string{oidSysDescr, oidSysName})
	if err != nil || res == nil || len(res.Variables) == 0 {
		return Discovered{}, false
	}
	d := Discovered{Host: host}
	for _, v := range res.Variables {
		switch v.Name {
		case oidSysDescr, "." + oidSysDescr:
			d.SysDescr = toStr(v)
		case oidSysName, "." + oidSysName:
			d.SysName = toStr(v)
		}
	}
	// Treat any successful Get as a responding device.
	return d, true
}

// enumerate expands CIDRs into host IPs (IPv4), skipping network/broadcast,
// stopping at maxHosts total.
func enumerate(subnets []string, maxHosts int) []string {
	var out []string
	for _, cidr := range subnets {
		_, ipnet, err := net.ParseCIDR(cidr)
		if err != nil || ipnet.IP.To4() == nil {
			continue
		}
		for ip := cloneIP(ipnet.IP.Mask(ipnet.Mask)); ipnet.Contains(ip); inc(ip) {
			if len(out) >= maxHosts {
				return out
			}
			// skip network + broadcast
			if isNetworkOrBroadcast(ip, ipnet) {
				continue
			}
			out = append(out, ip.String())
		}
	}
	return out
}

func isNetworkOrBroadcast(ip net.IP, ipnet *net.IPNet) bool {
	network := ip.Mask(ipnet.Mask)
	if ip.Equal(network) {
		return true
	}
	// broadcast = network | ^mask
	bcast := cloneIP(network.To4())
	for i := range bcast {
		bcast[i] |= ^ipnet.Mask[i]
	}
	return ip.Equal(bcast)
}

func cloneIP(ip net.IP) net.IP {
	c := make(net.IP, len(ip))
	copy(c, ip)
	return c
}

func inc(ip net.IP) {
	for i := len(ip) - 1; i >= 0; i-- {
		ip[i]++
		if ip[i] != 0 {
			break
		}
	}
}
