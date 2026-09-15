{{-- One applicant's full AI evaluation. $screening is a RecruitmentScreening. --}}
@php $evaluation = $screening->evaluation ?? []; @endphp
<div class="row g-3 small">
    <div class="col-md-6">
        @if (! empty($evaluation['must_haves']))
            <div class="fw-semibold mb-1">Must-haves</div>
            <ul class="list-unstyled mb-3">
                @foreach ($evaluation['must_haves'] as $check)
                    <li class="mb-1">
                        @if ($check['met'] === 'yes')
                            <i class="bi bi-check-circle-fill text-success me-1" title="Met"></i>
                        @elseif ($check['met'] === 'no')
                            <i class="bi bi-x-circle-fill text-danger me-1" title="Not met"></i>
                        @else
                            <i class="bi bi-question-circle-fill text-warning me-1" title="Not shown"></i>
                        @endif
                        <span class="fw-semibold">{{ $check['requirement'] }}</span>
                        @if (! empty($check['evidence']))
                            <div class="text-muted ms-4">{{ $check['evidence'] }}</div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if (! empty($evaluation['strengths']))
            <div class="fw-semibold mb-1 text-success">Strengths</div>
            <ul class="mb-3 ps-3">
                @foreach ($evaluation['strengths'] as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        @endif

        @if (! empty($evaluation['concerns']))
            <div class="fw-semibold mb-1 text-danger">Concerns</div>
            <ul class="mb-0 ps-3">
                @foreach ($evaluation['concerns'] as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="col-md-6">
        <dl class="row mb-2">
            @if (! empty($evaluation['current_role']))
                <dt class="col-5 text-muted fw-normal">Current role</dt>
                <dd class="col-7">{{ $evaluation['current_role'] }}</dd>
            @endif
            @if (isset($evaluation['relevant_years']))
                <dt class="col-5 text-muted fw-normal">Relevant experience</dt>
                <dd class="col-7">{{ rtrim(rtrim(number_format((float) $evaluation['relevant_years'], 1), '0'), '.') }} years</dd>
            @endif
            @if (! empty($evaluation['education']))
                <dt class="col-5 text-muted fw-normal">Education</dt>
                <dd class="col-7">{{ $evaluation['education'] }}</dd>
            @endif
            @if ($screening->cv_read_as === 'images')
                <dt class="col-5 text-muted fw-normal">CV</dt>
                <dd class="col-7">A scan, read from images of its pages</dd>
            @endif
        </dl>

        @if (! empty($evaluation['skills']))
            <div class="fw-semibold mb-1">Skills</div>
            <div class="mb-3">
                @foreach ($evaluation['skills'] as $item)
                    <span class="badge bg-light text-body border me-1 mb-1">{{ $item }}</span>
                @endforeach
            </div>
        @endif

        @if (! empty($evaluation['languages']))
            <div class="fw-semibold mb-1">Languages</div>
            <div class="mb-3">
                @foreach ($evaluation['languages'] as $item)
                    <span class="badge bg-light text-body border me-1 mb-1">{{ $item }}</span>
                @endforeach
            </div>
        @endif

        @if (! empty($evaluation['interview_questions']))
            <div class="fw-semibold mb-1">Suggested interview questions</div>
            <ol class="mb-0 ps-3">
                @foreach ($evaluation['interview_questions'] as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ol>
        @endif
    </div>
</div>
