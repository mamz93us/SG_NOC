<?php

use App\Jobs\EmailMarketing\ProcessSnsEventJob;
use App\Models\EmailMarketing\EmailCampaign;
use App\Models\EmailMarketing\EmailCampaignSend;
use App\Models\EmailMarketing\EmailList;
use App\Models\EmailMarketing\EmailSubscriber;
use App\Models\EmailMarketing\EmailSuppression;
use App\Models\EmailMarketing\EmailTemplate;
use App\Services\EmailMarketing\SuppressionManager;

it('marks subscriber bounced and suppresses on permanent SES bounce', function () {
    $list = EmailList::create(['name' => 'Promo']);
    $sub  = EmailSubscriber::create([
        'email'  => 'badaddress@example.com',
        'status' => 'subscribed',
    ]);
    $tpl  = EmailTemplate::create(['name' => 'T', 'rendered_html' => '<p>x</p>']);
    $camp = EmailCampaign::create([
        'name' => 'C', 'subject' => 'S', 'from_email' => 'a@b.com', 'from_name' => 'A',
        'email_template_id' => $tpl->id, 'email_list_id' => $list->id, 'status' => 'sending',
    ]);
    $send = EmailCampaignSend::create([
        'email_campaign_id' => $camp->id,
        'email_subscriber_id' => $sub->id,
        'ses_message_id' => 'msg-1',
        'status' => 'sent',
    ]);

    $payload = [
        'eventType' => 'Bounce',
        'mail' => [
            'messageId'   => 'msg-1',
            'destination' => ['badaddress@example.com'],
        ],
        'bounce' => [
            'bounceType'    => 'Permanent',
            'bounceSubType' => 'General',
        ],
    ];

    (new ProcessSnsEventJob($payload))->handle(app(SuppressionManager::class));

    $sub->refresh();
    $send->refresh();
    $camp->refresh();
    expect($sub->status)->toBe('bounced');
    expect($send->status)->toBe('bounced');
    expect($camp->total_bounces)->toBe(1);
    expect(EmailSuppression::where('email', 'badaddress@example.com')->where('reason', 'hard_bounce')->exists())
        ->toBeTrue();
});

it('does not suppress on transient bounce but still marks the send', function () {
    $list = EmailList::create(['name' => 'Promo']);
    $sub  = EmailSubscriber::create(['email' => 'tmp@example.com', 'status' => 'subscribed']);
    $tpl  = EmailTemplate::create(['name' => 'T', 'rendered_html' => '<p>x</p>']);
    $camp = EmailCampaign::create([
        'name' => 'C', 'subject' => 'S', 'from_email' => 'a@b.com', 'from_name' => 'A',
        'email_template_id' => $tpl->id, 'email_list_id' => $list->id, 'status' => 'sending',
    ]);
    $send = EmailCampaignSend::create([
        'email_campaign_id' => $camp->id, 'email_subscriber_id' => $sub->id,
        'ses_message_id' => 'msg-2', 'status' => 'sent',
    ]);

    $payload = [
        'eventType' => 'Bounce',
        'mail' => ['messageId' => 'msg-2', 'destination' => ['tmp@example.com']],
        'bounce' => ['bounceType' => 'Transient', 'bounceSubType' => 'MailboxFull'],
    ];

    (new ProcessSnsEventJob($payload))->handle(app(SuppressionManager::class));

    $sub->refresh();
    expect($sub->status)->toBe('subscribed'); // not bounced for transient
    expect(EmailSuppression::where('email', 'tmp@example.com')->exists())->toBeFalse();
});
