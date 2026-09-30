<?php

use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveDocument;
use App\Models\Archive\ArchiveField;
use App\Models\Archive\ArchiveFile;
use App\Models\Archive\ArchiveMember;
use App\Models\User;
use App\Services\Archive\Ai\ReadingState;
use App\Services\Archive\ArchiveAccess;
use App\Services\Archive\DocumentSearch;
use Illuminate\Http\Request;
use Tests\Unit\Archive\ArchiveTestSchema;

/**
 * The "read by AI" flag on the document list and the document page, and the
 * list's filter for it. The flag is built from the files' own reading state,
 * so the two pages can never describe one document differently.
 */
uses(Tests\TestCase::class);

function readingFile(string $status, int $read = 0, int $pages = 0): array
{
    return ['text_status' => $status, 'pages_read' => $read, 'page_count' => $pages];
}

// ─── The flag ───────────────────────────────────────────────────

test('every readable file read is "Read by AI"', function () {
    $state = ReadingState::of([readingFile(ArchiveFile::TEXT_DONE, 3, 3), readingFile(ArchiveFile::TEXT_DONE, 1, 0)]);

    expect($state->state)->toBe(ReadingState::DONE);
    expect($state->label())->toBe('Read by AI');
    expect($state->pagesRead)->toBe(4);
});

test('part of a document read says how much, when the page count is known', function () {
    expect(ReadingState::of([readingFile(ArchiveFile::TEXT_PENDING, 3, 10)])->label())->toBe('AI read 3 of 10 pages');

    // ArcMate never recorded page counts, so an unopened file's is unknown.
    expect(ReadingState::of([readingFile(ArchiveFile::TEXT_DONE, 2, 2), readingFile(ArchiveFile::TEXT_NONE)])->label())
        ->toBe('AI read 2 pages so far');
});

test('a file nothing can read does not keep a document "partly read" for ever', function () {
    // An attached Outlook e-mail beside a fully read scan.
    $state = ReadingState::of([readingFile(ArchiveFile::TEXT_DONE, 2, 2), readingFile(ArchiveFile::TEXT_UNREADABLE)]);

    expect($state->state)->toBe(ReadingState::DONE);
});

test('a failed read and an untouched document are told apart', function () {
    expect(ReadingState::of([readingFile(ArchiveFile::TEXT_FAILED)])->state)->toBe(ReadingState::FAILED);
    expect(ReadingState::of([readingFile(ArchiveFile::TEXT_NONE)])->state)->toBe(ReadingState::NONE);
    expect(ReadingState::of([])->state)->toBe(ReadingState::NONE);
    expect(ReadingState::of([readingFile(ArchiveFile::TEXT_NONE)])->hasText())->toBeFalse();
});

// ─── The filter ─────────────────────────────────────────────────

test('"Only documents AI has read" lists this archive\'s documents with a page read, and no others', function () {
    ArchiveTestSchema::create();

    $archive = Archive::create(['slug' => 'invoices', 'name' => 'Invoices']);
    ArchiveField::create(['archive_id' => $archive->id, 'key' => 'invoiceno', 'label' => 'InvoiceNo', 'type' => ArchiveField::TYPE_TEXT]);
    $archive = $archive->fresh('fields');

    $make = function (Archive $in, string $status, int $read) {
        $document = ArchiveDocument::create(['archive_id' => $in->id, 'status' => ArchiveDocument::STATUS_ACTIVE, 'captured_at' => '2026-09-15 10:00:00']);
        ArchiveFile::create([
            'archive_id' => $in->id, 'archive_document_id' => $document->id, 'position' => 1,
            'original_name' => 'scan.pdf', 'disk' => 'local', 'path' => "x/{$document->id}.pdf",
        ])->forceFill(['text_status' => $status, 'pages_read' => $read])->save();

        return $document;
    };

    $read = $make($archive, ArchiveFile::TEXT_DONE, 2);
    $partly = $make($archive, ArchiveFile::TEXT_PENDING, 1);
    $make($archive, ArchiveFile::TEXT_NONE, 0);
    $make($archive, ArchiveFile::TEXT_FAILED, 0);

    // A read document in another archive: the filter's subquery is per archive.
    $other = Archive::create(['slug' => 'hr', 'name' => 'HR']);
    $make($other, ArchiveFile::TEXT_DONE, 3);

    // super_admin because ArchiveTestSchema has no roles table to answer
    // hasPermission() from; access itself is ArchiveToolboxTest's to prove.
    $user = User::create(['name' => 'Finance', 'email' => 'finance@samirgroup.com', 'password' => 'x', 'role' => 'super_admin']);
    ArchiveMember::create(['archive_id' => $archive->id, 'user_id' => $user->id, 'can_view' => true]);

    $search = new DocumentSearch(ArchiveAccess::for($user));
    $criteria = $search->criteriaFrom(new Request(['ai_read' => '1']), $archive);

    expect($search->hasFilters($criteria))->toBeTrue();
    expect($search->run($archive, $criteria)->pluck('archive_documents.id')->sort()->values()->all())
        ->toBe([$read->id, $partly->id]);

    ArchiveTestSchema::drop();
});
