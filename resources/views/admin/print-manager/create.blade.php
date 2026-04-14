@extends('layouts.admin')

@section('content')
<div class="mb-4">
    <a href="{{ route('admin.print-manager.index') }}" class="text-decoration-none">
        <i class="bi bi-arrow-left me-1"></i>Back to Print Manager
    </a>
</div>

<form action="{{ route('admin.print-manager.store') }}" method="POST">
    @csrf
    @include('admin.print-manager._form')
</form>
@endsection
