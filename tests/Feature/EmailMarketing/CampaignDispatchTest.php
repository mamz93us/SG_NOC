<?php

use App\Models\EmailMarketing\EmailCampaign;
use App\Models\EmailMarketing\EmailCampaignSend;
use App\Models\EmailMarketing\EmailList;
use App\Models\EmailMarketing\EmailSubscriber;
use App\Models\EmailMarketing\EmailTemplate;
use App\Models\Setting;
use App\Services\EmailMarketing\CampaignDispatcher;
use App\Services\EmailMarketing\MergeTagRenderer;
use App\Services\EmailMarketing\SesService;
use App\Services\EmailMarketing\SuppressionManager;

beforeEach(function () {
    // Enable email marketing so SesService doesn't bail out
    Setting::get()->update([
        'email_marketing_enabled' => true,
        'ses_region'              => 'us-east-1',
        'ses_access_key_id'       => 'AKIAFAKE',
        'ses_secret_access_key'   => 'SECRETFAKE',
    ]);
});

it('populates campaign sends and renders merge tags via dispatcher', function () {
    $list = EmailList::create(['name' => 'Promo']);
    foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $i => $email) {
        $sub = EmailSubscriber::create([
            'email' => $email, 'first_name' => "User{$i}", 'status' => 'subscribed',
        ]);
        $list->subscribers()->attach($sub->id, ['subscribed_at' => now()]);
    }
    $tpl  = EmailTemplate::create([
        'name' => 'Hello',
        'rendered_html' => 'Hello {{first_name}}',
    ]);
    $camp = EmailCampaign::create([
        'name' => 'Welcome', 'subject' => 'Hi', 'from_email' => 'a@b.com', 'from_name' => 'A',
        'email_template_id' => $tpl->id, 'email_list_id' => $list->id,
        'status' => 'scheduled', 'scheduled_at' => now()->subMinute(),
    ]);

    // Mock SesService so no AWS call goes out.
    $sesMock = Mockery::mock(SesService::class);
    $sesMock->shouldReceive('getSendQuota')->andReturn(['MaxSendRate' => 10.0, 'Max24HourSend' => 50000, 'SentLast24Hours' => 0]);
    $sesMock->shouldReceive('sendCampaignEmail')->times(3)->andReturnUsing(function ($send, $html, $subject) {
        // Verify the renderer subbed in the subscriber's first name
        expect($html)->toContain('Hello User');
        return 'mock-msg-' . $send->id;
    });
    $this->app->instance(SesService::class, $sesMock);

    $dispatcher = new CampaignDispatcher($sesMock, new SuppressionManager());
    $dispatcher->tick($camp->fresh(), 100);

    $camp->refresh();
    expect($camp->status)->toBe('sent');
    expect(EmailCampaignSend::where('email_campaign_id', $camp->id)->where('status', 'sent')->count())
        ->toBe(3);
});
