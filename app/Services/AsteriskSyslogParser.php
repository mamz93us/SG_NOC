<?php

namespace App\Services;

/**
 * Parser for Asterisk / Grandstream UCM syslog payloads.
 *
 * Grandstream UCMs emit several different shapes from different
 * subsystems on the same box:
 *
 *   asterisk    [HOSTID] asterisk[PID]: SEV[TASK][[C-CALL]]: file.c:LINE in fn: …
 *   grandstream [HOSTID] PROGRAM: [ SEV ] [TASK] (file.cpp:LINE): …
 *   cgi         [HOSTID] cgi: [PID] [file.c:LINE]…
 *   ucm         [HOSTID] UCM6308A: TR069 INFO [1.0.5.18] …
 *   misc        [HOSTID] PROGRAM: [file.c:LINE][module] …      (ZeroConfig, ucm_warning, …)
 *   unknown     [HOSTID] PROGRAM: anything else
 *   unmatched   no recognizable [HOSTID] tag at all
 *
 * The parser tries patterns from most specific to most generic and
 * returns whatever fields matched. Numeric coercion + SecurityEvent
 * extraction happen in finalize().
 */
class AsteriskSyslogParser
{
    public function parse(?string $body): array
    {
        if ($body === null || $body === '') return [];

        $body = ltrim($body);
        $body = preg_replace('/^<\d+>/', '', $body);
        $body = rtrim($body, "\r\n");

        // Drop everything before the first [HEX:HEX:…] tag — the device
        // (and sometimes rsyslog) prepend their own timestamps/hostnames.
        if (preg_match('/\[[A-Fa-f0-9:]+\].*$/s', $body, $m)) {
            $body = $m[0];
        } else {
            return ['shape' => 'unmatched', 'text' => $body];
        }

        // 1) Asterisk core line.
        $core = '/^\[(?P<host_id>[A-Fa-f0-9:]+)\]\s+'
              . '(?P<program>\w+)\[(?P<pid>\d+)\]:\s+'
              . '(?P<asterisk_severity>[A-Z]+)\[(?P<task_id>\d+)\]'
              . '(?:\[C-(?P<call_id>[A-Fa-f0-9]+)\])?:\s+'
              . '(?P<file>[^:\s]+):(?P<line>\d+)\s+in\s+(?P<function>\w+):\s*'
              . '(?P<text>.*)$/s';
        if (preg_match($core, $body, $m)) {
            return $this->finalize($m, 'asterisk');
        }

        // 2) Grandstream subsystem with bracketed severity + (file:line).
        $gs = '/^\[(?P<host_id>[A-Fa-f0-9:]+)\]\s+'
            . '(?P<program>[A-Za-z_][\w]*):\s+'
            . '\[\s*(?P<asterisk_severity>[A-Z]+)\s*\]\s+'
            . '\[(?P<task_id>[^\]]+)\]\s+'
            . '\((?P<file>[^:)]+):(?P<line>\d+)\):\s*'
            . '(?P<text>.*)$/s';
        if (preg_match($gs, $body, $m)) {
            return $this->finalize($m, 'grandstream');
        }

        // 3) UCM6308A TR069-style: PROGRAM: SUBSYSTEM SEV [version] body.
        $ucm = '/^\[(?P<host_id>[A-Fa-f0-9:]+)\]\s+'
             . '(?P<program>UCM\w+):\s+'
             . '(?P<subsystem>\w+)\s+'
             . '(?P<asterisk_severity>[A-Z]+)\s+'
             . '\[(?P<version>[^\]]+)\]\s*'
             . '(?P<text>.*)$/s';
        if (preg_match($ucm, $body, $m)) {
            return $this->finalize($m, 'ucm');
        }

        // 4) Generic "program: [PID]? [file.c:line][module]? body".
        // Covers cgi, ZeroConfig, ucm_warning, and friends.
        $generic = '/^\[(?P<host_id>[A-Fa-f0-9:]+)\]\s+'
                 . '(?P<program>[A-Za-z_]\w*):\s+'
                 . '(?:\[(?P<pid>\d+)\]\s+)?'
                 . '\[(?P<file>[^:\]]+):(?P<line>\d+)\]'
                 . '(?:\[(?P<module>[^\]]+)\])?\s*'
                 . '(?P<text>.*)$/s';
        if (preg_match($generic, $body, $m)) {
            $shape = match (strtolower($m['program'])) {
                'cgi'    => 'cgi',
                default  => 'misc',
            };
            return $this->finalize($m, $shape);
        }

        // 5) Bare "[hostid] program: anything" — keep program but no file.
        $bare = '/^\[(?P<host_id>[A-Fa-f0-9:]+)\]\s+'
              . '(?P<program>[A-Za-z_]\w*):?\s*'
              . '(?P<text>.*)$/s';
        if (preg_match($bare, $body, $m)) {
            return $this->finalize($m, 'unknown');
        }

        // Last resort.
        if (preg_match('/^\[(?P<host_id>[A-Fa-f0-9:]+)\]\s*(?P<text>.*)$/s', $body, $m)) {
            return [
                'shape'   => 'unknown',
                'host_id' => $m['host_id'],
                'text'    => trim($m['text']),
            ];
        }

        return ['shape' => 'unmatched', 'text' => $body];
    }

    private function finalize(array $m, string $shape): array
    {
        $out = ['shape' => $shape];

        foreach ($m as $k => $v) {
            if (is_int($k)) continue;
            if ($v === '' || $v === null) continue;
            $out[$k] = $v;
        }

        if (isset($out['line']))    $out['line'] = (int) $out['line'];
        if (isset($out['pid']))     $out['pid']  = (int) $out['pid'];
        if (isset($out['task_id']) && ctype_digit($out['task_id'])) {
            $out['task_id'] = (int) $out['task_id'];
        }
        if (isset($out['text'])) {
            $out['text'] = trim($out['text']);
        }

        // Pull out a SecurityEvent="…" tag if present (Asterisk SECURITY events).
        if (!empty($out['text']) && preg_match('/SecurityEvent="([^"]+)"/', $out['text'], $sm)) {
            $out['security_event'] = $sm[1];
        }

        return $out;
    }
}
