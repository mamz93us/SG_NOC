{{--
    Leave records as a table: type, dates, days, calendar days and status.
    Shared by the Vacations person page and the Employee profile.

    $records  Collection<\App\Models\Vacation\VacationAbsence>
    $today    CarbonImmutable
    $year     int, for the empty message
--}}
@php
    $days = fn ($value) => \App\Models\Vacation\VacationBalance::days($value === null ? null : (float) $value);
@endphp
<div class="table-responsive">
    <table class="table align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th>Type</th>
                <th>From</th>
                <th>To</th>
                <th class="text-end" title="Days without the weekend">Days</th>
                <th class="text-end">Calendar days</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $record)
                <tr class="{{ $record->removed_at ? 'text-muted' : '' }}">
                    <td><span class="badge {{ \App\Models\Vacation\VacationAbsence::typeBadgeClass($record->absence_type) }}">{{ $record->absence_type }}</span></td>
                    <td class="text-nowrap">{{ $record->start_date->format('D d M Y') }}</td>
                    <td class="text-nowrap">{{ $record->end_date->format('D d M Y') }}</td>
                    <td class="text-end font-monospace">{{ $days($record->days()) }}</td>
                    <td class="text-end font-monospace text-muted">{{ $record->calendar_days }}</td>
                    <td>
                        <span class="badge {{ $record->statusBadgeClass($today) }}">{{ $record->statusLabel($today) }}</span>
                        @if ($record->removed_at)
                            <div class="small">since {{ $record->removed_at->format('d M Y') }}</div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">No leave records in {{ $year }}.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
