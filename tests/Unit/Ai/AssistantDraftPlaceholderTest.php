<?php

use App\Models\User;
use App\Services\Ai\AssistantToolbox;
use App\Services\Ai\DraftPlaceholders;
use App\Services\Ai\KnowledgeRetriever;
use App\Services\Ticketing\TicketRequestService;

/**
 * Drafts must not leave a placeholder for someone to fill in. The chat's
 * draft cards have Send and no Edit, and 7 of the first 9 emails the
 * assistant drafted on NOC2 ended "[Your Name]" or "[اسمك]".
 */
uses(Tests\TestCase::class);

function placeholderToolbox(): AssistantToolbox
{
    return new AssistantToolbox(
        new User(['name' => 'Mona Adel', 'email' => 'mona.adel@samirgroup.com']),
        null,
        null,
        app(KnowledgeRetriever::class),
        app(TicketRequestService::class),
    );
}

it('finds the placeholders a model leaves in a draft', function (string $text, array $expected) {
    expect(DraftPlaceholders::find($text))->toBe($expected);
})->with([
    'your name' => ["Best regards,\n[Your Name]", ['[Your Name]']],
    'arabic' => ["مع خالص التحية،\n[اسمك]", ['[اسمك]']],
    'recipient and date' => ['Dear [Recipient Name], the meeting is on [Insert Date].', ['[Recipient Name]', '[Insert Date]']],
    'template slot' => ['Hi {{first_name}},', ['{{first_name}}']],
    'angle brackets' => ['Regards, <Your Name>', ['<Your Name>']],
    'parentheses' => ['Thanks, (Your Name)', ['(Your Name)']],
]);

it('leaves ordinary brackets alone', function (string $text) {
    expect(DraftPlaceholders::find($text))->toBe([]);
})->with([
    'subject tag' => ['[External] Budget for Q4'],
    'ticket reference' => ['Following up on [Ticket #4521].'],
    'address in angle brackets' => ['Mona Adel <mona.adel@samirgroup.com>'],
    'an aside' => ['The report is attached (see page 2).'],
    'a real date in brackets' => ['We meet on [Thursday, September 10, 2026] at 10:00.'],
]);

it('refuses an email draft that still has a placeholder, and says whose name to sign with', function () {
    $result = placeholderToolbox()->call('draft_email', [
        'to' => ['ahmed.samy@samirgroup.com'],
        'subject' => 'Leave request',
        'body' => "Dear Ahmed,\n\nI would like to take Sunday off.\n\nBest regards,\n[Your Name]",
    ]);

    expect($result)->not->toHaveKey('draft')
        ->and($result['error'])->toContain('[Your Name]')->toContain('Mona Adel');
});

it('drafts the email once it is filled in', function () {
    $result = placeholderToolbox()->call('draft_email', [
        'to' => ['ahmed.samy@samirgroup.com'],
        'subject' => 'Leave request',
        'body' => "Dear Ahmed,\n\nI would like to take Sunday off.\n\nBest regards,\nMona Adel",
    ]);

    expect($result['draft'])->toBeTrue()
        ->and($result['body'])->toEndWith('Mona Adel');
});

it('refuses meeting invitations and tickets with placeholders too', function () {
    $toolbox = placeholderToolbox();

    expect($toolbox->call('draft_calendar_event', [
        'subject' => 'Sync with [Manager Name]',
        'start' => '2026-09-15T10:00:00',
        'end' => '2026-09-15T10:30:00',
    ]))->toHaveKey('error')
        ->and($toolbox->call('draft_ticket', [
            'title' => 'Printer offline',
            'description' => 'Please call me on [Your Extension].',
            'category_id' => 1,
            'subcategory_id' => 1,
            'reason_not_solved' => 'Restarted it already.',
        ]))->toHaveKey('error');
});

it('tells the model who it is talking to', function () {
    expect(placeholderToolbox()->identityNote())
        ->toContain('Mona Adel')
        ->toContain('signed with that name');
});
