<?php

use App\Models\EmailMarketing\EmailCampaign;
use App\Models\EmailMarketing\EmailSubscriber;
use App\Models\EmailMarketing\EmailTemplate;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\Training\Course;
use App\Models\Training\CourseCertificate;
use App\Services\EmailMarketing\CampaignDispatcher;
use App\Services\EmailMarketing\MergeTagRenderer;
use App\Services\EmailMarketing\SesService;
use App\Services\EmailMarketing\SuppressionManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(CourseCertificate::DISK);

    Setting::get()->update([
        'email_marketing_enabled' => true,
        'ses_region'              => 'us-east-1',
        'ses_access_key_id'       => 'AKIAFAKE',
        'ses_secret_access_key'   => 'SECRETFAKE',
    ]);

    $this->course = Course::create(['name' => 'Cyber Awareness 2026']);
    $this->emp = Employee::create(['name' => 'Ahmed', 'email' => 'ahmed@samirgroup.com', 'status' => 'active']);
    $this->cert = CourseCertificate::create([
        'course_id'   => $this->course->id,
        'employee_id' => $this->emp->id,
        'email'       => 'ahmed@samirgroup.com',
        'file_path'   => "{$this->course->id}/abc.pdf",
        'file_mime'   => 'application/pdf',
        'token'       => CourseCertificate::generateToken(),
    ]);
});

it('merge tag {{certificate_url}} resolves to the recipient cert link', function () {
    $sub = EmailSubscriber::create(['email' => 'ahmed@samirgroup.com', 'status' => 'subscribed']);
    $campaign = EmailCampaign::create([
        'name' => 'C', 'subject' => 'S', 'from_email' => 'a@b.com', 'from_name' => 'A',
        'email_template_id' => EmailTemplate::create(['name' => 'T', 'rendered_html' => 'x'])->id,
        'course_id' => $this->course->id, 'status' => 'draft',
    ]);

    $renderer = new MergeTagRenderer;
    $html = $renderer->render('Open: {{certificate_url}}', $sub, null, null, $campaign);

    expect($html)->toContain($this->cert->token);
    expect($html)->toContain('/certificates/');
});

it('{{certificate_url}} is empty for non-course campaigns and unknown recipients', function () {
    $renderer = new MergeTagRenderer;

    // Non-course campaign
    $sub = EmailSubscriber::create(['email' => 'ahmed@samirgroup.com', 'status' => 'subscribed']);
    expect($renderer->render('X {{certificate_url}} Y', $sub, null, null, null))
        ->toBe('X  Y');

    // Course campaign but recipient has no certificate
    $other = EmailSubscriber::create(['email' => 'noone@example.com', 'status' => 'subscribed']);
    $campaign = EmailCampaign::create([
        'name' => 'C', 'subject' => 'S', 'from_email' => 'a@b.com', 'from_name' => 'A',
        'email_template_id' => EmailTemplate::create(['name' => 'T', 'rendered_html' => 'x'])->id,
        'course_id' => $this->course->id, 'status' => 'draft',
    ]);
    expect($renderer->render('X {{certificate_url}} Y', $other, null, null, $campaign))->toBe('X  Y');
});

it('campaign dispatcher resolves recipients from the course certificates', function () {
    // Create a second employee + cert so we know it's not just one
    $emp2 = Employee::create(['name' => 'Sara', 'email' => 'sara@samirgroup.com', 'status' => 'active']);
    CourseCertificate::create([
        'course_id' => $this->course->id, 'employee_id' => $emp2->id, 'email' => 'sara@samirgroup.com',
        'file_path' => "{$this->course->id}/sara.pdf", 'file_mime' => 'application/pdf',
        'token' => CourseCertificate::generateToken(),
    ]);

    // Orphan — should be skipped
    CourseCertificate::create([
        'course_id' => $this->course->id, 'employee_id' => null, 'email' => 'ghost@samirgroup.com',
        'file_path' => "{$this->course->id}/ghost.pdf", 'file_mime' => 'application/pdf',
        'token' => CourseCertificate::generateToken(),
    ]);

    $tpl = EmailTemplate::create(['name' => 'T', 'rendered_html' => 'hi']);
    $campaign = EmailCampaign::create([
        'name' => 'C', 'subject' => 'S', 'from_email' => 'a@b.com', 'from_name' => 'A',
        'email_template_id' => $tpl->id, 'course_id' => $this->course->id,
        'status' => 'scheduled', 'scheduled_at' => now()->subMinute(),
    ]);

    $sesMock = Mockery::mock(SesService::class);
    $sesMock->shouldReceive('getSendQuota')->andReturn(['MaxSendRate' => 10.0, 'Max24HourSend' => 50000, 'SentLast24Hours' => 0]);
    $sesMock->shouldReceive('sendCampaignEmail')->twice()->andReturn('msg-id');
    $this->app->instance(SesService::class, $sesMock);

    (new CampaignDispatcher($sesMock, new SuppressionManager))->tick($campaign->fresh(), 100);

    // Only the two linked employees become recipients
    $sendEmails = $campaign->fresh()->sends()->with('subscriber')->get()->pluck('subscriber.email')->sort()->values();
    expect($sendEmails->all())->toBe(['ahmed@samirgroup.com', 'sara@samirgroup.com']);

    // sent_at gets stamped on the certificates
    expect($this->cert->fresh()->sent_at)->not->toBeNull();
});

it('public certificate route streams the file and tracks views', function () {
    // Put a real fake file at the cert path so the stream endpoint can serve it.
    Storage::disk(CourseCertificate::DISK)->put($this->cert->file_path, 'pdf-bytes');

    expect($this->cert->view_count)->toBe(0);
    expect($this->cert->viewed_at)->toBeNull();

    $resp = $this->get(route('certificates.show', ['token' => $this->cert->token]));
    $resp->assertOk();
    $resp->assertSee('Cyber Awareness 2026');

    $this->cert->refresh();
    expect($this->cert->view_count)->toBe(1);
    expect($this->cert->viewed_at)->not->toBeNull();

    // Second hit increments only the counter, leaves viewed_at as first view.
    $firstViewedAt = $this->cert->viewed_at;
    $this->get(route('certificates.show', ['token' => $this->cert->token]))->assertOk();
    $this->cert->refresh();
    expect($this->cert->view_count)->toBe(2);
    expect($this->cert->viewed_at->equalTo($firstViewedAt))->toBeTrue();
});

it('returns 404 for an unknown certificate token', function () {
    // 64-char that doesn't exist
    $this->get(route('certificates.show', ['token' => str_repeat('0', 64)]))->assertNotFound();
});
