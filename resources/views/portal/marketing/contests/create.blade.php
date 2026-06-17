@extends('layouts.marketing')
@section('title', 'New World Cup Contest')

@section('content')
@php $flagBase = asset(trim((string) config('worldcup.flag_path', 'images/flags'), '/')); @endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0 fw-bold"><i class="bi bi-trophy-fill text-warning me-2"></i>New “Guess the Score” Contest</h4>
    <a href="{{ route('portal.marketing.contests.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

@if($errors->any())
<div class="alert alert-danger py-2"><ul class="mb-0 ps-3">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
@endif

<div class="card shadow-sm" style="max-width:640px;">
    <div class="card-body">
        <form method="POST" action="{{ route('portal.marketing.contests.store') }}"
              x-data="{ home: @js(old('home','')), away: @js(old('away','')), flag(c){ return c ? '{{ $flagBase }}/'+c+'.png' : '' } }">
            @csrf

            <div class="mb-3">
                <label class="form-label fw-semibold">Contest name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" maxlength="150" required
                       value="{{ old('name') }}" placeholder="e.g. World Cup Final — Guess the Score">
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Home team <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text p-1 justify-content-center" style="width:48px;">
                            <img :src="flag(home)" x-show="home" style="height:24px;width:auto;" alt="">
                        </span>
                        <select name="home" class="form-select" x-model="home" required>
                            <option value="">— select —</option>
                            @foreach($teams as $t)
                            <option value="{{ $t['code'] }}">{{ $t['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Away team <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text p-1 justify-content-center" style="width:48px;">
                            <img :src="flag(away)" x-show="away" style="height:24px;width:auto;" alt="">
                        </span>
                        <select name="away" class="form-select" x-model="away" required>
                            <option value="">— select —</option>
                            @foreach($teams as $t)
                            <option value="{{ $t['code'] }}">{{ $t['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-7">
                    <label class="form-label fw-semibold">Kick-off <small class="text-muted fw-normal">(shown on the form)</small></label>
                    <input type="text" name="kickoff" class="form-control" maxlength="60"
                           value="{{ old('kickoff') }}" placeholder="e.g. 19 Jul 2026, 6:00 PM">
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Close guesses on <small class="text-muted fw-normal">(optional)</small></label>
                    <input type="date" name="expires_at" class="form-control" value="{{ old('expires_at') }}">
                </div>
            </div>

            <div class="alert alert-light border small">
                <i class="bi bi-info-circle me-1"></i>After saving you'll get a <strong>merge tag</strong> to paste into your
                email campaign. Each employee receives their own one-time link (no login), so every guess is tied to a
                named person and nobody can enter twice. The festive World Cup page (both flags + score boxes) is generated automatically.
            </div>

            <button type="submit" class="btn btn-primary"><i class="bi bi-trophy me-1"></i>Create contest</button>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js" defer></script>
@endsection
