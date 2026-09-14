@extends('layouts.hr')

@section('title', 'No access')
@section('bare', '1')

@section('content')
<div class="hr-sign">
    <div class="hr-sign-icon"><i class="bi bi-shield-lock"></i></div>

    <h2>No HR access yet</h2>

    <p class="copy">
        {{ session('error') ?? 'Your account does not have access to the HR portal. Please contact IT to be granted access.' }}
    </p>

    @auth
        <p class="who">Signed in as <strong>{{ auth()->user()->email }}</strong></p>
    @endauth

    <form method="POST" action="{{ route('portal.hr.logout') }}">
        @csrf
        <button type="submit" class="btn btn-outline-secondary hr-sign-btn">
            <i class="bi bi-box-arrow-right"></i>Sign out / use another account
        </button>
    </form>
</div>
@endsection
