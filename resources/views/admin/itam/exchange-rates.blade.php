@extends('layouts.admin')
@section('title', 'Exchange Rates')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-currency-exchange me-2"></i>Exchange Rates</h4>
        <a href="{{ route('admin.itam.reports.subscription-payments') }}" class="btn btn-sm btn-outline-secondary">Payments Report</a>
    </div>

    @if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger py-2">{{ $errors->first() }}</div>@endif

    <p class="text-muted small mb-3">
        The rates behind the combined totals on the subscription reports. Enter what finance actually books at —
        nothing here is fetched automatically, on purpose: a report whose totals move on their own between two runs
        cannot be reconciled. Leave a rate blank to fall back to the indicative default, in which case every total
        built on it is labelled <em>not reviewed</em>.
    </p>

    <div class="alert alert-light border small py-2">
        <i class="bi bi-info-circle me-1"></i>
        Each rate is <strong>how many units of that currency equal 1 {{ $base }}</strong>.
        {{ $base }} is the base and is fixed at 1.
    </div>

    <form method="POST" action="{{ route('admin.itam.exchange-rates.update') }}">
        @csrf @method('PUT')
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Currency</th>
                            <th style="min-width:190px">Units per 1 {{ $base }}</th>
                            <th style="min-width:160px">Rate Date</th>
                            <th style="min-width:200px">Source</th>
                            <th>Status</th>
                            <th>Last Set By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($currencies as $code)
                        @php
                            $row = $stored[$code] ?? null;
                            $live = $rates[$code] ?? null;
                            $isBase = $code === $base;
                        @endphp
                        <tr>
                            <td class="fw-semibold">
                                {{ $code }}
                                @if($isBase)<span class="badge bg-secondary ms-1">base</span>@endif
                            </td>
                            <td>
                                <input type="number" step="0.000001" min="0.000001"
                                       name="rates[{{ $code }}][units_per_base]"
                                       class="form-control form-control-sm font-monospace"
                                       value="{{ old("rates.$code.units_per_base", $row?->units_per_base) }}"
                                       placeholder="{{ $live ? rtrim(rtrim(number_format($live['rate'], 6, '.', ''), '0'), '.') : '' }}"
                                       @if($isBase) readonly value="1" @endif>
                            </td>
                            <td>
                                <input type="date" name="rates[{{ $code }}][rate_date]" class="form-control form-control-sm"
                                       value="{{ old("rates.$code.rate_date", $row?->rate_date?->format('Y-m-d')) }}">
                            </td>
                            <td>
                                <input type="text" name="rates[{{ $code }}][source]" class="form-control form-control-sm"
                                       maxlength="150" placeholder="e.g. CIB selling rate"
                                       value="{{ old("rates.$code.source", $row?->source) }}">
                            </td>
                            <td>
                                @if($row)
                                    <span class="badge bg-success">Reviewed</span>
                                    @if($row->isStale())
                                    <div><span class="badge bg-warning text-dark mt-1">{{ $row->ageInDays() }} days old</span></div>
                                    @endif
                                @else
                                    <span class="badge bg-warning text-dark">Indicative</span>
                                    <div class="small text-muted">falls back to config</div>
                                @endif
                            </td>
                            <td class="small text-muted">
                                @if($row)
                                    {{ $row->updatedBy?->name ?? 'system' }}
                                    <div>{{ $row->updated_at?->format('d M Y H:i') }}</div>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
            @can('manage-itam')
            <div class="card-footer bg-white text-end">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Save Rates</button>
            </div>
            @endcan
        </div>
    </form>
</div>
@endsection
