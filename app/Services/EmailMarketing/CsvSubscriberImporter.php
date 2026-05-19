<?php

namespace App\Services\EmailMarketing;

use App\Models\EmailMarketing\EmailList;
use App\Models\EmailMarketing\EmailSubscriber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Reads CSV/XLSX rows and imports them into email_subscribers + the
 * given list. Column mapping is required — caller passes
 * ['email' => 0, 'first_name' => 1, ...] (header index map).
 *
 * Returns counts: ['imported' => N, 'skipped_invalid' => N, 'skipped_suppressed' => N].
 *
 * Uses Maatwebsite/Excel (already installed in SG_NOC) to parse files.
 */
class CsvSubscriberImporter
{
    public function __construct(private SuppressionManager $suppressions) {}

    /**
     * @param  string  $filePath  Absolute path to a CSV (or XLSX)
     * @param  array  $mapping  ['email' => 0, 'first_name' => 1, 'last_name' => 2, 'attributes' => [3,4,5]]
     * @param  array  $attrColumnNames  ['country','department',...]  (parallel to mapping['attributes'])
     */
    public function import(EmailList $list, string $filePath, array $mapping, array $attrColumnNames = [], bool $skipHeader = true, ?int $userId = null): array
    {
        $rows = $this->readRows($filePath);
        if ($skipHeader && count($rows) > 0) {
            array_shift($rows);
        }

        $stats = ['imported' => 0, 'skipped_invalid' => 0, 'skipped_suppressed' => 0];
        $emailIdx = $mapping['email'] ?? null;
        if ($emailIdx === null) {
            throw new \InvalidArgumentException('Mapping must include "email" column index.');
        }

        DB::transaction(function () use ($rows, $list, $mapping, $attrColumnNames, $emailIdx, &$stats) {
            foreach (array_chunk($rows, 500) as $chunk) {
                foreach ($chunk as $row) {
                    $email = trim((string) ($row[$emailIdx] ?? ''));
                    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $stats['skipped_invalid']++;

                        continue;
                    }
                    if ($this->suppressions->isSuppressed($email)) {
                        $stats['skipped_suppressed']++;

                        continue;
                    }

                    $email = strtolower($email);
                    $attrs = [];
                    if (! empty($mapping['attributes']) && is_array($mapping['attributes'])) {
                        foreach ($mapping['attributes'] as $i => $col) {
                            $name = $attrColumnNames[$i] ?? ('field_'.$col);
                            $val = trim((string) ($row[$col] ?? ''));
                            if ($val !== '') {
                                $attrs[$name] = $val;
                            }
                        }
                    }

                    $firstName = trim((string) ($row[$mapping['first_name'] ?? -1] ?? '')) ?: null;
                    $lastName  = trim((string) ($row[$mapping['last_name']  ?? -1] ?? '')) ?: null;

                    // firstOrCreate only writes the second array on insert — so an
                    // existing subscriber's status is never overwritten. New rows
                    // start at 'pending' for double-opt-in lists, 'subscribed' otherwise.
                    $subscriber = EmailSubscriber::firstOrCreate(
                        ['email' => $email],
                        [
                            'first_name'   => $firstName,
                            'last_name'    => $lastName,
                            'source'       => 'import',
                            'attributes'   => $attrs ?: null,
                            'status'       => $list->double_opt_in ? 'pending' : 'subscribed',
                            'confirmed_at' => $list->double_opt_in ? null : now(),
                        ]
                    );

                    // For existing subscribers: update profile fields (name, attributes)
                    // from the CSV, but DON'T touch their status — they might have
                    // unsubscribed/bounced previously.
                    if (! $subscriber->wasRecentlyCreated) {
                        $dirty = array_filter([
                            'first_name' => $firstName,
                            'last_name'  => $lastName,
                            'attributes' => $attrs ?: null,
                        ], fn ($v) => $v !== null);
                        if (! empty($dirty)) {
                            $subscriber->fill($dirty)->save();
                        }
                    }

                    $list->subscribers()->syncWithoutDetaching([
                        $subscriber->id => [
                            'subscribed_at' => $list->double_opt_in ? null : now(),
                            'opt_in_token' => $list->double_opt_in ? Str::random(40) : null,
                            'opt_in_sent_at' => null,
                        ],
                    ]);

                    $stats['imported']++;
                }
            }
        });

        return $stats;
    }

    public function previewHeaders(string $filePath): array
    {
        $rows = $this->readRows($filePath, 1);

        return $rows[0] ?? [];
    }

    private function readRows(string $filePath, ?int $limit = null): array
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $rows = [];

        if ($ext === 'csv' || $ext === 'txt') {
            if (($handle = fopen($filePath, 'r')) !== false) {
                while (($data = fgetcsv($handle)) !== false) {
                    $rows[] = $data;
                    if ($limit && count($rows) >= $limit) {
                        break;
                    }
                }
                fclose($handle);
            }

            return $rows;
        }

        // xlsx / xls — delegate to Maatwebsite\Excel for robustness
        $collections = Excel::toArray(new \stdClass, $filePath);
        $first = $collections[0] ?? [];
        if ($limit) {
            $first = array_slice($first, 0, $limit);
        }

        return $first;
    }
}
