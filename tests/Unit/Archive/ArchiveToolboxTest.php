<?php

use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveDocument;
use App\Models\Archive\ArchiveDocumentValue;
use App\Models\Archive\ArchiveField;
use App\Models\Archive\ArchiveMember;
use App\Models\User;
use App\Services\Archive\Ai\ArchiveToolbox;
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
