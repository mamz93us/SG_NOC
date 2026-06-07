<?php

use App\Observers\EmailMarketingActivityObserver;

afterEach(fn () => EmailMarketingActivityObserver::$silent = false);

it('silently() suppresses audit logging inside the callback and restores the flag', function () {
    expect(EmailMarketingActivityObserver::$silent)->toBeFalse();

    $insideFlag = null;
    $returned = EmailMarketingActivityObserver::silently(function () use (&$insideFlag) {
        $insideFlag = EmailMarketingActivityObserver::$silent;

        return 'ok';
    });

    expect($insideFlag)->toBeTrue();   // suppressed inside the import
    expect($returned)->toBe('ok');     // returns the callback result
    expect(EmailMarketingActivityObserver::$silent)->toBeFalse(); // restored after
});

it('restores the silent flag even when the callback throws', function () {
    try {
        EmailMarketingActivityObserver::silently(function () {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(EmailMarketingActivityObserver::$silent)->toBeFalse();
});
