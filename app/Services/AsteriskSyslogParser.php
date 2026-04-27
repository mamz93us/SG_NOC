<?php

namespace App\Services;

/**
 * Parser for Asterisk / Grandstream UCM syslog payloads. The same UCM
 * emits a few different shapes and we need to handle them all:
 *
 *   1. Asterisk core:
 *      [HOSTID] asterisk[PID]: SEVERITY[TASK][[C-CALLID]]: file.c:LINE in fn: …
 *
 *   2. Grandstream subsystems (GS_AVS, ChannelNode, VideoPlayerRoom):
 *      [HOSTID] PROGRAM: [ SEVERITY ] [TASK] (file.cpp:LINE): …
 *
 *   3. Grandstream CGI:
 *      [HOSTID] cgi  [PID] [file.c:LINE] …
 *
 * The parser tries each pattern in order and returns whatever fields it
 * could extract. SecurityEvent="…" payloads (common on Asterisk SECURITY
 * lines) are pulled out into a top-level `security_event` field for
 * easy filtering and alerting.
 */
class AsteriskSyslogParser
{
    public function parse(?string $body): array
    {
        if ($body === null || $body === '') return [];

        $body = ltrim($body);
        $body = preg_replace('/^<\d+>/', '', $body);

        // Strip the trailing newline rsyslog sometimes leaves.
        $body = rtrim($body, "\r\n");

        // 1) Asterisk core line.
        $core = '/^\[(?P<host_id>[A-Fa-f0-9]+)\]\s+'
              . '(?P<program>\w+)\[(?P<pid>\d+)\]:\s+'
              . '(?P<asterisk_severity>[A-Z]+)\[(?P<task_id>\d+)\]'
              . '(?:\[C-(?P<call_id>[A-Fa-f0-9]+)\])?:\s+'
              . '(?P<file>[^:\s]+):(?P<line>\d+)\s+in\s+(?P<function>\w+):\s*'
              . '(?P<text>.*)$/s';
        if (preg_match($core, $body, $m)) {
            return $this->finalize($m, $body, 'asterisk');
        }

        // 2) Grandstream subsystems — "[ SEVERITY ] [task] (file:line): body".
        $gs = '/^\[(?P<host_id>[A-Fa-f0-9]+)\]\s+'
            . '(?P<program>[A-Za-z_][\w]*):\s+'
            . '\[\s*(?P<asterisk_severity>[A-Z]+)\s*\]\s+'
            . '\[(?P<task_id>[^\]]+)\]\s+'
            . '\((?P<file>[^:)]+):(?P<line>\d+)\):\s*'
            . '(?P<text>.*)$/s';
        if (preg_match($gs, $body, $m)) {
            return $this->finalize($m, $body, 'grandstream');
        }

        // 3) Grandstream CGI shape — no severity field; rely on rsyslog severity.
        $cgi = '/^\[(?P<host_id>[A-Fa-f0-9]+)\]\s+'
             . '(?P<program>cgi)\s+\[(?P<pid>\d+)\]\s+'
             . '\[(?P<file>[^:\]]+):(?P<line>\d+)\]'
             . '(?P<text>.*)$/s';
        if (preg_match($cgi, $body, $m)) {
            return $this->finalize($m, $body, 'cgi');
        }

        // 4) Bare "[HOSTID] anything else" — keep host_id, dump the rest as text.
        if (preg_match('/^\[(?P<host_id>[A-Fa-f0-9]+)\]\s*(?P<text>.*)$/s', $body, $m)) {
            return [
                'host_id' => $m['host_id'],
                'text'    => trim($m['text']),
                'shape'   => 'unknown',
            ];
        }

        return ['text' => $body, 'shape' => 'unmatched'];
    }

    private function finalize(array $m, string $original, string $shape): array
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

        // Pull out a SecurityEvent="…" payload — common on Asterisk
        // SECURITY events. Useful for alert rules.
        if (!empty($out['text']) && preg_match('/SecurityEvent="([^"]+)"/', $out['text'], $sm)) {
            $out['security_event'] = $sm[1];
        }

        return $out;
    }
}
