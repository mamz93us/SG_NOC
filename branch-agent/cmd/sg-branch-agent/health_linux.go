//go:build linux

package main

import (
	"bufio"
	"os"
	"strconv"
	"strings"
	"syscall"
)

// enrichHealth adds disk and RAM usage on Linux (the production target).
func enrichHealth(h map[string]any) {
	if pct, ok := diskUsedPct("/"); ok {
		h["disk_pct"] = pct
	}
	if pct, ok := ramUsedPct(); ok {
		h["ram_pct"] = pct
	}
}

func diskUsedPct(path string) (int, bool) {
	var st syscall.Statfs_t
	if err := syscall.Statfs(path, &st); err != nil {
		return 0, false
	}
	total := st.Blocks * uint64(st.Bsize)
	free := st.Bavail * uint64(st.Bsize)
	if total == 0 {
		return 0, false
	}
	used := total - free
	return int(float64(used) / float64(total) * 100.0), true
}

func ramUsedPct() (int, bool) {
	f, err := os.Open("/proc/meminfo")
	if err != nil {
		return 0, false
	}
	defer f.Close()

	var total, avail float64
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		fields := strings.Fields(sc.Text())
		if len(fields) < 2 {
			continue
		}
		val, _ := strconv.ParseFloat(fields[1], 64)
		switch fields[0] {
		case "MemTotal:":
			total = val
		case "MemAvailable:":
			avail = val
		}
	}
	if total == 0 {
		return 0, false
	}
	return int((total - avail) / total * 100.0), true
}
