<?php

use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveAiProposal;
use App\Models\Archive\ArchiveDocument;
use App\Models\Archive\ArchiveDocumentValue;
use App\Models\Archive\ArchiveField;
use App\Models\Archive\ArchiveFile;
use App\Models\Archive\ArchiveFileText;
use App\Models\Archive\ArchiveMember;
use App\Models\User;
use App\Services\Archive\Ai\ArchiveToolbox;
use App\Services\Archive\DocumentSearch;
use Tests\Unit\Archive\ArchiveTestSchema;

/**
 * The tools the assistant may use, and the one property that matters about
 * them: they cannot be talked into reaching a document their caller may not
 * open.
 *
 * The security model is the toolbox, not the prompt. So these tests do what a
 * prompt would try — ask for another archive by name, ask for a document by id,
 * ask while holding the permission but belonging to nothing — and check that
 * the answer is "not found" rather than the document.
 */
uses(Tests\TestCase::class);

/**
 * A user whose permissions are set directly.
 *
 * hasPermission() resolves through the role and permission tables, which this
 * schema deliberately does not build — the toolbox only ever ASKS the question,
 * so answering it here keeps these tests about the toolbox rather than about
 * RBAC, which has its own suite.
 *
 * A named class rather than an anonymous one because Eloquent constructs model
 * instances itself (HasEvents does `new static()`), so a required constructor
 * argument breaks the moment anything hydrates one.
 */
class TestArchiveUser extends User
{
    /** @var array<int,string> */
    public array $granted = [];

    protected $table = 'users';

    public function hasPermission(string $slug): bool
    {
        return in_array($slug, $this->granted, true);
    }

    public function isSuperAdmin(): bool
    {
        return false;
    }
}

beforeEach(function () {
    ArchiveTestSchema::create();

    $this->makeUser = function (string $email, array $permissions = []): User {
        $user = TestArchiveUser::create(['name' => $email, 'email' => $email, 'password' => 'x', 'role' => 'viewer']);
        $user->granted = $permissions;

        return $user;
    };

    $this->makeArchive = function (string $slug, string $fieldKey = 'invoiceno'): Archive {
        $archive = Archive::create(['slug' => $slug, 'name' => strtoupper($slug)]);

        ArchiveField::create([
            'archive_id' => $archive->id,
            'key' => $fieldKey,
            'label' => 'InvoiceNo',
            'type' => ArchiveField::TYPE_TEXT,
            'arcmate_column' => 'S1',
        ]);

        return $archive->fresh();
    };

    $this->makeDocument = function (Archive $archive, string $value): ArchiveDocument {
        $document = ArchiveDocument::create([
            'archive_id' => $archive->id,
            'status' => ArchiveDocument::STATUS_ACTIVE,
            'captured_at' => '2026-09-15 10:00:00',
        ]);

        ArchiveDocumentValue::create([
            'archive_document_id' => $document->id,
            'archive_field_id' => $archive->fields->first()->id,
            'value_text' => $value,
            'source' => ArchiveDocumentValue::SOURCE_ARCMATE,
        ]);

        return $document->fresh();
    };
});

afterEach(fn () => ArchiveTestSchema::drop());

// ─── Whether the tools are offered at all ────────────────────────

test('the tools are not offered without the AI permission', function () {
    $archive = ($this->makeArchive)('invoices');
    $user = ($this->makeUser)('no-ai@samirgroup.com', ['use-archive-portal']);
    ArchiveMember::create(['archive_id' => $archive->id, 'user_id' => $user->id, 'can_view' => true]);

    $toolbox = new ArchiveToolbox($user);

    expect($toolbox->available())->toBeFalse();
    expect($toolbox->definitions())->toBe([]);
});

test('the tools are not offered to somebody who belongs to no archive', function () {
    ($this->makeArchive)('invoices');

    // Holding the permission is not access to anything. Offering the tools here
    // would only produce confident answers about an empty set.
    $user = ($this->makeUser)('nobody@samirgroup.com', ['use-archive-portal', 'use-archive-ai']);

    expect((new ArchiveToolbox($user))->available())->toBeFalse();
});

