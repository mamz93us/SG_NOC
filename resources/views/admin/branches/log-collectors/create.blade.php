@extends('layouts.admin')

@section('title', 'Add Branch Log Collector')

@section('content')
<div class="container-fluid py-3">
    <h4 class="mb-3">Add branch</h4>

    <div class="card">
        <div class="card-body">
            <form action="{{ route('admin.branches.log-collectors.store') }}" method="POST">
                @include('admin.branches.log-collectors._form')
            </form>
        </div>
    </div>

    <div class="alert alert-light border mt-3 small">
        <strong>Quick reminder of the workflow:</strong>
        <ol class="mb-0">
            <li>Build the branch VM and run <code>install.sh</code> there.</li>
            <li>Copy the <code>API_TOKEN</code> it prints.</li>
            <li>Fill out this form (paste the token, set host = the VM's tunnel IP).</li>
            <li>Save → <em>Test</em> on the list page → green badge.</li>
        </ol>
    </div>
</div>
@endsection
