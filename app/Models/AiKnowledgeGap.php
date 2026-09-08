<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiKnowledgeGap extends Model
{
    protected $fillable = [
        'query_normalized', 'query_sample', 'hit_count', 'locale', 'last_seen_at', 'resolved_at',
    ];

    protected $casts = [
        'hit_count' => 'integer',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /** Record (or bump) an unanswered search — the backlog of articles IT should write next. */
    public static function record(string $rawQuery, ?string $locale): void
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', $rawQuery) ?? ''));

        if ($normalized === '') {
            return;
        }

        // Truncate to the column width — this is a backlog signal, not a
        // verbatim transcript, so a very long question is fine to clip.
        $normalized = mb_substr($normalized, 0, 500);

        $gap = static::firstOrNew(['query_normalized' => $normalized]);
        $gap->query_sample = mb_substr($rawQuery, 0, 2000);
        $gap->locale = $locale;
        $gap->hit_count = ($gap->hit_count ?? 0) + 1;
        $gap->last_seen_at = now();
        $gap->save();
    }
}
