<?php

use App\Models\EmailMarketing\EmailCampaign;
use App\Models\EmailMarketing\EmailCampaignSend;
use App\Models\EmailMarketing\EmailList;
use App\Models\EmailMarketing\EmailSubscriber;
use App\Models\EmailMarketing\EmailSuppression;
use App\Models\EmailMarketing\EmailTemplate;
use App\Models\Setting;
use App\Services\EmailMarketing\CampaignDispatcher;
use App\Services\EmailMarketing\SesService;
use App\Services\EmailMarketing\SuppressionManager;

beforeEach(function () {
    Setting::get()->update([
        'email_marketing_enabled' => true,
        'ses_region'              => 'us-east-1',
        'ses_access_key_id'       => 'AKIAFAKE',
        'ses_secret_access_key'   => 'SECRETFAKE',
    ]);
});

it('skips subscribers who are on the global suppression list', function () {
    $list = EmailList::create(['name' => 'Promo']);
    $good = EmailSubscriber::create(['email' => 'good@example.com', 'status' => 'subscribed', 'first_name' => 'OK']);
    $bad  = EmailSubscriber::create(['email' => 'blocked@example.com', 'status' => 'subscribed', 'first_name' => 'NO']);
    $list->subscribers()->attach($good->id, ['subscribed_at' => now()]);
    $list->subscribers()->attach($bad->id, ['subscribed_at' => now()]);

    EmailSuppression::create(['email' => 'blocked@example.com', 'reason' => 'manual']);

    $tpl  = EmailTemplate::create(['name' => 'T', 'rendered_html' => 'Hello {{first_name}}']);
    $camp = EmailCampaign::create([
        'name' => 'C', 'subject' => 'S', 'from_email' => 'a@b.com', 'from_name' => 'A',
        'email_template_id' => $tpl->id, 'email_list_id' => $list->id,
        'status' => 'scheduled', 'scheduled_at' => now()->subMinute(),
    ]);

    $sesMock = Mockery::mock(SesService::class);
    $sesMock->shouldReceive('getSendQuota')->andReturn(['MaxSendRate' => 10.0, 'Max24HourSend' => 50000, 'SentLast24Hours' => 0]);
    // Should only be called once (for good@example.com)
    $sesMock->shouldReceive('sendCampaignEmail')->once()->andReturn('mock-msg-1');
    $this->app->instance(SesService::class, $sesMock);

    $dispatcher = new CampaignDispatcher($sesMock, new SuppressionManager());
    $dispatcher->tick($camp->fresh(), 100);

    expect(EmailCampaignSend::where('email_campaign_id', $camp->id)->where('status', 'suppressed')->count())->toBe(1);
    expect(EmailCampaignSend::where('email_campaign_id', $camp->id)->where('status', 'sent')->count())->toBe(1);
});
