@extends('layouts.admin')

@section('title', 'Email Marketing — Settings')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0"><i class="bi bi-envelope-paper me-2"></i>Email Marketing — Settings</h3>
        <div>
            @if(auth()->user()?->isSuperAdmin())
                @php($pendingApprovals = \App\Models\EmailMarketing\EmailCampaign::where('status', 'pending_approval')->count())
                <a href="{{ route('admin.email-marketing.approvals.index') }}" class="btn btn-outline-primary btn-sm me-2">
                    <i class="bi bi-patch-check me-1"></i>Approvals
                    @if($pendingApprovals > 0)<span class="badge bg-danger">{{ $pendingApprovals }}</span>@endif
                </a>
            @endif
            <a href="{{ route('admin.email-marketing.suppressions') }}" class="btn btn-outline-secondary btn-sm me-2">
                <i class="bi bi-shield-x me-1"></i>Suppressions
            </a>
            <a href="{{ route('admin.email-marketing.quota') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-speedometer2 me-1"></i>Quota
            </a>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">
            @foreach ($errors->all() as $err)<li>{{ $err }}</li>@endforeach
        </ul></div>
    @endif

    @can('manage-email-marketing-settings')
    <form class="card shadow-sm mb-4" method="POST" action="{{ route('admin.email-marketing.settings') }}">
        @csrf
        <div class="card-header bg-light">
            <strong>Master switch</strong>
        </div>
        <div class="card-body">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="email_marketing_enabled" name="email_marketing_enabled" value="1"
                       @checked(old('email_marketing_enabled', $settings->email_marketing_enabled))>
                <label class="form-check-label" for="email_marketing_enabled">
                    Enable email marketing (campaigns will not send if disabled)
                </label>
            </div>
        </div>

        <div class="card-header bg-light"><strong>AWS Credentials</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">AWS region</label>
                    <select name="ses_region" class="form-select" required>
                        <option value="">Select region…</option>
                        @foreach ($regions as $code => $label)
                            <option value="{{ $code }}" @selected(old('ses_region', $settings->ses_region) === $code)>{{ $code }} — {{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Access key ID</label>
                    <input type="text" name="ses_access_key_id" class="form-control" autocomplete="off"
                           value="{{ old('ses_access_key_id', $settings->ses_access_key_id) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Secret access key</label>
                    <input type="password" name="ses_secret_access_key" class="form-control" autocomplete="new-password"
                           placeholder="{{ $settings->ses_secret_access_key ? '•••••••• (leave blank to keep current)' : '' }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Configuration set</label>
                    <input type="text" name="ses_configuration_set" class="form-control"
                           value="{{ old('ses_configuration_set', $settings->ses_configuration_set) }}">
                    <small class="text-muted">SES configuration set name (used for event publishing to SNS).</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">SNS Topic ARN (reference only)</label>
                    <input type="text" name="sns_topic_arn" class="form-control"
                           value="{{ old('sns_topic_arn', $settings->sns_topic_arn) }}">
                    <small class="text-muted">For documentation — the webhook accepts any properly signed SNS notification.</small>
                </div>
            </div>
        </div>

        <div class="card-header bg-light"><strong>Sender Identity</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Default from email</label>
                    <input type="email" name="ses_default_from_email" class="form-control"
                           value="{{ old('ses_default_from_email', $settings->ses_default_from_email) }}">
                    <small class="text-muted">Must be a verified SES identity (domain or email).</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Default from name</label>
                    <input type="text" name="ses_default_from_name" class="form-control"
                           value="{{ old('ses_default_from_name', $settings->ses_default_from_name) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Default reply-to</label>
                    <input type="email" name="ses_default_reply_to" class="form-control"
                           value="{{ old('ses_default_reply_to', $settings->ses_default_reply_to) }}">
                </div>
                <div class="col-md-12">
                    <label class="form-label">Unsubscribe base URL</label>
                    <input type="url" name="ses_unsubscribe_base_url" class="form-control"
                           value="{{ old('ses_unsubscribe_base_url', $settings->ses_unsubscribe_base_url ?? url('/')) }}">
                    <small class="text-muted">Used to build unsubscribe links in campaign emails.</small>
                </div>
            </div>
        </div>

        <div class="card-header bg-light"><strong>Marketing Portal</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-12">
                    <label class="form-label">Marketing portal domain</label>
                    <div class="input-group">
                        <span class="input-group-text">https://</span>
                        <input type="text" name="marketing_domain" class="form-control"
                               value="{{ old('marketing_domain', $settings->marketing_domain ?: \App\Support\Marketing::domain()) }}"
                               placeholder="em.samirgroup.net">
                    </div>
                    <small class="text-muted">
                        Isolated subdomain that serves the email-marketing portal and the recipient-facing
                        unsubscribe links. Point this host's DNS + nginx at this app
                        (see <code>docs/MARKETING_SUBDOMAIN.md</code>). Saving a new value clears the route cache automatically.
                    </small>
                </div>
            </div>
        </div>

        <div class="card-header bg-light"><strong>Campaign Approval</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Internal recipient domains</label>
                    <input type="text" name="email_marketing_internal_domains" class="form-control"
                           value="{{ old('email_marketing_internal_domains', $settings->email_marketing_internal_domains ?: 'samirgroup.com,sssegypt.com') }}"
                           placeholder="samirgroup.com, sssegypt.com">
                    <small class="text-muted">
                        Comma-separated. A campaign whose recipients are <strong>all</strong> on these domains
                        is internal and sends without approval. Any external recipient routes the campaign to
                        super_admin approval first.
                    </small>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="email_marketing_require_all_approval"
                               name="email_marketing_require_all_approval" value="1"
                               @checked(old('email_marketing_require_all_approval', $settings->email_marketing_require_all_approval))>
                        <label class="form-check-label" for="email_marketing_require_all_approval">
                            Require approval for <strong>all</strong> campaigns
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-header bg-light"><strong>Throttling & Tracking</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Manual rate limit (per second)</label>
                    <input type="number" name="ses_throttle_per_second" class="form-control" min="1" max="5000"
                           value="{{ old('ses_throttle_per_second', $settings->ses_throttle_per_second) }}">
                    <small class="text-muted">Leave blank to use SES account quota. Use to slow down during warm-up.</small>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="open_pixel" name="email_marketing_open_pixel_enabled" value="1"
                               @checked(old('email_marketing_open_pixel_enabled', $settings->email_marketing_open_pixel_enabled))>
                        <label class="form-check-label" for="open_pixel">Open tracking pixel</label>
                    </div>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="click_tracking" name="email_marketing_click_tracking_enabled" value="1"
                               @checked(old('email_marketing_click_tracking_enabled', $settings->email_marketing_click_tracking_enabled))>
                        <label class="form-check-label" for="click_tracking">Click tracking</label>
                    </div>
                </div>
            </div>
        </div>

        <div class="card-header bg-light"><strong>Retention</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Email events retention (days)</label>
                    <input type="number" name="email_marketing_event_retention_days" class="form-control" min="1" max="3650"
                           value="{{ old('email_marketing_event_retention_days', $settings->email_marketing_event_retention_days ?? 365) }}">
                </div>
            </div>
        </div>

        <div class="card-footer d-flex justify-content-end">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check2-circle me-1"></i>Save settings
            </button>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="card-header bg-light"><strong>Send test email</strong></div>
        <form class="card-body" method="POST" action="{{ route('admin.email-marketing.settings.test-send') }}">
            @csrf
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">Recipient</label>
                    <input type="email" name="to" class="form-control" placeholder="you@samirgroup.com" required>
                    <small class="text-muted">In SES sandbox you can only send to verified addresses.</small>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-outline-primary w-100">
                        <i class="bi bi-send me-1"></i>Send test
                    </button>
                </div>
            </div>
        </form>
    </div>
    @else
    <div class="alert alert-warning">
        You don't have permission to edit AWS SES credentials. Contact a Super Admin to change these settings.
    </div>
    @endcan
</div>
@endsection
