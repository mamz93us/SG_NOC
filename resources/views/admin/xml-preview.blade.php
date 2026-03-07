@extends('layouts.admin')

@section('content')
<h3 class="mb-3">Phonebook XML Preview</h3>

<div class="mb-3">
    <a href="{{ route('phonebook.xml') }}" class="btn btn-primary" target="_blank">
        Download XML
    </a>
</div>

<div class="card mb-4">
    <div class="card-header bg-dark text-white">
        <strong>Branches (Groups)</strong>
    </div>
    <div class="card-body p-0">
        <table class="table table-bordered m-0">
            <thead class="table-light">
                <tr>
                    <th width="80">ID</th>
                    <th>Name</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($branches as $branch)
                <tr>
                    <td>{{ $branch->id }}</td>
                    <td>{{ $branch->name }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="2" class="text-center">No branches available.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header bg-dark text-white">
        <strong>Contacts</strong>
    </div>
    <div class="card-body p-0">
        <table class="table table-bordered m-0">
            <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Branch</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($contacts as $contact)
                <tr>
                    <td>{{ $contact->first_name }} {{ $contact->last_name }}</td>
                    <td>{{ $contact->phone }}</td>
                    <td>{{ $contact->email }}</td>
                    <td>{{ $contact->branch->name ?? '—' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" class="text-center">No contacts available.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
``
