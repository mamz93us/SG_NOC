<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\IspConnection;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IspReportController extends Controller
{
    public function index(Request $request)
    {
        $query = $this->applyFilters($request, IspConnection::with('branch'));

        $connections = $query->orderBy('branch_id')->orderBy('provider')->get();

        $byBranch = $connections->groupBy(fn ($c) => $c->branch?->name ?? 'No Branch');
        $totalCost = (float) $connections->sum('monthly_cost');

        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $providers = IspConnection::query()->whereNotNull('provider')->distinct()->pluck('provider')->sort()->values();

        return view('admin.network.isp-report.index', [
            'connections' => $connections,
            'byBranch' => $byBranch,
            'totalCost' => $totalCost,
            'branches' => $branches,
            'providers' => $providers,
            'connectionTypes' => IspConnection::CONNECTION_TYPES,
            'customerTypes' => IspConnection::CUSTOMER_TYPES,
            'paymentTypes' => IspConnection::PAYMENT_TYPES,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->applyFilters($request, IspConnection::with('branch'));
        $connections = $query->orderBy('branch_id')->orderBy('provider')->get();

        $filename = 'isp_report_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($connections) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Branch', 'Provider', 'Account #', 'Connection Type', 'Customer Type',
                'Payment Type', 'Package', 'Billing Day', 'Monthly Cost', 'Currency',
                'Renewal Date', 'Contract End', 'Circuit ID', 'Static IP',
            ]);
            foreach ($connections as $c) {
                fputcsv($out, [
                    $c->branch?->name ?? '',
                    $c->provider,
                    $c->account_number,
                    $c->connection_type,
                    $c->customer_type,
                    $c->payment_type,
                    $c->package,
                    $c->billing_day,
                    $c->monthly_cost,
                    $c->currency,
                    $c->renewal_date?->format('Y-m-d'),
                    $c->contract_end?->format('Y-m-d'),
                    $c->circuit_id,
                    $c->static_ip,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function applyFilters(Request $request, $query)
    {
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('provider')) {
            $query->where('provider', $request->provider);
        }
        if ($request->filled('account_number')) {
            $query->where('account_number', 'like', '%'.$request->account_number.'%');
        }
        if ($request->filled('connection_type')) {
            $query->where('connection_type', $request->connection_type);
        }
        if ($request->filled('customer_type')) {
            $query->where('customer_type', $request->customer_type);
        }

        return $query;
    }
}
