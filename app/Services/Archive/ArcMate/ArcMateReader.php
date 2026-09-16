<?php

namespace App\Services\Archive\ArcMate;

use Illuminate\Database\ConnectionInterface;

/**
 * Every read the NOC makes from one ArcMate project database.
 *
 * Read-only by construction: there is no method here that writes, and the login
 * it runs as holds db_datareader and nothing more.
 *
 * ArcMate's tables have no primary keys and no foreign keys — only ascending
 * `arcId` values with a unique index — so every incremental read is "give me
 * the rows whose arcId is greater than the one I already have", which is also
 * why a sync that is interrupted simply resumes.
 *
 * The index columns (S1, S2, D1, C1 …) differ per project and are named in
 * arcDesign.xml, so they arrive here as a caller-supplied list. That list is
 * the one place a column name reaches SQL as text rather than as a binding,
 * which is why safeColumn() is strict and unconditional: anything that is not
 * a letter or two followed by digits is refused outright.
 */
class ArcMateReader implements ReadsArcMate
{
    /** ArcMate's own object type for a document, in tblDeletedObjects. */
    private const OBJECT_TYPE_DOCUMENT = 1;

    public function __construct(private ConnectionInterface $db) {}

    /**
     * Whether a string is one of ArcMate's index column names.
     *
     * S1, S2, D1, C1 — a letter or two, then a number. Everything else is
     * refused, because this is the only value in the class that is interpolated
     * into SQL instead of bound.
     */
    public static function safeColumn(?string $column): bool
    {
        return (bool) preg_match('/^[A-Za-z]{1,2}\d{1,3}$/', trim((string) $column));
    }

