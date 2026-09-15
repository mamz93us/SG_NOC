@extends('layouts.archive')

@section('title', 'No access')

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="arc-card p-4 mt-5 text-center">
                <i class="bi bi-folder-x" style="font-size:2rem;color:var(--ink-soft)"></i>
                <h1 class="h5 mt-3 mb-2">No archives yet</h1>

                {{--
                    Signed in, but no membership anywhere. Deliberately a
                    separate page from the login rather than a redirect loop
                    back to Microsoft — the sign-in worked, and telling someone
                    to sign in again when they already have is how the marketing
                    and HR portals used to bounce people.
                --}}
                <p class="arc-muted mb-3">
                    You are signed in as {{ auth()->user()?->email }}, but you have not been given
                    access to any archive.
                </p>
                <p class="arc-muted small mb-0">
                    Ask IT to add you to the archives you need. Access is granted per archive —
                    invoices, delivery notes and HR files are each separate.
                </p>
            </div>
        </div>
    </div>
@endsection