test('a member with archive AI gets the four tools', function () {
    $archive = ($this->makeArchive)('invoices');
    $user = ($this->makeUser)('finance@samirgroup.com', ['use-archive-portal', 'use-archive-ai']);
    ArchiveMember::create(['archive_id' => $archive->id, 'user_id' => $user->id, 'can_view' => true]);

    $toolbox = new ArchiveToolbox($user);

    expect($toolbox->available())->toBeTrue();
    expect(array_column(array_column($toolbox->definitions(), 'function'), 'name'))
        ->toBe(ArchiveToolbox::TOOLS);
});

test('no tool takes a user id, so no prompt can redirect one', function () {
    $archive = ($this->makeArchive)('invoices');
    $user = ($this->makeUser)('finance@samirgroup.com', ['use-archive-portal', 'use-archive-ai']);
    ArchiveMember::create(['archive_id' => $archive->id, 'user_id' => $user->id, 'can_view' => true]);

    foreach ((new ArchiveToolbox($user))->definitions() as $definition) {
        $properties = (array) $definition['function']['parameters']['properties'];

        foreach (array_keys($properties) as $parameter) {
            expect($parameter)->not->toContain('user');
            expect($parameter)->not->toContain('email');
        }
    }
});

// ─── What a member can and cannot reach ──────────────────────────

test('only the archives somebody belongs to are listed', function () {
    $mine = ($this->makeArchive)('invoices');
    $theirs = ($this->makeArchive)('hr-files');

    $user = ($this->makeUser)('finance@samirgroup.com', ['use-archive-portal', 'use-archive-ai']);
    ArchiveMember::create(['archive_id' => $mine->id, 'user_id' => $user->id, 'can_view' => true]);

    $result = (new ArchiveToolbox($user))->call('list_archives', []);

    expect(array_column($result['archives'], 'slug'))->toBe(['invoices']);
});

test('searching an archive you do not belong to finds nothing', function () {
    $mine = ($this->makeArchive)('invoices');
    $theirs = ($this->makeArchive)('hr-files');
    ($this->makeDocument)($theirs, 'HR-SECRET-1');

    $user = ($this->makeUser)('finance@samirgroup.com', ['use-archive-portal', 'use-archive-ai']);
    ArchiveMember::create(['archive_id' => $mine->id, 'user_id' => $user->id, 'can_view' => true]);

    // Naming it directly is exactly what a prompt would try.
    $result = (new ArchiveToolbox($user))->call('search_documents', ['archive' => 'hr-files']);

    expect($result)->toHaveKey('error');
    expect($result)->not->toHaveKey('documents');
});

test('a document in another archive is not found rather than refused', function () {
    $mine = ($this->makeArchive)('invoices');
    $theirs = ($this->makeArchive)('hr-files');
    $secret = ($this->makeDocument)($theirs, 'HR-SECRET-1');

    $user = ($this->makeUser)('finance@samirgroup.com', ['use-archive-portal', 'use-archive-ai']);
    ArchiveMember::create(['archive_id' => $mine->id, 'user_id' => $user->id, 'can_view' => true]);

    $result = (new ArchiveToolbox($user))->call('get_document', ['id' => $secret->id]);

    // "Forbidden" would confirm the document exists, which is itself worth
    // something to somebody guessing at ids.
    expect($result['error'])->toContain('No document');
    expect(json_encode($result))->not->toContain('HR-SECRET-1');
});

test('a member finds their own documents, with a link', function () {
    $archive = ($this->makeArchive)('invoices');
    $document = ($this->makeDocument)($archive, 'INV-4471');

    $user = ($this->makeUser)('finance@samirgroup.com', ['use-archive-portal', 'use-archive-ai']);
    ArchiveMember::create(['archive_id' => $archive->id, 'user_id' => $user->id, 'can_view' => true]);

    $result = (new ArchiveToolbox($user))->call('search_documents', [
        'archive' => 'invoices',
        'filters' => ['invoiceno' => 'INV-44'],
    ]);

    expect($result['found'])->toBe(1);
    expect($result['documents'][0]['fields']['invoiceno'])->toBe('INV-4471');
    // A link nobody can click is worse than no link; assistant answers carry it.
    expect($result['documents'][0]['link'])->toContain('/documents/'.$document->id);
});

