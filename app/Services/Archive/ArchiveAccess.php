<?php

namespace App\Services\Archive;

use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveDocument;
use App\Models\Archive\ArchiveFile;
use App\Models\Archive\ArchiveMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place that decides what a person may reach in the archive.
 *
 * EVERY route, every stream, every download and every AI tool goes through this
 * class. That is deliberate and worth defending: the alternative — each
 * controller writing its own version of "is this mine?" — is how a viewer and
 * its download link drift apart, and the thing on the other side of the drift
 * here is every supplier invoice, contract and HR file the company has scanned
 * since 2013.
 *
 * Two gates, and both must pass:
 *
 *   1. `use-archive-portal` — reaching the portal at all.
 *   2. a membership row on the archive, carrying the ability being asked for.
 *
 * There is no "all archives" grant short of a superuser. Holding the portal
 * permission is emphatically NOT access to any document.
 *
 * Nothing here ever returns a 403. A document in an archive someone is not a
 * member of is reported as missing, because "this exists but is not yours" is
 * itself information — the existence of an invoice number or an HR file is
 * worth something to whoever is probing for it.
 */
class ArchiveAccess
{
    public const PERMISSION = 'use-archive-portal';

    public const PERMISSION_AI = 'use-archive-ai';

    public const PERMISSION_MANAGE = 'manage-archive-portal';

    public function __construct(private ?User $user) {}

    public static function for(?User $user): self
    {
        return new self($user);
    }

    public function user(): ?User
    {
        return $this->user;
    }

    /** Whether this person may open the portal at all. */
    public function mayEnter(): bool
    {
        return (bool) $this->user?->hasPermission(self::PERMISSION);
    }

    public function mayUseAi(): bool
    {
        return $this->mayEnter() && (bool) $this->user?->hasPermission(self::PERMISSION_AI);
    }

    public function mayManage(): bool
    {
        return (bool) $this->user?->hasPermission(self::PERMISSION_MANAGE);
    }

    /**
     * A superuser sees every archive without a membership row.
     *
     * Kept narrow on purpose — this is `is_super`, the flag that is not
     * editable in the UI, not the `admin` role. An administrator of the NOC is
     * not automatically a reader of the finance archive.
     */
    public function seesEverything(): bool
    {
        return (bool) $this->user?->isSuperAdmin();
    }

    // ─── Archives ────────────────────────────────────────────────

    /**
     * Archives this person may do `$ability` in.
     *
     * The ability is checked against ArchiveMember::ABILITIES before it reaches
     * the query, so a caller can never name an arbitrary column.
     */
    public function archives(string $ability = 'can_view'): Builder
    {
        $query = Archive::query()->orderBy('sort_order')->orderBy('name');

        if (! $this->mayEnter()) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->seesEverything()) {
            return $query;
        }

        $ability = $this->ability($ability);

        return $query->whereHas('members', function (Builder $member) use ($ability) {
            $member->where('user_id', $this->user?->getKey())->where($ability, true);
        });
    }

    /** @return array<int,int> */
    public function archiveIds(string $ability = 'can_view'): array
    {
        return $this->archives($ability)->reorder()->pluck('id')->all();
    }

    public function canOnArchive(?Archive $archive, string $ability = 'can_view'): bool
    {
        if (! $archive || ! $this->mayEnter()) {
            return false;
        }

        if ($this->seesEverything()) {
            return true;
        }

        $ability = $this->ability($ability);

        return ArchiveMember::query()
            ->where('archive_id', $archive->getKey())
            ->where('user_id', $this->user?->getKey())
            ->where($ability, true)
            ->exists();
    }

    // ─── Documents and files ─────────────────────────────────────

    /**
     * Documents in the archives this person may reach.
     *
     * Deleted-in-ArcMate documents are excluded here rather than in each
     * caller: they are kept so a deletion stays recoverable, not so they keep
     * turning up in searches.
     */
    public function documents(string $ability = 'can_view'): Builder
    {
        return ArchiveDocument::query()
            ->whereIn('archive_id', $this->archiveIds($ability))
            ->where('status', ArchiveDocument::STATUS_ACTIVE);
    }

    /**
     * One document, or null when it is not this person's to see.
     *
     * Re-run per request — never trusted from the last page — because these
     * URLs get shared, bookmarked and forwarded, and a membership can be
     * withdrawn between two clicks.
     */
    public function findDocument(int|string|null $id, string $ability = 'can_view'): ?ArchiveDocument
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $this->documents($ability)->whereKey($id)->first();
    }

    /**
     * One file, with its document, or null.
     *
     * Checked through the document rather than the file's own archive_id: the
     * document is what membership is about, and the two agreeing is not
     * something this class should assume.
     */
    public function findFile(int|string|null $id, string $ability = 'can_view'): ?ArchiveFile
    {
        if ($id === null || $id === '') {
            return null;
        }

        /** @var ArchiveFile|null $file */
        $file = ArchiveFile::query()->with('document')->whereKey($id)->first();

        if (! $file || ! $file->document) {
            return null;
        }

        return $this->findDocument($file->archive_document_id, $ability) ? $file : null;
    }

    /**
     * A file of a document already checked in this request.
     *
     * Saves the second lookup where the document is in hand, without letting a
     * file id from the URL wander into another document.
     */
    public function fileOfDocument(ArchiveDocument $document, int|string|null $fileId): ?ArchiveFile
    {
        if ($fileId === null || $fileId === '') {
            return null;
        }

        return $document->files()->whereKey($fileId)->first();
    }

    /** Only the abilities a membership actually carries may reach a query. */
    private function ability(string $ability): string
    {
        return array_key_exists($ability, ArchiveMember::ABILITIES) ? $ability : 'can_view';
    }
}
