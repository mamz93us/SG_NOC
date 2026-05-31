@extends('layouts.marketing')

@section('title', 'Courses')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    @if (session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="bi bi-award me-2"></i>Courses</h4>
        @can('manage-courses')
            <a href="{{ route('portal.marketing.courses.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus me-1"></i>New course
            </a>
        @endcan
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th><th>Description</th><th>Certificates</th><th>Sent</th><th></th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($courses as $course)
                    <tr>
                        <td><a href="{{ route('portal.marketing.courses.show', $course) }}"><strong>{{ $course->name }}</strong></a></td>
                        <td><small class="text-muted">{{ $course->description }}</small></td>
                        <td>{{ $course->certificates_count }}</td>
                        <td>{{ $course->sent_count }}</td>
                        <td>
                            @can('manage-courses')
                                <a href="{{ route('portal.marketing.courses.edit', $course) }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No courses yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $courses->links() }}</div>
    </div>
</div>
@endsection
