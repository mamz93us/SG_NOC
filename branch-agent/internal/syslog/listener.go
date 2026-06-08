package syslog

import (
	"bufio"
	"context"
	"log"
	"net"
	"strings"
	"time"

	"github.com/samirgroup/sg-branch-agent/internal/store"
)

// Listener receives syslog over UDP and TCP and writes parsed records to the
// store.
type Listener struct {
	Addr  string // e.g. ":514"
	Store *store.Manager
}

// Start binds the UDP and TCP listeners and serves until ctx is cancelled.
// Returns an error only if neither transport could bind.
func (l *Listener) Start(ctx context.Context) error {
	udpErr := l.serveUDP(ctx)
	tcpErr := l.serveTCP(ctx)
	if udpErr != nil && tcpErr != nil {
		return udpErr
	}
	return nil
}

func (l *Listener) serveUDP(ctx context.Context) error {
	pc, err := net.ListenPacket("udp", l.Addr)
	if err != nil {
		log.Printf("syslog: UDP bind %s failed: %v", l.Addr, err)
		return err
	}
	log.Printf("syslog: listening UDP %s", l.Addr)
	go func() {
		<-ctx.Done()
		_ = pc.Close()
	}()
	go func() {
		buf := make([]byte, 64*1024)
		for {
			n, addr, err := pc.ReadFrom(buf)
			if err != nil {
				select {
				case <-ctx.Done():
					return
				default:
					continue
				}
			}
			line := strings.TrimRight(string(buf[:n]), "\r\n\x00")
			if line == "" {
				continue
			}
			l.Store.Write(Parse(line, host(addr), time.Now().UTC()))
		}
	}()
	return nil
}

func (l *Listener) serveTCP(ctx context.Context) error {
	ln, err := net.Listen("tcp", l.Addr)
	if err != nil {
		log.Printf("syslog: TCP bind %s failed: %v", l.Addr, err)
		return err
	}
	log.Printf("syslog: listening TCP %s", l.Addr)
	go func() {
		<-ctx.Done()
		_ = ln.Close()
	}()
	go func() {
		for {
			conn, err := ln.Accept()
			if err != nil {
				select {
				case <-ctx.Done():
					return
				default:
					continue
				}
			}
			go l.handleConn(ctx, conn)
		}
	}()
	return nil
}

// handleConn reads newline-delimited syslog from a TCP connection. (Octet-
// counted framing per RFC 6587 is uncommon for the devices here; newline
// framing covers Sophos/Cisco/UCM.)
func (l *Listener) handleConn(ctx context.Context, conn net.Conn) {
	defer conn.Close()
	src := host(conn.RemoteAddr())
	sc := bufio.NewScanner(conn)
	sc.Buffer(make([]byte, 0, 64*1024), 1024*1024)
	for sc.Scan() {
		select {
		case <-ctx.Done():
			return
		default:
		}
		line := strings.TrimRight(sc.Text(), "\r\n\x00")
		if line == "" {
			continue
		}
		l.Store.Write(Parse(line, src, time.Now().UTC()))
	}
}

func host(addr net.Addr) string {
	if h, _, err := net.SplitHostPort(addr.String()); err == nil {
		return h
	}
	return addr.String()
}
