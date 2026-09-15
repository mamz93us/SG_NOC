{{-- Where to see more of an applicant. $screening is a RecruitmentScreening. --}}
@can('view-candidates')
    <a href="{{ route('admin.candidates.show', $screening->teamtailor_candidate_id) }}">Profile</a>
@endcan
@if (config('teamtailor.app_url'))
    <a href="{{ rtrim((string) config('teamtailor.app_url'), '/') }}/candidates/{{ rawurlencode($screening->teamtailor_candidate_id) }}" target="_blank" rel="noopener">Teamtailor</a>
@endif
{{-- The candidate typed this link: only an http(s) one is ever made clickable. --}}
@if ($screening->linkedin_url && preg_match('#^https?://#i', $screening->linkedin_url))
    <a href="{{ $screening->linkedin_url }}" target="_blank" rel="noopener noreferrer">LinkedIn</a>
@endif
@if ($screening->candidate_email)
    <a href="mailto:{{ $screening->candidate_email }}">{{ $screening->candidate_email }}</a>
@endif
