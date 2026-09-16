@extends('layouts.archive')

@section('title', $nothingSetUp ? 'Not set up yet' : 'No access')

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="arc-card p-4 mt-5 text-center">
                {{--
                    Signed in, and still nothing to show. Deliberately a separate
                    page rather than a redirect back to Microsoft — the sign-in
                    worked, and telling someone to sign in again when they already
                    have is how the marketing and HR portals used to bounce people.

                    Which of the two messages below applies matters: one is a
                    request to make of IT, the other is a job for whoever is
                    reading it.
                --}}
                @if ($nothingSetUp)
                    <i class="bi bi-plug" style="font-size:2rem;color:var(--ink-soft)"></i>
                    <h1 class="h5 mt-3 mb-2">Nothing is set up yet</h1>

                    <p class="arc-muted mb-3">
                        The portal is running, but no archive has been connected to it.
                        Until ArcMate is connected there is nothing here to read.
                    </p>

                    @if ($canManage)
                        <a href="{{ route('admin.archive.index') }}" class="btn btn-brand btn-sm">
                            <i class="bi bi-sliders"></i> Set the archive up in the NOC
                        </a>
                        <p class="arc-muted small mb-0 mt-3">
                            You will need the read-only SQL login and the mounted file share
                            first — the settings page in the NOC tests both and says which is
                            missing.
                        </p>
                    @else
                        <p class="arc-muted small mb-0">
                            Ask IT to connect it.
                        </p>
                    @endif
                @else
                    <i class="bi bi-folder-x" style="font-size:2rem;color:var(--ink-soft)"></i>
                    <h1 class="h5 mt-3 mb-2">No archives yet</h1>

                    <p class="arc-muted mb-3">
                        You are signed in as {{ auth()->user()?->email }}, but you have not been given
                        access to any archive.
                    </p>
                    <p class="arc-muted small mb-0">
                        Ask IT to add you to the archives you need. Access is granted per archive —
                        invoices, delivery notes and HR files are each separate.
                    </p>
                @endif
            </div>
        </div>
    </div>
@endsection