    /**
     * @param  array<int,string>  $columns
     * @return array<int,string> only the ones safe to name in SQL
     */
    public static function safeColumns(array $columns): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($column) => strtoupper(trim((string) $column)), $columns),
            fn (string $column) => self::safeColumn($column)
        )));
    }

    // ─── Counts, for the Test page and reconciliation ─────────────

    public function documentCount(): int
    {
        return (int) ($this->db->selectOne('SELECT COUNT(*) AS c FROM tblDocuments')->c ?? 0);
    }

    public function fileCount(): int
    {
        return (int) ($this->db->selectOne('SELECT COUNT(*) AS c FROM tblFiles')->c ?? 0);
    }

    public function maxDocumentId(): int
    {
        return (int) ($this->db->selectOne('SELECT MAX(arcId) AS m FROM tblDocuments')->m ?? 0);
    }

    public function maxFileId(): int
    {
        return (int) ($this->db->selectOne('SELECT MAX(arcId) AS m FROM tblFiles')->m ?? 0);
    }

    /**
     * How much OCR text this project actually has.
     *
     * Worth knowing before planning any AI reading: on SPS Invoices the sampled
     * rows came back NULL, so the answer may well be "none", and that decides
     * whether word search has to be paid for page by page.
     *
     * @return array{files_with_text:int, total_bytes:int}
     */
    public function fullTextSummary(): array
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS files_with_text, COALESCE(SUM(CAST(DATALENGTH(arcFullText) AS bigint)), 0) AS total_bytes
             FROM tblFiles WHERE arcFullText IS NOT NULL AND DATALENGTH(arcFullText) > 0'
        );

        return [
            'files_with_text' => (int) ($row->files_with_text ?? 0),
            'total_bytes' => (int) ($row->total_bytes ?? 0),
        ];
    }

    /** @return array<int,object> The document status values in use, with counts. */
    public function documentStatuses(): array
    {
        return $this->db->select('SELECT arcStatus, COUNT(*) AS docs FROM tblDocuments GROUP BY arcStatus ORDER BY arcStatus');
    }

    // ─── Incremental reads ───────────────────────────────────────

    /**
     * Documents with an arcId above the watermark, oldest first.
     *
     * @param  array<int,string>  $columns  index columns from arcDesign.xml
     * @return array<int,object>
     */
    public function documentsAfter(int $afterArcId, array $columns, int $limit): array
    {
        $select = $this->documentSelect($columns);

        return $this->db->select(
            "SELECT TOP ({$this->limit($limit)}) {$select} FROM tblDocuments WHERE arcId > ? ORDER BY arcId",
            [$afterArcId]
        );
    }

    /**
     * Specific documents by arcId — for re-reading the ones a track row says
     * changed.
     *
     * @param  array<int,int>  $arcIds
     * @param  array<int,string>  $columns
     * @return array<int,object>
     */
    public function documentsByIds(array $arcIds, array $columns): array
    {
        $arcIds = array_values(array_filter(array_map('intval', $arcIds)));

        if ($arcIds === []) {
            return [];
        }

        $select = $this->documentSelect($columns);
        $placeholders = implode(',', array_fill(0, count($arcIds), '?'));

        return $this->db->select(
            "SELECT {$select} FROM tblDocuments WHERE arcId IN ({$placeholders}) ORDER BY arcId",
            $arcIds
        );
    }

    /**
     * Files with an arcId above the watermark, oldest first.
     *
     * arcFullText is deliberately NOT selected here. It is a `text` column that
     * can hold a whole document, and pulling it alongside every file row would
     * turn a cheap index sync into a slow one; the text pass asks for it
     * separately, and only where there is any.
     *
     * @return array<int,object>
     */
    public function filesAfter(int $afterArcId, int $limit): array
    {
        return $this->db->select(
            'SELECT TOP ('.$this->limit($limit).') arcId, arcDocumentId, arcFileName, arcOrgName, arcFileOrder,
                    arcPageCount, arcFileSize, arcStatus, arcFileCRC, arcSecuritylevel
             FROM tblFiles WHERE arcId > ? ORDER BY arcId',
            [$afterArcId]
        );
    }

    /**
     * The files of specific documents — for re-reading a changed document's
     * file list.
     *
     * @param  array<int,int>  $documentArcIds
     * @return array<int,object>
     */
    public function filesForDocuments(array $documentArcIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $documentArcIds)));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return $this->db->select(
            "SELECT arcId, arcDocumentId, arcFileName, arcOrgName, arcFileOrder,
                    arcPageCount, arcFileSize, arcStatus, arcFileCRC, arcSecuritylevel
             FROM tblFiles WHERE arcDocumentId IN ({$placeholders}) ORDER BY arcDocumentId, arcFileOrder, arcId",
            $ids
        );
    }

    /**
     * The OCR text of specific files, where there is any.
     *
     * @param  array<int,int>  $fileArcIds
     * @return array<int,object>
     */
    public function fullTextForFiles(array $fileArcIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $fileArcIds)));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return $this->db->select(
            "SELECT arcId, CAST(arcFullText AS nvarchar(max)) AS arcFullText
             FROM tblFiles
             WHERE arcId IN ({$placeholders}) AND arcFullText IS NOT NULL AND DATALENGTH(arcFullText) > 0",
            $ids
        );
    }

    // ─── Change detection ────────────────────────────────────────

    /**
     * Track rows above a watermark: which documents were touched, and when.
     *
     * This is how an EDIT is noticed at all. The arcId watermark on
     * tblDocuments only ever finds new documents — a person correcting an
     * invoice number in ArcMate changes a row in place and its arcId does not
     * move, so without this the correction would never arrive.
     *
     * @return array<int,object>
     */
    public function documentTrackAfter(int $afterArcId, int $limit): array
    {
        return $this->db->select(
            'SELECT TOP ('.$this->limit($limit).') arcId, arcDocumentId, arcStatus, arcUser, arcDate
             FROM tblDocumentsTrack WHERE arcId > ? ORDER BY arcId',
            [$afterArcId]
        );
    }

    /** @return array<int,object> */
    public function fileTrackAfter(int $afterArcId, int $limit): array
    {
        return $this->db->select(
            'SELECT TOP ('.$this->limit($limit).') arcId, arcFileId, arcStatus, arcUser, arcDate
             FROM tblFilesTrack WHERE arcId > ? ORDER BY arcId',
            [$afterArcId]
        );
    }

    /**
     * Deletions above a watermark.
     *
     * ArcMate does not remove the row when a document is deleted; it records
     * the fact here, so this is the only way a deletion travels.
     *
     * @return array<int,object>
     */
    public function deletedDocumentsAfter(int $afterArcId, int $limit): array
    {
        return $this->db->select(
            'SELECT TOP ('.$this->limit($limit).') arcId, arcObjectId, arcObjectType, arcUserName, arcDate
             FROM tblDeletedObjects WHERE arcId > ? AND arcObjectType = ? ORDER BY arcId',
            [$afterArcId, self::OBJECT_TYPE_DOCUMENT]
        );
    }

    /**
     * The earliest track date per document — the closest thing ArcMate has to
     * "when was this filed".
     *
     * Only a fallback: tblDocuments has no date column at all, and the
     * timestamp its files are NAMED with is both more precise and available
     * without a second query. This covers a document whose files are gone.
     *
     * @param  array<int,int>  $documentArcIds
     * @return array<int,string> document arcId => yyyyMMddHHmmss
     */
    public function earliestTrackDates(array $documentArcIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $documentArcIds)));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = $this->db->select(
            "SELECT arcDocumentId, MIN(arcDate) AS arcDate
             FROM tblDocumentsTrack WHERE arcDocumentId IN ({$placeholders})
             GROUP BY arcDocumentId",
            $ids
        );

        $dates = [];

        foreach ($rows as $row) {
            $dates[(int) $row->arcDocumentId] = (string) $row->arcDate;
        }

        return $dates;
    }

    // ─── Internals ───────────────────────────────────────────────

    /** @param array<int,string> $columns */
    private function documentSelect(array $columns): string
    {
        $select = ['arcId', 'arcStatus', 'arcFileCount', 'arcSecuritylevel'];

        foreach (self::safeColumns($columns) as $column) {
            $select[] = "[{$column}]";
        }

        return implode(', ', $select);
    }

    /** SQL Server will not take a binding in TOP, so the value is made safe here. */
    private function limit(int $limit): int
    {
        return max(1, min(5000, $limit));
    }
}
