<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document in the employee library — a manual, a guide, an IT policy or a
 * form — shown on the home portal and authored in Admin → Employee Documents.
 *
 * Either `file_path` (an upload on the `private` disk) or `link_url` (something
 * that already lives elsewhere) is set, never neither. `isFile()` is what the
 * views branch on rather than testing the columns directly.
 */
class PortalDocument extends Model
{
    protected $fillable = [
        'title', 'title_ar', 'description', 'category',
        'file_path', 'file_name', 'file_mime', 'file_size', 'link_url',
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
        'form' => 'Form',
    ];

    public const AUDIENCES = ['all', 'branch', 'department'];

    /** The categories the "Documentation & Manuals" card covers — everything but policy. */
    public const DOC_CATEGORIES = ['manual', 'guide', 'form'];

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

    public function isPolicy(): bool
    {
        return $this->category === 'policy';
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
        if (! $this->isFile()) {
            return 'LINK';
        }

        $ext = strtoupper(pathinfo((string) $this->file_name, PATHINFO_EXTENSION));

        return $ext !== '' ? $ext : 'FILE';
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
