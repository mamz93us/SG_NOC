@extends('layouts.admin')

@section('content')
<div class="mb-4">
    <a href="{{ route('admin.print-manager.show', $cupsPrinter) }}" class="text-decoration-none">
        <i class="bi bi-arrow-left me-1"></i>Back to {{ $cupsPrinter->name }}
    </a>
</div>

<form action="{{ route('admin.print-manager.update', $cupsPrinter) }}" method="POST">
    @csrf
    @method('PUT')
    @include('admin.print-manager._form')
</form>
@endsection
