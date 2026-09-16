<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveAiProposal;
use App\Models\Archive\ArchiveDocumentValue;
use App\Models\Archive\ArchiveField;
use App\Services\Archive\ArchiveAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * The review queue: what AI proposed, and a person deciding.
 *
 * This page is the reason the whole fill feature is allowed to exist. AI reads
 * twenty years of scanned invoices and contracts and proposes values for fields
 * nobody ever filled in; nothing it proposes reaches a document until somebody
 * approves it here, and an approved value is written with `ai_approved` and the
 * reviewer's id on it.
 *
 * Two decisions worth knowing about:
 *
 * **Gated by membership, not by `manage-archive-portal`.** Approving a proposal
 * edits a document's index, so the gate is `can_edit` on that archive — the same
 * ability the document page uses for its own edit affordances. Whoever configures
 * the ArcMate mirror is not thereby qualified to say what an invoice number is.
 *
 * **Deliberately NOT gated on `Archive::allowsValueEdits()`.** That method is
 * false for a `mirror` archive, which is every archive that has gaps — SPS
 * Invoices included, until cutover. Its concern is a person retyping a value
 * ArcMate also holds, which the next sync would argue with. An approved proposal
 * is different in kind and already handled: ArchiveSyncService treats
 * `ai_approved` exactly as it treats `person`, never overwriting it and flagging
 * the document for review if ArcMate later disagrees. Adding that check here
 * would look like tightening security and would in fact switch the feature off
 * everywhere it is useful.
 */
class ReviewController extends Controller
{
    /** Proposals on one page. Reviewing is reading scans; a longer page is not kinder. */
    private const PER_PAGE = 25;

    /** Never bulk-approve below this, whatever a form asks for. */
    private const MIN_BULK_CONFIDENCE = 70;

    public function index(Request $request): View
    {
        $access = ArchiveAccess::for($request->user());

        // The archives this person may EDIT, which is a smaller set than the ones
        // they may read — the queue must not show a proposal they cannot act on.
        $archives = $access->archives('can_edit')->get();

        $archive = $request->query('archive')
            ? $archives->firstWhere('slug', $request->query('archive'))
            : null;

        // One subquery, narrowed rather than intersected with a second: the
        // documents this person may edit, optionally in one archive.
        $documents = $access->documents('can_edit');

        if ($archive) {
            $documents->where('archive_id', $archive->getKey());
        }

        // Least confident first by default: those are the ones where a reviewer's
        // time actually changes the outcome. The confident ones are what
        // bulk-approve is for.
        $order = $request->query('order') === 'confident' ? 'desc' : 'asc';

        return view('archive.review', [
            // The paginator carries its own total, so nothing here counts twice.
            'proposals' => ArchiveAiProposal::query()
                ->pending()
                ->whereIn('archive_document_id', $documents->select('id'))
                ->with(['document.archive', 'field', 'batch'])
                ->orderBy('confidence', $order)
                ->orderBy('id')
                ->paginate(self::PER_PAGE)
                ->withQueryString(),
            'archives' => $archives,
            'archive' => $archive,
            'order' => $order,
            'minBulkConfidence' => self::MIN_BULK_CONFIDENCE,
            'counts' => $this->countsByArchive($access),
        ]);
    }

