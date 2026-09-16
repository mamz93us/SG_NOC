<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Identity\LicenseHolderClassifier;
use App\Services\Identity\LicenseHolderReview;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ITAM ▸ Reports ▸ Microsoft 365 License Review: every Azure account holding a
 * paid Microsoft licence, sorted by whether a real employee needs it (see
 * LicenseHolderClassifier). Filterable by category, searchable, and exported as
 * CSV with the same filters.
 *
 * The latest fingerprint punch is attendance data, so it is shown, and
 * exported, only to holders of view-attendance.
 */
class MicrosoftLicenseReportController extends Controller
{
    private const PER_PAGE = 100;

    public function index(Request $request)
    {
        $review = LicenseHolderReview::build();

        $category = $request->query('category');
        if (! is_string($category) || ! array_key_exists($category, LicenseHolderClassifier::CATEGORIES)) {
            $category = null;
        }
        $search = trim((string) $request->query('q', ''));
        $canSeeAttendance = (bool) $request->user()?->can('view-attendance');

        $rows = collect($review['rows'])
            ->when($category, fn ($rows) => $rows->where('category', $category))
            ->when($search !== '', fn ($rows) => $rows->filter(
                fn ($row) => str_contains(mb_strtolower($row['upn'].' '.$row['display_name'].' '.($row['employee']['name'] ?? '')), mb_strtolower($search))
            ))
            ->values();

        if ($request->boolean('csv')) {
            return $this->csv($rows->all(), $canSeeAttendance);
        }

        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('admin.itam.reports.microsoft-licenses', [
            'review' => $review,
            'rows' => $paginator,
            'categories' => LicenseHolderClassifier::CATEGORIES,
            'category' => $category,
            'search' => $search,
            'canSeeAttendance' => $canSeeAttendance,
        ]);
    }

    private function csv(array $rows, bool $withPunches): StreamedResponse
    {
        $headers = ['Category', 'Account (UPN)', 'Display Name', 'Sign-in', 'NOC Employee', 'NOC Status', 'Employee Type',
            'Branch', 'Oracle HR No.', 'Oracle No. On NOC Record', 'Paid Licenses', 'Free Licenses', 'Cost', 'Why'];
        if ($withPunches) {
            array_splice($headers, 10, 0, ['Last Fingerprint Punch']);
        }

        return response()->streamDownload(function () use ($rows, $headers, $withPunches) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                $line = [
                    LicenseHolderClassifier::CATEGORIES[$row['category']]['title'],
                    $row['upn'],
                    $row['display_name'],
                    $row['enabled'] ? 'Enabled' : 'Disabled',
                    $row['employee']['name'] ?? '',
                    $row['employee']['status'] ?? '',
                    $row['employee']['employee_type'] ?? '',
                    $row['employee']['branch'] ?? '',
                    $row['oracle_emp_no'] ?? '',
                    $row['oracle_on_record'] ? '#'.$row['oracle_on_record'] : '',
                    implode(', ', array_column($row['paid'], 'name')),
                    implode(', ', $row['free']),
                    implode('; ', array_map(fn ($currency, $amount) => $currency.' '.number_format($amount, 2, '.', ''), array_keys($row['costs']), $row['costs'])),
                    $row['reason'],
                ];
                if ($withPunches) {
                    array_splice($line, 10, 0, [$row['last_punch'] ? substr($row['last_punch'], 0, 10) : '']);
                }
                fputcsv($out, $line);
            }
            fclose($out);
        }, 'microsoft-365-license-review-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