test('counting answers how many without listing them', function () {
    $archive = ($this->makeArchive)('invoices');
    ($this->makeDocument)($archive, 'INV-1');
    ($this->makeDocument)($archive, 'INV-2');

    $user = ($this->makeUser)('finance@samirgroup.com', ['use-archive-portal', 'use-archive-ai']);
    ArchiveMember::create(['archive_id' => $archive->id, 'user_id' => $user->id, 'can_view' => true]);

    $result = (new ArchiveToolbox($user))->call('count_documents', ['archive' => 'invoices']);

    expect($result['count'])->toBe(2);
    expect($result)->not->toHaveKey('documents');
});

test('a withdrawn membership takes effect on the next call', function () {
    $archive = ($this->makeArchive)('invoices');
    ($this->makeDocument)($archive, 'INV-4471');

    $user = ($this->makeUser)('finance@samirgroup.com', ['use-archive-portal', 'use-archive-ai']);
    $member = ArchiveMember::create(['archive_id' => $archive->id, 'user_id' => $user->id, 'can_view' => true]);

    $toolbox = new ArchiveToolbox($user);
    expect($toolbox->call('count_documents', ['archive' => 'invoices'])['count'])->toBe(1);

    // A conversation outlives a membership change, so access is re-checked per
    // call rather than trusted from when the tools were offered.
    $member->delete();

    expect($toolbox->call('count_documents', ['archive' => 'invoices']))->toHaveKey('error');
});

test('an unknown tool name is refused', function () {
    $archive = ($this->makeArchive)('invoices');
    $user = ($this->makeUser)('finance@samirgroup.com', ['use-archive-portal', 'use-archive-ai']);
    ArchiveMember::create(['archive_id' => $archive->id, 'user_id' => $user->id, 'can_view' => true]);

    expect((new ArchiveToolbox($user))->call('delete_everything', []))->toHaveKey('error');
});

// ─── What AI read off the paper ──────────────────────────────────

/**
 * A member of an archive holding archive AI, with one reading of the invoice
 * number on a document whose recorded value says something else.
 *
 * @return array{0: Archive, 1: ArchiveDocument, 2: ArchiveToolbox}
 */
function archiveWithReading(object $test, string $recorded, string $read, string $status = ArchiveAiProposal::STATUS_PENDING): array
{
    $archive = ($test->makeArchive)('invoices');
    $document = ($test->makeDocument)($archive, $recorded);

    ArchiveAiProposal::create([
        'archive_document_id' => $document->id,
        'archive_field_id' => $archive->fields->first()->id,
        'value' => $read,
        'confidence' => 88,
        'evidence_page' => 2,
        'status' => $status,
    ]);

    $user = ($test->makeUser)('finance@samirgroup.com', ['use-archive-portal', 'use-archive-ai']);
    ArchiveMember::create(['archive_id' => $archive->id, 'user_id' => $user->id, 'can_view' => true]);

    return [$archive, $document, new ArchiveToolbox($user)];
}

test('a filter finds a document by what AI read off it, labelled as unreviewed', function () {
    [, $document, $toolbox] = archiveWithReading($this, 'OLD-1', 'INV-9001');

    $result = $toolbox->call('search_documents', ['archive' => 'invoices', 'filters' => ['invoiceno' => 'INV-90']]);

    expect($result['found'])->toBe(1);
    expect($result['documents'][0]['id'])->toBe($document->id);
    // The recorded value stays the recorded value; the reading rides beside it.
    expect($result['documents'][0]['fields']['invoiceno'])->toBe('OLD-1');
    expect($result['documents'][0]['ai_readings'][0])->toMatchArray([
        'field' => 'invoiceno', 'value' => 'INV-9001', 'page' => 2, 'reviewed' => false,
    ]);

    expect($toolbox->call('count_documents', ['archive' => 'invoices', 'filters' => ['invoiceno' => 'INV-90']])['count'])->toBe(1);
});

