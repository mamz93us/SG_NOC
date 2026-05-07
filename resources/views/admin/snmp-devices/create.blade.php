@extends('layouts.admin')

@section('title', 'Add SNMP Device')

@section('content')
<div class="container-fluid py-3">
    <h4 class="mb-3">Add SNMP device</h4>

    <div class="card">
        <div class="card-body">
            <form action="{{ route('admin.snmp-devices.store') }}" method="POST">
                @include('admin.snmp-devices._form')
            </form>
        </div>
    </div>
</div>
@endsection
