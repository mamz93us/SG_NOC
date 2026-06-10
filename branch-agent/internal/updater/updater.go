// Package updater performs NOC-driven self-update: download the target binary
// the NOC advertises, verify its SHA256, and atomically swap the running
// executable. The caller then exits and systemd (Restart=always) relaunches
// the new binary — so upgrades happen with zero VM interaction.
//
// This requires the running binary to live somewhere the agent user can write
// (the installer places it under the data dir, owned by the service user). If
// it isn't writable, Apply fails cleanly and the agent keeps running the old
// version.
package updater

import (
	"context"
	"crypto/sha256"
	"crypto/tls"
	"encoding/hex"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"time"
)

const maxBinaryBytes = 200 << 20 // 200 MiB guard

var httpClient = &http.Client{
	Timeout:   5 * time.Minute,
	Transport: &http.Transport{TLSClientConfig: &tls.Config{MinVersion: tls.VersionTLS12}},
}

// Apply downloads url, checks it against wantSHA256 (hex), and atomically
// replaces the currently-running executable. Returns nil on success — the
// caller should log and os.Exit(0) so systemd relaunches the new binary.
func Apply(ctx context.Context, url, wantSHA256 string) error {
	if url == "" || wantSHA256 == "" {
		return fmt.Errorf("update: missing url or sha256")
	}

	self, err := os.Executable()
	if err != nil {
		return fmt.Errorf("update: locate self: %w", err)
	}
	if resolved, err := filepath.EvalSymlinks(self); err == nil {
		self = resolved
	}
	dir := filepath.Dir(self)

	// Download to a temp file alongside the target (same filesystem → atomic
	// rename) so a partial download never replaces a good binary.
	tmp, err := os.CreateTemp(dir, ".sg-branch-agent.new-*")
	if err != nil {
		return fmt.Errorf("update: temp file (is %s writable by the agent user?): %w", dir, err)
	}
	tmpPath := tmp.Name()
	defer os.Remove(tmpPath) // no-op after a successful rename

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		tmp.Close()
		return err
	}
	resp, err := httpClient.Do(req)
	if err != nil {
		tmp.Close()
		return fmt.Errorf("update: download: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		tmp.Close()
		return fmt.Errorf("update: download HTTP %d", resp.StatusCode)
	}

	h := sha256.New()
	if _, err := io.Copy(io.MultiWriter(tmp, h), io.LimitReader(resp.Body, maxBinaryBytes)); err != nil {
		tmp.Close()
		return fmt.Errorf("update: write: %w", err)
	}
	if err := tmp.Close(); err != nil {
		return fmt.Errorf("update: close temp: %w", err)
	}

	got := hex.EncodeToString(h.Sum(nil))
	if got != wantSHA256 {
		return fmt.Errorf("update: checksum mismatch (want %s, got %s)", wantSHA256, got)
	}

	if err := os.Chmod(tmpPath, 0o755); err != nil {
		return fmt.Errorf("update: chmod: %w", err)
	}
	if err := os.Rename(tmpPath, self); err != nil {
		return fmt.Errorf("update: replace %s: %w", self, err)
	}
	return nil
}
