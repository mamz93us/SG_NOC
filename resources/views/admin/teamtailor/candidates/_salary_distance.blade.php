{{--
    On top of a candidate's profile: the salary their answers state, where they live, and how far that is from the office.
    Needs $salary (SalaryAnswers::extract), $profile, $applications, $screenedLocation and $canUseRecruitmentAi.
--}}
@php
    $expectedSalary = $salary['expected'] ?? null;
    $currentSalary = $salary['current'] ?? null;
    $raise = \App\Services\Recruitment\SalaryAnswers::raisePercent($expectedSalary, $currentSalary);
    $severalApplications = count($applications) > 1;
    $aiLivesIn = $screenedLocation['lives_in'] ?? null;
@endphp
<div class="row row-cols-1 row-cols-sm-2 {{ $canUseRecruitmentAi ? 'row-cols-xl-4' : 'row-cols-xl-3' }} g-3 mb-3">
    <div class="col">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted text-uppercase small mb-1"><i class="bi bi-cash-coin me-1"></i>Expected salary</div>
                @if($expectedSalary)
                    <div class="fs-4 fw-bold lh-sm" title="Their answer: {{ $expectedSalary['text'] }}">{{ \App\Services\Recruitment\SalaryAnswers::label($expectedSalary) }}</div>
                    <div class="small text-muted mt-1">
                        {{ collect([
                            $raise !== null ? ($raise >= 0 ? '+' : '').$raise.'% on current' : null,
                            $severalApplications && $expectedSalary['from'] ? 'asked for '.$expectedSalary['from'] : null,
                        ])->filter()->implode(' · ') ?: 'From their application answers' }}
                    </div>
                @else
                    <div class="fs-5 text-muted">Not stated</div>
                    <div class="small text-muted mt-1">Not in their application answers</div>
                @endif
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted text-uppercase small mb-1"><i class="bi bi-wallet2 me-1"></i>Current / last salary</div>
                @if($currentSalary)
                    <div class="fs-4 fw-bold lh-sm" title="Their answer: {{ $currentSalary['text'] }}">{{ \App\Services\Recruitment\SalaryAnswers::label($currentSalary) }}</div>
                    <div class="small text-muted mt-1">{{ $severalApplications && $currentSalary['from'] ? 'Given for '.$currentSalary['from'] : 'From their application answers' }}</div>
                @else
                    <div class="fs-5 text-muted">Not stated</div>
                    <div class="small text-muted mt-1">Not in their application answers</div>
                @endif
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted text-uppercase small mb-1"><i class="bi bi-geo-alt me-1"></i>Lives in</div>
                @if($aiLivesIn || $profile['location'])
                    <div class="fs-5 fw-bold lh-sm">{{ $aiLivesIn ?? $profile['location'] }}</div>
                    <div class="small text-muted mt-1">{{ $aiLivesIn ? 'Read by Recruitment AI from the CV and answers' : 'From their Teamtailor profile' }}</div>
                @else
                    <div class="fs-5 text-muted">Not stated</div>
                @endif
            </div>
        </div>
    </div>
    @if($canUseRecruitmentAi)
    <div class="col">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted text-uppercase small mb-1"><i class="bi bi-signpost-split me-1"></i>Distance to office</div>
                @if(($screenedLocation['distance_km'] ?? null) !== null)
                    <div class="fs-4 fw-bold lh-sm">~{{ number_format($screenedLocation['distance_km']) }} km</div>
                    <div class="small text-muted mt-1">
                        {{ collect([
                            $screenedLocation['office'] ? 'to '.$screenedLocation['office'] : null,
                            $screenedLocation['relocation_needed'] === 'yes' ? 'would move city' : null,
                            'estimated by Recruitment AI',
                        ])->filter()->implode(' · ') }}
                    </div>
                @else
                    <div class="fs-5 text-muted">Not estimated</div>
                    <div class="small text-muted mt-1">Recruitment AI estimates it when it screens a job that has its office set</div>
                @endif
            </div>
        </div>
    </div>
    @endif
</div>
