@extends('layouts.admin')

@section('title', 'Vacations — Import from Oracle')

@section('content')
@include('admin.vacations._tabs')

<div class="mb-3">
    <h4 class="mb-0 fw-bold"><i class="bi bi-file-earmark-arrow-up me-2 text-primary"></i>Import from Oracle</h4>
    <small class="text-muted">
        Upload Oracle's two vacation sheets as Oracle exports them — the balance sheet, the details sheet, or both.
        Which is which is read from the columns, so the boxes are only a guide.
    </small>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-upload me-1"></i>Upload</div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.vacations.imports.store') }}" enctype="multipart/form-data">
                    @csrf

                    @if (count($books) > 1)
                        <div class="mb-3">
                            <label class="form-label small mb-1">Company</label>
                            <select name="book" class="form-select form-select-sm">
                                @foreach ($books as $key => $book)
                                    <option value="{{ $key }}" @selected(old('book', $defaultBook) === $key)>{{ $book['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    @else
                        <input type="hidden" name="book" value="{{ $defaultBook }}">
                        <div class="small text-muted mb-3">Company: <strong>{{ $books[$defaultBook]['label'] ?? $defaultBook }}</strong></div>
                    @endif

                    <div class="mb-3">
                        <label class="form-label small mb-1">Balance sheet <span class="text-muted font-monospace">(CARRYOVER, ACCRUALS, ABSENCES, TOTAL_BALANCE)</span></label>
                        <input type="file" name="balances_file" accept=".xls,.xlsx,.csv" class="form-control form-control-sm">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small mb-1">Balances are as of</label>
                        <input type="date" name="as_of" value="{{ old('as_of', now()->toDateString()) }}" max="{{ now()->toDateString() }}"
                               class="form-control form-control-sm" style="max-width:180px" required>
                        <div class="form-text">
                            The day Oracle exported the balance sheet. This year's leave grows every month, so the figures are
                            true on that day; the year they count for is this date's year.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small mb-1">Details sheet <span class="text-muted font-monospace">(ABSENCE_TYPE, VAC_START_DATE, VAC_END_DATE)</span></label>
                        <input type="file" name="absences_file" accept=".xls,.xlsx,.csv" class="form-control form-control-sm">
                    </div>

                    <button class="btn btn-primary btn-sm"><i class="bi bi-cloud-arrow-up me-1"></i>Import</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-info-circle me-1"></i>What an import does</div>
            <div class="card-body small">
                <p class="mb-2">
                    <strong>Balance sheet.</strong> Each person's balance for the year is replaced with Oracle's figures:
                    last year's balance carried over, this year's leave earned so far, days used and days left. Nothing is
                    recalculated. A sheet older than the balance already held for someone never overwrites it.
                </p>
                <p class="mb-2">
                    <strong>Details sheet.</strong> New leave records are added. Oracle's export lists every record from some
                    date on, so a record starting inside the sheet's dates that the sheet no longer lists was withdrawn or
                    changed in Oracle: it is marked <em>No longer in Oracle</em> — kept, never deleted — and comes back if a
                    later sheet lists it again. Records from before the sheet's first date are left as they are. Days are
                    counted without the weekend
                    ({{ collect($books[$defaultBook]['weekend'] ?? [])->map(fn ($d) => \Carbon\CarbonImmutable::now()->startOfWeek(0)->addDays($d)->format('l'))->implode(' and ') ?: 'none' }}).
                </p>
                <p class="mb-0">
                    <strong>Employees.</strong> An Oracle number is linked to the employee holding it as their Oracle no. —
                    in the company's branches ({{ implode(', ', $books[$defaultBook]['branches'] ?? []) ?: 'any' }}) when the
                    SSS Egypt and SamirGroup numbers collide. Anyone left unsettled waits under
                    <a href="{{ route('admin.vacations.balances.index', ['show' => 'unlinked']) }}">Balances ▸ Not linked</a>,
                    where their page lets you choose the employee. Your choice is never overwritten by an import.
                </p>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 mt-4">
    <div class="card-header bg-transparent fw-semibold"><i class="bi bi-clock-history me-1"></i>Imports</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>When</th>
                    <th>Sheet</th>
                    <th>Covers</th>
                    <th class="text-end">Rows</th>
                    <th class="text-end">New</th>
                    <th class="text-end">Changed</th>
                    <th class="text-end">Unchanged</th>
                    <th class="text-end" title="Leave records Oracle stopped listing">No longer in Oracle</th>
                    <th class="text-end">Skipped</th>
                    <th class="text-end">Not linked</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($imports as $import)
                    <tr>
                        <td class="text-nowrap">
                            {{ $import->created_at?->format('d M Y H:i') }}
                            <div class="text-muted">{{ $import->importer?->name ?? ($import->source === 'api' ? 'Oracle API' : '—') }}</div>
                        </td>
                        <td>
                            <span class="badge {{ $import->kind === 'balances' ? 'bg-primary' : 'bg-info text-dark' }}">{{ $import->kindLabel() }}</span>
                            <div class="text-muted text-break">{{ $import->filename }}</div>
                        </td>
                        <td class="text-nowrap">
                            @if ($import->as_of)
                                as of {{ $import->as_of->format('d M Y') }}
                            @elseif ($import->window_from)
                                starting {{ $import->window_from->format('d M') }} – {{ $import->window_to->format('d M Y') }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-end">{{ $import->rows }}</td>
                        <td class="text-end">{{ $import->created }}</td>
                        <td class="text-end">{{ $import->updated }}</td>
                        <td class="text-end text-muted">{{ $import->unchanged }}</td>
                        <td class="text-end">{{ $import->kind === 'absences' ? $import->removed.($import->restored ? ' (+'.$import->restored.' back)' : '') : '—' }}</td>
                        <td class="text-end {{ $import->skipped ? 'text-warning-emphasis fw-semibold' : 'text-muted' }}">{{ $import->skipped }}</td>
                        <td class="text-end {{ $import->unlinked ? 'text-warning-emphasis fw-semibold' : 'text-muted' }}">{{ $import->unlinked }}</td>
                    </tr>
                    @if ($import->notes)
                        <tr>
                            <td colspan="10" class="bg-body-tertiary">
                                <ul class="mb-0 ps-3">
                                    @foreach ($import->notes as $note)
                                        <li>{{ $note }}</li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">Nothing imported yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
