//go:build linux

package store

import "syscall"

// diskFreeBytes returns the available bytes on the filesystem holding path.
func diskFreeBytes(path string) (uint64, bool) {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return 0, false
	}
	return st.Bavail * uint64(st.Bsize), true
}

// diskUsedPct returns used percentage of the filesystem holding path.
func diskUsedPct(path string) (int, bool) {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return 0, false
	}
	total := st.Blocks * uint64(st.Bsize)
	if total == 0 {
		return 0, false
	}
	free := st.Bavail * uint64(st.Bsize)
	used := total - free
	return int(float64(used) / float64(total) * 100.0), true
}
