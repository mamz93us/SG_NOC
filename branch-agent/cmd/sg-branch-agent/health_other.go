//go:build !linux

package main

// enrichHealth is a no-op off Linux (local dev on macOS/Windows). The
// production target is Ubuntu 24.04, where health_linux.go supplies disk/RAM.
func enrichHealth(h map[string]any) {}
