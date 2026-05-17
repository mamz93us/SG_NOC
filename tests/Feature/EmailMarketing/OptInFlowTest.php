<?php

use App\Models\EmailMarketing\EmailList;
use App\Models\EmailMarketing\EmailSubscriber;
use Illuminate\Support\Str;

it('promotes pending subscriber to subscribed when opt-in token confirms', function () {
    $list = EmailList::create(['name' => 'Promo', 'double_opt_in' => true]);
    $sub  = EmailSubscriber::create([
        'email'  => 'pending@example.com',
        'status' => 'pending',
    ]);
    $token = Str::random(40);
    \DB::table('email_list_subscriber')->insert([
        'email_list_id'       => $list->id,
        'email_subscriber_id' => $sub->id,
        'opt_in_token'        => $token,
        'opt_in_sent_at'      => now(),
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    $response = $this->get(route('email.opt-in.confirm', ['token' => $token]));
    $response->assertOk();

    $sub->refresh();
    expect($sub->status)->toBe('subscribed');
    expect($sub->confirmed_at)->not->toBeNull();

    // Token should be cleared after use
    $pivot = \DB::table('email_list_subscriber')
        ->where('email_subscriber_id', $sub->id)
        ->first();
    expect($pivot->opt_in_token)->toBeNull();
});

it('rejects an unknown opt-in token', function () {
    $response = $this->get(route('email.opt-in.confirm', ['token' => 'not-a-real-token']));
    $response->assertForbidden();
});
