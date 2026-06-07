<?php

use App\Services\EmailMarketing\CampaignApprovalService;
use App\Services\EmailMarketing\CampaignDispatcher;

/** Service with a mocked dispatcher — emailsAreInternal() is pure (no DB / AWS). */
function approvalService(): CampaignApprovalService
{
    return new CampaignApprovalService(Mockery::mock(CampaignDispatcher::class));
}

it('is internal-only only when every recipient domain is internal', function () {
    $svc = approvalService();
    $internal = ['samirgroup.com', 'sssegypt.com'];

    expect($svc->emailsAreInternal(['a@samirgroup.com', 'b@sssegypt.com'], $internal))->toBeTrue();
    expect($svc->emailsAreInternal(['a@samirgroup.com', 'b@gmail.com'], $internal))->toBeFalse();
    expect($svc->emailsAreInternal(['ADMIN@SamirGroup.com'], $internal))->toBeTrue(); // case-insensitive
});

it('is not internal-only with no recipients or no configured domains', function () {
    $svc = approvalService();

    expect($svc->emailsAreInternal([], ['samirgroup.com']))->toBeFalse();
    expect($svc->emailsAreInternal(['a@samirgroup.com'], []))->toBeFalse();
});
