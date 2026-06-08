//go:build !linux

package store

// On non-Linux dev hosts we can't easily statfs; report "plenty free" so the
// low-disk guard never trips and 0% used. Production is Linux (disk_linux.go).
func diskFreeBytes(path string) (uint64, bool) { return 1 << 60, true }

func diskUsedPct(path string) (int, bool) { return 0, true }
