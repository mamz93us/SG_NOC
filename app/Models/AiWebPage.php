<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One URL of an AiWebSource, kept between crawl rounds: what it last said
 * (content_hash), and the knowledge article that holds it.
 */
class AiWebPage extends Model
{
    public const QUEUED = 'queued';

    /** Read, and its article written. */
    public const INDEXED = 'indexed';

    /** Read again and found the same, so nothing was translated or indexed. */
    public const UNCHANGED = 'unchanged';

    /** Not readable as a page: robots.txt, not HTML, no text, or a redirect off the site. */
    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    /** 404 or 410: the page is gone, and so is its article. */
    public const GONE = 'gone';

    protected $fillable = [
        'source_id', 'url', 'url_hash', 'depth', 'status', 'http_status', 'title', 'language',
        'content_hash', 'characters', 'article_id', 'error', 'fetched_at',
    ];

    protected $casts = [
        'depth' => 'integer',
        'http_status' => 'integer',
        'characters' => 'integer',
        'article_id' => 'integer',
        'fetched_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(AiWebSource::class, 'source_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeArticle::class, 'article_id');
    }

    public static function hashUrl(string $url): string
    {
        return hash('sha256', $url);
    }
}
