<?php

use App\Models\EmailMarketing\EmailList;
use App\Models\EmailMarketing\EmailSubscriber;
use App\Models\EmailMarketing\EmailSuppression;
use App\Services\EmailMarketing\MergeTagRenderer;

it('unsubscribes a subscriber via a signed URL', function () {
    $list = EmailList::create(['name' => 'Promo']);
    $sub  = EmailSubscriber::create([
        'email'  => 'leave@example.com',
        'status' => 'subscribed',
    ]);
    $list->subscribers()->attach($sub->id, ['subscribed_at' => now()]);

    $renderer = new MergeTagRenderer();
    $url = $renderer->unsubscribeUrl($sub, $list);

    // POST to the same signed URL (RFC 8058 one-click style)
    $response = $this->post($url);
    $response->assertOk();

    $sub->refresh();
    expect($sub->status)->toBe('unsubscribed');
    expect(EmailSuppression::where('email', 'leave@example.com')->exists())->toBeTrue();

    $pivot = \DB::table('email_list_subscriber')
        ->where('email_subscriber_id', $sub->id)->first();
    expect($pivot->unsubscribed_at)->not->toBeNull();
});

it('refuses an unsigned unsubscribe request', function () {
    $sub  = EmailSubscriber::create(['email' => 'guard@example.com', 'status' => 'subscribed']);
    $response = $this->post(route('email.unsubscribe.confirm', ['token' => 'random-token']));
    $response->assertForbidden();
    $sub->refresh();
    expect($sub->status)->toBe('subscribed');
});
