<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyslogAlertRule extends Model
{
    protected $fillable = [
        'name', 'enabled',
        'severity_max', 'source_type', 'host_contains', 'message_regex',
        'event_severity', 'event_module',
        'cooldown_minutes',
        'last_matched_at', 'match_count',
    ];

    protected $casts = [
        'enabled'           => 'boolean',
        'severity_max'      => 'integer',
        'cooldown_minutes'  => 'integer',
        'match_count'       => 'integer',
        'last_matched_at'   => 'datetime',
    ];

    /**
     * Test a single SyslogMessage against this rule's filters.
     * Returns true if all configured filters match.
     */
    public function matches(SyslogMessage $msg): bool
    {
        if (!$this->enabled) return false;

        if ($msg->severity > $this->severity_max) return false;

        if ($this->source_type && $msg->source_type !== $this->source_type) {
            return false;
        }

        if ($this->host_contains
            && stripos((string) $msg->host, $this->host_contains) === false) {
            return false;
        }

        if ($this->message_regex) {
            // Wrap in delimiters if user didn't supply them. Suppress
            // PCRE warnings — bad regex returns false here so rule misses
            // rather than crashing the matcher.
            $pattern = $this->message_regex;
            if (!preg_match('/^\/.*\/[a-z]*$/i', $pattern)
                && !preg_match('/^#.*#[a-z]*$/i', $pattern)) {
                $pattern = '/' . str_replace('/', '\/', $pattern) . '/';
            }
            if (@preg_match($pattern, (string) $msg->message) !== 1) {
                return false;
            }
        }

        return true;
    }
}
