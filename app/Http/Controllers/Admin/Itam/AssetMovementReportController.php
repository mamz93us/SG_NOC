<?php

namespace App\Http\Controllers\Admin\Itam;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\Itam\AssetMovements;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ITAM → Reports → Asset Movements: every transfer, retirement and scrap in a
 * period, in one list for the finance team to post against Oracle.
 *
 * It defaults to this month, prints on its own page with signature lines, and
 * exports the same rows as CSV. See Services\Itam\AssetMovements for where the
 * rows come from.
 */
class AssetMovementReportController extends Controller
{
    public function index(Request $request, AssetMovements $movements)
    {
        $filters = $this->filters($request);
        $rows = $movements->rows($filters);
        $summary = $movements->summary($rows);

        if ($request->boolean('csv')) {
            return $this->csv($rows, $filters);
        }

        $data = [
            'rows' => $rows,
            'summary' => $summary,
            'filters' => $filters,
            'kinds' => AssetMovements::KINDS,
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'employees' => Employee::query()->whereNull('linked_primary_employee_id')->orderBy('name')->get(['id', 'name', 'oracle_emp_no']),
        ];

        return $request->boolean('print')
            ? view('admin.itam.reports.movements-print', $data)
            : view('admin.itam.reports.movements', $data);
    }

    /**
     * @return array{from: string, to: string, kind: ?string, branch: ?int, employee: ?int, oracle: ?string}
     */
    private function filters(Request $request): array
    {
        $from = $request->filled('from') ? (string) $request->query('from') : CarbonImmutable::now()->startOfMonth()->toDateString();
        $to = $request->filled('to') ? (string) $request->query('to') : CarbonImmutable::now()->toDateString();

        return [
            'from' => $from,
            'to' => $to,
            'kind' => array_key_exists((string) $request->query('kind'), AssetMovements::KINDS) ? (string) $request->query('kind') : null,
            'branch' => $request->filled('branch') ? (int) $request->query('branch') : null,
            'employee' => $request->filled('employee') ? (int) $request->query('employee') : null,
            'oracle' => $request->filled('oracle') ? trim((string) $request->query('oracle')) : null,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     */
    private function csv($rows, array $filters): StreamedResponse
    {
        $name = 'asset-movements-'.$filters['from'].'-to-'.$filters['to'].'.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Date', 'Recorded', 'Movement', 'Asset Code', 'Oracle Asset No.', 'Asset', 'Type', 'Serial',
                'From', 'From Oracle Emp No.', 'To', 'To Oracle Emp No.', 'Branch', 'Reason', 'Detail', 'Disposal', 'Scrap Request',
                'Purchase Date', 'Purchase Cost', 'Currency', 'Recorded By',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['date']?->format('Y-m-d'),
                    $row['recorded_at']?->format('Y-m-d H:i'),
                    $row['kind_label'],
                    $row['asset_code'],
                    $row['oracle_asset_number'],
                    $row['name'],
                    $row['type'],
                    $row['serial_number'],
                    $row['from'],
                    $row['from_no'],
                    $row['to'] ?? ($row['storage_location'] ?: null),
                    $row['to_no'],
                    $row['branch'],
                    $row['reason_label'],
                    $row['reason'],
                    $row['disposal_method'],
                    $row['workflow_id'],
                    $row['purchase_date']?->format('Y-m-d'),
                    $row['purchase_cost'],
                    $row['purchase_cost'] !== null ? $row['currency'] : null,
                    $row['by'],
                ]);
            }

            fclose($handle);
        }, $name, ['Content-Type' => 'text/csv']);
    }
}