    /**
     * Approve, approve-as-edited, or reject one proposal.
     *
     * "Edited" is its own status rather than a plain approval, because the
     * difference matters when anybody later asks how good the AI actually was:
     * a value a reviewer had to correct is not a value the AI got right.
     */
    public function decide(Request $request, ArchiveAiProposal $proposal): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,edit,reject'],
            'value' => ['nullable', 'string', 'max:250'],
        ]);

        $access = ArchiveAccess::for($request->user());
        $document = $access->findDocument($proposal->archive_document_id, 'can_edit');

        // Re-checked here, not trusted from the listing: these URLs are shared
        // and a membership can be withdrawn between two clicks.
        abort_unless($document && $proposal->field, 404);

        if ($proposal->isDecided()) {
            return back()->with('error', 'Somebody has already decided that one.');
        }

        if ($data['decision'] === 'reject') {
            $proposal->forceFill([
                'status' => ArchiveAiProposal::STATUS_REJECTED,
                'reviewed_by' => $request->user()?->getKey(),
                'reviewed_at' => now(),
            ])->save();

            return back()->with('status', 'Rejected. Nothing was written to the document.');
        }

        $edited = $data['decision'] === 'edit';
        $value = trim((string) ($edited ? $data['value'] : $proposal->value));

        if ($value === '') {
            return back()->with('error', 'A value is needed to approve that.');
        }

        $this->writeValue($proposal->field, $document->getKey(), $value, $request->user()?->getKey());

        $proposal->forceFill([
            'status' => $edited ? ArchiveAiProposal::STATUS_EDITED : ArchiveAiProposal::STATUS_APPROVED,
            'approved_value' => $edited ? $value : null,
            'reviewed_by' => $request->user()?->getKey(),
            'reviewed_at' => now(),
        ])->save();

        return back()->with('status', $proposal->field->label().' saved as "'.$value.'".');
    }

    /**
     * Approve everything above a confidence level, for one archive.
     *
     * The one bulk action, and it is floored at MIN_BULK_CONFIDENCE regardless of
     * what arrives in the request: "approve everything" over half a million
     * documents is not review, and a threshold typed as 0 would be exactly that
     * with a number next to it.
     *
     * Each row still goes through the same single-proposal path, so a bulk
     * approval cannot write a value a single approval would have refused.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'archive' => ['required', 'integer'],
            'confidence' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $access = ArchiveAccess::for($request->user());
        $archive = Archive::find($data['archive']);

        abort_unless($archive && $access->canOnArchive($archive, 'can_edit'), 404);

        $threshold = max(self::MIN_BULK_CONFIDENCE, (int) $data['confidence']);
        $userId = $request->user()?->getKey();

        $proposals = ArchiveAiProposal::query()
            ->pending()
            ->where('confidence', '>=', $threshold)
            ->whereIn(
                'archive_document_id',
                $access->documents('can_edit')->where('archive_id', $archive->getKey())->select('id'),
            )
            ->with('field')
            ->limit(500)
            ->get();

        $approved = 0;

        foreach ($proposals as $proposal) {
            $value = trim((string) $proposal->value);

            if (! $proposal->field || $value === '') {
                continue;
            }

            $this->writeValue($proposal->field, (int) $proposal->archive_document_id, $value, $userId);

            $proposal->forceFill([
                'status' => ArchiveAiProposal::STATUS_APPROVED,
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
            ])->save();

            $approved++;
        }

        $this->logBulk($archive, $threshold, $approved);

        return back()->with(
            'status',
            $approved === 0
                ? 'Nothing was above '.$threshold.'% confidence.'
                : $approved.' value(s) saved at '.$threshold.'% confidence or above.',
        );
    }

    // ─── Internals ───────────────────────────────────────────────

    /**
     * Write an approved value onto the document.
     *
     * The typed columns are filled alongside `value_text` for the same reason the
     * sync fills them: a range search on a date or an amount has to be an indexed
     * comparison, and ArcMate stored everything — amounts included — as varchar.
     *
     * Audited automatically, because this is a person changing a document. That
     * is the whole point of the review queue existing.
     */
    private function writeValue(ArchiveField $field, int $documentId, string $value, ?int $userId): void
    {
        $row = ArchiveDocumentValue::firstOrNew([
            'archive_document_id' => $documentId,
            'archive_field_id' => $field->getKey(),
        ]);

        $row->fill([
            'value_text' => $value,
            'value_date' => $field->isDate() ? $this->asDate($value) : null,
            'value_number' => $field->isNumber() && is_numeric($value) ? $value : null,
            'source' => ArchiveDocumentValue::SOURCE_AI_APPROVED,
            'set_by_user_id' => $userId,
        ])->save();
    }

    private function asDate(string $value): ?string
    {
        try {
            return CarbonImmutable::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * How many proposals wait in each archive this person may edit.
     *
     * @return array<int,int>
     */
    private function countsByArchive(ArchiveAccess $access): array
    {
        return ArchiveAiProposal::query()
            ->pending()
            ->join('archive_documents', 'archive_documents.id', '=', 'archive_ai_proposals.archive_document_id')
            ->whereIn('archive_documents.archive_id', $access->archiveIds('can_edit'))
            ->groupBy('archive_documents.archive_id')
            ->selectRaw('archive_documents.archive_id, COUNT(*) AS waiting')
            ->pluck('waiting', 'archive_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * A bulk approval is one decision covering hundreds of documents, so it is
     * logged as one event by hand. The individual value writes are audited on
     * their own; this records the judgement that produced them.
     */
    private function logBulk(Archive $archive, int $threshold, int $approved): void
    {
        try {
            ActivityLog::log('archive_proposals_bulk_approved', $archive, [
                'archive' => $archive->slug,
                'confidence_at_least' => $threshold,
                'approved' => $approved,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[archive] bulk approval not logged: '.$e->getMessage());
        }
    }
}