test('the recorded value still matches when a reading says something else', function () {
    [, , $toolbox] = archiveWithReading($this, 'OLD-1', 'INV-9001');

    expect($toolbox->call('search_documents', ['archive' => 'invoices', 'filters' => ['invoiceno' => 'OLD']])['found'])->toBe(1);
});

test('a rejected reading neither matches nor is shown', function () {
    [, , $toolbox] = archiveWithReading($this, 'OLD-1', 'INV-9001', ArchiveAiProposal::STATUS_REJECTED);

    expect($toolbox->call('search_documents', ['archive' => 'invoices', 'filters' => ['invoiceno' => 'INV-90']])['found'])->toBe(0);

    $all = $toolbox->call('search_documents', ['archive' => 'invoices']);
    expect($all['documents'][0])->not->toHaveKey('ai_readings');
});

test('a reading in an archive you do not belong to is never reached', function () {
    [, , $toolbox] = archiveWithReading($this, 'OLD-1', 'INV-9001');

    $theirs = ($this->makeArchive)('hr-files');
    $secret = ($this->makeDocument)($theirs, 'HR-1');
    ArchiveAiProposal::create([
        'archive_document_id' => $secret->id,
        'archive_field_id' => $theirs->fields->first()->id,
        'value' => 'SALARY-SECRET',
        'status' => ArchiveAiProposal::STATUS_PENDING,
    ]);

    $result = $toolbox->call('search_documents', ['archive' => 'invoices', 'filters' => ['invoiceno' => 'SALARY']]);

    expect($result['found'])->toBe(0);
    expect(json_encode($toolbox->call('get_document', ['id' => $secret->id])))->not->toContain('SALARY-SECRET');
});

test('a word search shows the page and the words around the match', function () {
    [$archive, $document, $toolbox] = archiveWithReading($this, 'INV-1', 'INV-1');

    $file = ArchiveFile::create([
        'archive_id' => $archive->id, 'archive_document_id' => $document->id,
        'position' => 1, 'original_name' => 'invoice.pdf', 'disk' => 'local', 'path' => 'x/invoice.pdf',
    ]);
    ArchiveFileText::create(['archive_file_id' => $file->id, 'page' => 1, 'text' => 'Cover sheet only.']);
    ArchiveFileText::create(['archive_file_id' => $file->id, 'page' => 3, 'text' => 'Delivered by Almarai Company to the Jeddah warehouse on 3 March.']);

    $result = $toolbox->call('search_documents', ['archive' => 'invoices', 'words' => 'Almarai']);

    expect($result['found'])->toBe(1);
    expect($result['documents'][0]['matching_pages'])->toHaveCount(1);
    expect($result['documents'][0]['matching_pages'][0])->toMatchArray(['file' => 'invoice.pdf', 'page' => 3]);
    expect($result['documents'][0]['matching_pages'][0]['snippet'])->toContain('Almarai Company');
});

test('a document lists its unreviewed readings and the fields AI could not find', function () {
    [$archive, $document, $toolbox] = archiveWithReading($this, 'OLD-1', 'INV-9001');

    $amount = ArchiveField::create([
        'archive_id' => $archive->id, 'key' => 'amount', 'label' => 'Amount', 'type' => ArchiveField::TYPE_NUMBER,
    ]);
    ArchiveAiProposal::create([
        'archive_document_id' => $document->id, 'archive_field_id' => $amount->id,
        'status' => ArchiveAiProposal::STATUS_NOT_FOUND,
    ]);

    $result = $toolbox->call('get_document', ['id' => $document->id]);

    expect($result['ai_readings'][0]['value'])->toBe('INV-9001');
    expect($result['ai_did_not_find'])->toBe(['Amount']);
});

test('a snippet is cut around the first word it holds', function () {
    $text = str_repeat('filler ', 100).'the Almarai delivery note '.str_repeat('tail ', 100);

    $snippet = DocumentSearch::snippet($text, 'almarai');

    expect($snippet)->toContain('Almarai delivery note');
    expect($snippet)->toStartWith('…');
    expect(mb_strlen($snippet))->toBeLessThanOrEqual(302);
});
