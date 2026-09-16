<?php

namespace App\Services\Archive\ArcMate;

/**
 * What the sync needs from an ArcMate project database.
 *
 * An interface for one honest reason: ArcMateReader speaks SQL Server and
 * nothing else — `SELECT TOP (n)`, `DATALENGTH()`, bracket-quoted columns — so
 * it cannot be pointed at an in-memory SQLite stand-in the way the BioTime
 * readers can. The logic actually worth testing (what maps to what, how
 * watermarks advance, which values a person's edit protects, what a deletion
 * does) lives in ArchiveSyncService, and this is what lets the tests hand it a
 * fake ArcMate without a SQL Server.
 *
 * Everything here is a read. There is no write method by design.
 */
interface ReadsArcMate
{
    /** @return array<int,object> documents with arcId above the watermark, oldest first */
    public function documentsAfter(int $afterArcId, array $columns, int $limit): array;

    /** @return array<int,object> */
    public function documentsByIds(array $arcIds, array $columns): array;

    /** @return array<int,object> files with arcId above the watermark, oldest first */
    public function filesAfter(int $afterArcId, int $limit): array;

    /** @return array<int,object> */
    public function filesForDocuments(array $documentArcIds): array;

    /** @return array<int,object> track rows above the watermark: which documents changed */
    public function documentTrackAfter(int $afterArcId, int $limit): array;

    /** @return array<int,object> deletions above the watermark */
    public function deletedDocumentsAfter(int $afterArcId, int $limit): array;

    /** @return array<int,string> document arcId => yyyyMMddHHmmss */
    public function earliestTrackDates(array $documentArcIds): array;

    public function documentCount(): int;

    public function fileCount(): int;
}
