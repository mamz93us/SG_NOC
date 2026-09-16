<?php

use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveAiBatch;
use App\Models\Archive\ArchiveAiProposal;
use App\Models\Archive\ArchiveDocument;
use App\Models\Archive\ArchiveFile;
use App\Models\Archive\ArchiveInboxItem;
use App\Models\Archive\ArchiveScanEndpoint;
use App\Models\Archive\ArchiveTask;
use Tests\Unit\Archive\ArchiveTestSchema;

/**
 * Scopes must name their own table.
 *
 * Asserted against the generated SQL rather than by running a query, because the
 * fault this guards CANNOT be reproduced by running one here: MySQL rejects an
 * ambiguous column with SQLSTATE 1052, while SQLite quietly picks a table and
 * carries on. So the review queue passed every test and then returned a 500 in
 * production the moment it joined archive_documents — which has a `status` column
 * of its own — to a scope filtering on a bare `status`.
 *
 * Every scope here is one join away from the same fault, so each says which table
 * it means. Quotes are normalised away because the two drivers disagree about
 * them: MySQL writes `x`.`y` and SQLite writes "x"."y".
 */
uses(Tests\TestCase::class);

/** The scope's SQL with the driver's identifier quoting removed. */
function scopeSql(\Illuminate\Database\Eloquent\Builder $query): string
{
    return str_replace(['`', '"'], '', $query->toSql());
}

test('every archive scope qualifies the column it filters on', function () {
    $cases = [
        'ArchiveAiProposal::pending' => [ArchiveAiProposal::query()->pending(), 'archive_ai_proposals.status'],
        'ArchiveDocument::active' => [ArchiveDocument::query()->active(), 'archive_documents.status'],
        'ArchiveAiBatch::runnable' => [ArchiveAiBatch::query()->runnable(), 'archive_ai_batches.status'],
        'ArchiveInboxItem::waiting' => [ArchiveInboxItem::query()->waiting(), 'archive_inbox_items.status'],
        'ArchiveInboxItem::needingAi' => [ArchiveInboxItem::query()->needingAi(), 'archive_inbox_items.ai_status'],
        'ArchiveTask::pending' => [ArchiveTask::query()->pending(), 'archive_tasks.status'],
        'ArchiveFile::onArcMate' => [ArchiveFile::query()->onArcMate(), 'archive_files.disk'],
        'ArchiveFile::transferFailed' => [ArchiveFile::query()->transferFailed(), 'archive_files.disk'],
        'ArchiveFile::needingText' => [ArchiveFile::query()->needingText(), 'archive_files.text_status'],
        'ArchiveScanEndpoint::enabled' => [ArchiveScanEndpoint::query()->enabled(), 'archive_scan_endpoints.enabled'],
        'Archive::readable' => [Archive::query()->readable(), 'archives.readable'],
        'Archive::syncable' => [Archive::query()->syncable(), 'archives.mode'],
    ];

    foreach ($cases as $label => [$query, $qualified]) {
        $this->assertStringContainsString(
            $qualified,
            scopeSql($query),
            $label.' does not name its own table, so a join makes it ambiguous'
        );
    }
});

test('the review queue query shape that actually broke', function () {
    // The production failure reproduced as SQL: proposals joined to documents,
    // where BOTH tables have a `status` column. On MySQL the unqualified form was
    // "Column 'status' in where clause is ambiguous"; the fix is that the where
    // clause now says which one it means.
    $sql = scopeSql(
        ArchiveAiProposal::query()
            ->pending()
            ->join('archive_documents', 'archive_documents.id', '=', 'archive_ai_proposals.archive_document_id')
    );

    expect($sql)->toContain('archive_ai_proposals.status');

    // And nothing in the where clause is a bare `status`.
    $where = mb_substr($sql, (int) mb_strpos($sql, 'where'));
    expect($where)->not->toMatch('/(?<![.\w])status\s*=/');
});

test('a scope still filters correctly once qualified', function () {
    // Qualifying must not change what a scope MEANS — only how it says it.
    ArchiveTestSchema::create();

    $archive = Archive::create(['slug' => 'q', 'name' => 'Q']);
    $document = ArchiveDocument::create([
        'archive_id' => $archive->id,
        'status' => ArchiveDocument::STATUS_ACTIVE,
    ]);
    $hidden = ArchiveDocument::create([
        'archive_id' => $archive->id,
        'status' => ArchiveDocument::STATUS_DELETED_IN_ARCMATE,
    ]);

    $active = ArchiveDocument::query()->active()->pluck('id')->all();

    expect($active)->toContain($document->id);
    expect($active)->not->toContain($hidden->id);

    ArchiveTestSchema::drop();
});
