<?php

use App\Models\EmailMarketing\EmailEvent;

it('rejects an SNS notification with an invalid signature', function () {
    // A real SNS message with a clearly wrong signature/cert URL
    $payload = [
        'Type'             => 'Notification',
        'MessageId'        => 'abc',
        'TopicArn'         => 'arn:aws:sns:us-east-1:000000000000:fake',
        'Subject'          => 'test',
        'Message'          => json_encode(['eventType' => 'Delivery', 'mail' => ['messageId' => 'whatever']]),
        'Timestamp'        => now()->toIso8601String(),
        'SignatureVersion' => '1',
        'Signature'        => base64_encode('not-a-real-signature'),
        'SigningCertURL'   => 'https://evil.com/cert.pem',
    ];

    $response = $this->postJson('/api/sns/email-events', $payload);
    $response->assertStatus(401);
    expect(EmailEvent::count())->toBe(0);
});

it('rejects a payload that is not valid JSON', function () {
    $response = $this->call('POST', '/api/sns/email-events', [], [], [], [], 'not-json');
    $response->assertStatus(400);
});
