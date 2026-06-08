// Package ddns detects the branch's public WAN IP for the DDNS reporter.
package ddns

import (
	"context"
	"crypto/tls"
	"io"
	"net"
	"net/http"
	"strings"
	"time"
)

// echoServices return the caller's public IP as plain text. We try them in
// order and accept the first that returns a valid IP.
var echoServices = []string{
	"https://api.ipify.org",
	"https://ifconfig.me/ip",
	"https://icanhazip.com",
	"https://ipinfo.io/ip",
}

var httpClient = &http.Client{
	Timeout:   10 * time.Second,
	Transport: &http.Transport{TLSClientConfig: &tls.Config{MinVersion: tls.VersionTLS12}},
}

// DetectWANIP returns the public IPv4/IPv6 address of this host, trying each
// echo service until one answers with a parseable IP.
func DetectWANIP(ctx context.Context) (string, error) {
	var lastErr error
	for _, url := range echoServices {
		ip, err := fetchIP(ctx, url)
		if err == nil {
			return ip, nil
		}
		lastErr = err
	}
	if lastErr == nil {
		lastErr = errNoService
	}
	return "", lastErr
}

func fetchIP(ctx context.Context, url string) (string, error) {
	reqCtx, cancel := context.WithTimeout(ctx, 8*time.Second)
	defer cancel()
	req, err := http.NewRequestWithContext(reqCtx, http.MethodGet, url, nil)
	if err != nil {
		return "", err
	}
	resp, err := httpClient.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	body, err := io.ReadAll(io.LimitReader(resp.Body, 128))
	if err != nil {
		return "", err
	}
	ip := strings.TrimSpace(string(body))
	if net.ParseIP(ip) == nil {
		return "", errBadIP
	}
	return ip, nil
}

type ddnsError string

func (e ddnsError) Error() string { return string(e) }

const (
	errNoService = ddnsError("no WAN-IP echo service reachable")
	errBadIP     = ddnsError("echo service returned a non-IP response")
)
