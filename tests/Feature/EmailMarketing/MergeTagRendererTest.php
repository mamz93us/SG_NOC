<?php

use App\Models\EmailMarketing\EmailList;
use App\Models\EmailMarketing\EmailSubscriber;
use App\Services\EmailMarketing\MergeTagRenderer;

it('substitutes basic merge tags', function () {
    $sub = EmailSubscriber::create([
        'email'      => 'jane@example.com',
        'first_name' => 'Jane',
        'last_name'  => 'Doe',
        'status'     => 'subscribed',
    ]);
    $renderer = new MergeTagRenderer();
    $rendered = $renderer->render('Hello {{first_name}} {{last_name}} <{{email}}>', $sub);
    expect($rendered)->toBe('Hello Jane Doe <jane@example.com>');
});

it('leaves unknown merge tags as literals', function () {
    $sub = EmailSubscriber::create(['email' => 'x@example.com', 'status' => 'subscribed']);
    $renderer = new MergeTagRenderer();
    expect($renderer->render('{{first_name}} {{nonexistent}}', $sub))
        ->toBe(' {{nonexistent}}'); // first_name is empty string, unknown left intact
});

it('resolves custom attributes via {{attributes.key}}', function () {
    $sub = EmailSubscriber::create([
        'email'      => 'a@example.com',
        'status'     => 'subscribed',
        'attributes' => ['country' => 'SA', 'plan' => 'pro'],
    ]);
    $renderer = new MergeTagRenderer();
    expect($renderer->render('You are in {{country}} on the {{attributes.plan}} plan.', $sub))
        ->toBe('You are in SA on the pro plan.');
});

it('builds an unsubscribe URL containing a signed token', function () {
    $sub = EmailSubscriber::create(['email' => 'u@example.com', 'status' => 'subscribed']);
    $list = EmailList::create(['name' => 'Promo']);
    $renderer = new MergeTagRenderer();
    $url = $renderer->unsubscribeUrl($sub, $list);
    expect($url)->toContain('/email/unsubscribe/');
    expect($url)->toContain('signature=');
});

it('decodes token back to subscriber and list ids', function () {
    $sub = EmailSubscriber::create(['email' => 'u@example.com', 'status' => 'subscribed']);
    $list = EmailList::create(['name' => 'Promo']);
    $renderer = new MergeTagRenderer();
    $url = $renderer->unsubscribeUrl($sub, $list);
    preg_match('#/email/unsubscribe/([^?]+)#', $url, $m);
    [$decodedSub, $decodedList] = MergeTagRenderer::decodeToken($m[1]);
    expect($decodedSub)->toBe($sub->id);
    expect($decodedList)->toBe($list->id);
});
