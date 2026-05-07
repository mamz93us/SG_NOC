@extends('layouts.admin')

@section('title', 'Edit SNMP Device')

@section('content')
<div class="container-fluid py-3">
    <h4 class="mb-3">Edit SNMP device — <code>{{ $device->host }}</code></h4>

    <div class="card">
        <div class="card-body">
            <form action="{{ route('admin.snmp-devices.update', $device) }}" method="POST">
                @method('PUT')
                @include('admin.snmp-devices._form')
            </form>
        </div>
    </div>

    @if($device->last_polled_at)
        <div class="card mt-3">
            <div class="card-body small">
                <div class="row">
                    <div class="col-md-3"><strong>Last polled</strong></div>
                    <div class="col-md-9">{{ $device->last_polled_at->toDateTimeString() }}</div>
                    <div class="col-md-3 mt-2"><strong>Last status</strong></div>
                    <div class="col-md-9 mt-2">{{ $device->last_status ?: '—' }}</div>
                    @if($device->last_error)
                        <div class="col-md-3 mt-2"><strong>Last error</strong></div>
                        <div class="col-md-9 mt-2 text-danger font-monospace small">{{ $device->last_error }}</div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
