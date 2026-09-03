<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document in the employee library — a manual, a guide, an IT policy, a form
 * or a training video — shown on the home portal and authored in
 * Admin → Employee Documents.
 *
 * Exactly one SOURCE is set: `file_path` (an upload on the `private` disk),
 * `video_url` (a YouTube link, embedded) or `link_url` (something that already
 * lives elsewhere). Views branch on `sourceType()` rather than testing the
 * columns directly.
 *
 * The `category` is shelving — which card and which chip a document appears
 * behind — and is deliberately independent of the source: a policy can be a
 * video, a manual can be a link.
 */
class PortalDocument extends Model
{
    protected $fillable = [
        'title', 'title_ar', 'description', 'category',
        'file_path', 'file_name', 'file_mime', 'file_size', 'link_url', 'video_url',
        'version', 'effective_date',
        'is_published', 'pinned', 'sort_order',
        'audience', 'audience_branch_id', 'audience_department_id',
        'created_by', 'created_by_name',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'pinned' => 'boolean',
        'sort_order' => 'integer',
        'file_size' => 'integer',
        'download_count' => 'integer',
        'effective_date' => 'date',
        'audience_branch_id' => 'integer',
        'audience_department_id' => 'integer',
    ];

    /**
     * The category drives which portal card a document appears behind, so the
     * keys are part of the URL (`/documents?category=policy`) — renaming one is
     * a data migration, not a label change.
     */
    public const CATEGORIES = [
        'policy' => 'IT Policy',
        'manual' => 'Manual',
        'guide' => 'How-to Guide',
        'video' => 'Training Video',
        'form' => 'Form',
    ];

    public const AUDIENCES = ['all', 'branch', 'department'];

    /** The categories the "Documentation & Manuals" card covers — everything but policy. */
    public const DOC_CATEGORIES = ['manual', 'guide', 'video', 'form'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'audience_branch_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'audience_department_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Only what this employee should see.
     *
     * Someone with no branch or department still sees the `all` documents — a
     * missing HR record must never hide the IT policy from them.
     */
    public function scopeForEmployee(Builder $query, ?Employee $employee): Builder
    {
        return $query->where(function (Builder $q) use ($employee) {
            $q->where('audience', 'all');

            if ($employee?->branch_id) {
                $q->orWhere(fn (Builder $b) => $b
                    ->where('audience', 'branch')
                    ->where('audience_branch_id', $employee->branch_id));
            }

            if ($employee?->department_id) {
                $q->orWhere(fn (Builder $b) => $b
                    ->where('audience', 'department')
                    ->where('audience_department_id', $employee->department_id));
            }
        });
    }

    /** Pinned first, then the author's order, then newest. */
    public function scopeRanked(Builder $query): Builder
    {
        return $query->orderByDesc('pinned')
            ->orderBy('sort_order')
            ->orderByDesc('id');
    }

    /** Published, audience-filtered and ordered — what the portal renders. */
    public static function liveFor(?Employee $employee): Builder
    {
        return static::query()->published()->forEmployee($employee)->ranked();
    }

    public function isFile(): bool
    {
        return (bool) $this->file_path;
    }

    public function isVideo(): bool
    {
        return ! $this->isFile() && $this->youtubeId() !== null;
    }

    /** 'file' | 'video' | 'link' — the one thing this row's card does. */
    public function sourceType(): string
    {
        return match (true) {
            $this->isFile() => 'file',
            $this->isVideo() => 'video',
            default => 'link',
        };
    }

    public function isPolicy(): bool
    {
        return $this->category === 'policy';
    }

    public function extension(): string
    {
        return strtolower(pathinfo((string) $this->file_name, PATHINFO_EXTENSION));
    }

    public function isPdf(): bool
    {
        return $this->isFile() && $this->extension() === 'pdf';
    }

    public function isImage(): bool
    {
        return $this->isFile() && in_array($this->extension(), ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
    }

    /**
     * Whether the viewer page can actually show this thing.
     *
     * Everything else (Word, Excel, a zip) has no in-browser rendering worth
     * the name, so those cards download straight away rather than routing
     * people through a page whose only content is a download button.
     */
    public function isPreviewable(): bool
    {
        return $this->isPdf() || $this->isImage() || $this->isVideo();
    }

    /**
     * The YouTube video id, from whichever form of the URL was pasted.
     *
     * Handles watch?v=, youtu.be/, /embed/, /shorts/ and /live/ — people paste
     * whatever the share button gave them, and rejecting four of the five is
     * how a field ends up filled in wrongly instead of not at all.
     *
     * Returns null for anything that is not YouTube: only ids that came out of
     * here are ever put in an iframe src, so a pasted `javascript:` or an
     * arbitrary host cannot become an embed.
     */
    public function youtubeId(): ?string
    {
        $url = trim((string) $this->video_url);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = preg_replace('/^(www|m)\./', '', $host);
        $path = trim((string) ($parts['path'] ?? ''), '/');

        $id = null;

        if ($host === 'youtu.be') {
            $id = explode('/', $path)[0] ?? null;
        } elseif (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            parse_str((string) ($parts['query'] ?? ''), $query);

            if (! empty($query['v'])) {
                $id = $query['v'];
            } elseif (preg_match('~^(embed|shorts|live|v)/([^/?\#]+)~', $path, $m)) {
                $id = $m[2];
            }
        }

        // YouTube ids are 11 characters of the URL-safe alphabet. Anything else
        // is not an id, whatever it came wrapped in.
        return ($id && preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) ? $id : null;
    }

    /** The privacy-preserving embed host — no cookie until the video is played. */
    public function youtubeEmbedUrl(): ?string
    {
        $id = $this->youtubeId();

        return $id ? "https://www.youtube-nocookie.com/embed/{$id}?rel=0&modestbranding=1" : null;
    }

    public function youtubeWatchUrl(): ?string
    {
        $id = $this->youtubeId();

        return $id ? "https://www.youtube.com/watch?v={$id}" : null;
    }

    public function youtubeThumbnailUrl(): ?string
    {
        $id = $this->youtubeId();

        return $id ? "https://i.ytimg.com/vi/{$id}/hqdefault.jpg" : null;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst((string) $this->category);
    }

    /**
     * A short, uppercase file-type tag for the card — PDF, DOCX, LINK.
     *
     * Taken from the stored filename rather than the mime type: browsers and
     * Office send several mime types for the same extension, and the extension
     * is what the person is about to open.
     */
    public function typeTag(): string
    {
        return match ($this->sourceType()) {
            'video' => 'VIDEO',
            'link' => 'LINK',
            default => strtoupper($this->extension()) ?: 'FILE',
        };
    }

    public function humanSize(): ?string
    {
        if (! $this->file_size) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $this->file_size;
        $i = 0;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, $size < 10 && $i > 0 ? 1 : 0).' '.$units[$i];
    }
}
