<?php

use App\Models\Announcement;
use App\Models\OraclePortal\PortalSetting;
use App\Services\OraclePortal\AnnouncementSync;
use App\Services\OraclePortal\PortalApiClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Oracle's announcements into the NOC's own table.
 *
 * What is defended: a notice typed here is never touched, an edit made here
 * survives the next pull, Oracle's own changes still land on the fields nobody
 * has taken over, a notice that leaves the feed is withdrawn rather than
 * deleted, and a truncated response changes nothing at all.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);

    foreach (['announcements', 'activity_logs', 'branches', 'departments', 'oracle_portal_settings'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('branches', function (Blueprint $t) {
        $t->unsignedInteger('id')->primary();
        $t->string('name');
        $t->timestamps();
    });

    Schema::create('departments', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });

    Schema::create('activity_logs', function (Blueprint $t) {
        $t->id();
        $t->string('model_type')->nullable();
        $t->unsignedBigInteger('model_id')->nullable();
        $t->string('model_label')->nullable();
        $t->string('action')->nullable();
        $t->json('changes')->nullable();
        $t->string('actor_label')->nullable();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('ip_address')->nullable();
        $t->string('user_agent')->nullable();
        $t->timestamps();
    });

    Schema::create('oracle_portal_settings', function (Blueprint $t) {
        $t->id();
        $t->boolean('enabled')->default(false);
        $t->text('base_url')->nullable();
        $t->text('api_key')->nullable();
        $t->boolean('sync_announcements')->default(false);
        $t->boolean('sync_employees')->default(false);
        $t->boolean('sync_vacations')->default(false);
        $t->timestamp('last_announcements_sync_at')->nullable();
        $t->timestamp('last_employees_sync_at')->nullable();
        $t->timestamp('last_vacations_sync_at')->nullable();
        $t->unsignedInteger('last_announcements_count')->nullable();
        $t->unsignedInteger('last_employees_count')->nullable();
        $t->unsignedInteger('last_vacation_balances_count')->nullable();
        $t->unsignedInteger('last_vacation_records_count')->nullable();
        $t->timestamps();
    });

    Schema::create('announcements', function (Blueprint $t) {
        $t->id();
        $t->string('title', 200);
        $t->string('title_ar', 200)->nullable();
        $t->text('body');
        $t->text('body_ar')->nullable();
        $t->string('link_url', 500)->nullable();
        $t->string('link_label', 80)->nullable();
        $t->string('severity', 20)->default('info');
        $t->boolean('pinned')->default(false);
        $t->boolean('is_published')->default(false);
        $t->timestamp('published_at')->nullable();
        $t->timestamp('expires_at')->nullable();
        $t->string('audience', 20)->default('all');
        $t->unsignedInteger('audience_branch_id')->nullable();
        $t->unsignedBigInteger('audience_department_id')->nullable();
        $t->unsignedBigInteger('created_by')->nullable();
        $t->string('created_by_name', 255)->nullable();
        $t->string('source', 20)->default('manual');
        $t->string('external_id', 40)->nullable();
        $t->string('external_image_name', 255)->nullable();
        $t->timestamp('synced_at')->nullable();
        $t->json('synced_fields')->nullable();
        $t->timestamp('removed_at')->nullable();
        $t->timestamps();
    });

    PortalSetting::create([
        'enabled' => true,
        'base_url' => 'https://example.invalid/api',
        'api_key' => 'test-key',
        'sync_announcements' => true,
    ]);
});

/** A client that answers with whatever rows the test hands it. */
function portalApiReturning(array $rows): PortalApiClient
{
    return new class($rows) extends PortalApiClient
    {
        public function __construct(private array $rows) {}

        public function announcements(bool $activeOnly = false, ?PortalSetting $settings = null): array
        {
            return $this->rows;
        }
    };
}

function announcementSyncWith(array $rows): AnnouncementSync
{
    return new AnnouncementSync(portalApiReturning($rows));
}

function oracleRow(string $id, string $subject, array $overrides = []): array
{
    return array_merge([
        'announcementId' => $id,
        'subject' => $subject,
        'imageName' => 'notice.jpg',
        'startDate' => '2026-09-17',
        'expireDate' => '2099-12-31',
        'isExpired' => 'N',
    ], $overrides);
}

it('creates Oracle announcements live, with no body and the image name kept', function () {
    $counts = announcementSyncWith([oracleRow('300000438097080', 'National Day holiday')])->sync();

    expect($counts['created'])->toBe(1);

    $ann = Announcement::sole();

    expect($ann->source)->toBe(Announcement::SOURCE_ORACLE)
        ->and($ann->external_id)->toBe('300000438097080')
        ->and($ann->title)->toBe('National Day holiday')
        ->and($ann->body)->toBe('')
        ->and($ann->is_published)->toBeTrue()
        ->and($ann->severity)->toBe('info')
        ->and($ann->audience)->toBe('all')
        ->and($ann->external_image_name)->toBe('notice.jpg');
});

it('never touches an announcement typed here', function () {
    $manual = Announcement::create([
        'title' => 'Written by HR', 'body' => 'Our own text', 'severity' => 'urgent',
        'audience' => 'all', 'is_published' => true,
    ]);

    announcementSyncWith([oracleRow('1', 'From Oracle')])->sync();

    $manual->refresh();

    expect($manual->title)->toBe('Written by HR')
        ->and($manual->body)->toBe('Our own text')
        ->and($manual->severity)->toBe('urgent')
        ->and($manual->source)->toBe(Announcement::SOURCE_MANUAL)
        ->and($manual->removed_at)->toBeNull();
});

it('reports a second identical pull as unchanged', function () {
    $sync = announcementSyncWith([oracleRow('1', 'A notice')]);
    $sync->sync();

    $again = announcementSyncWith([oracleRow('1', 'A notice')])->sync();

    expect([$again['created'], $again['updated'], $again['unchanged']])->toBe([0, 0, 1]);
});

it('follows Oracle when Oracle changes the subject', function () {
    announcementSyncWith([oracleRow('1', 'First wording')])->sync();
    $counts = announcementSyncWith([oracleRow('1', 'Corrected wording')])->sync();

    expect($counts['updated'])->toBe(1);
    expect(Announcement::sole()->title)->toBe('Corrected wording');
});

it('keeps an edit made here and still follows Oracle on the other fields', function () {
    announcementSyncWith([oracleRow('1', 'Oracle wording')])->sync();

    // Somebody tidies the title and writes the text Oracle cannot give us.
    $ann = Announcement::sole();
    $ann->update(['title' => 'A clearer title', 'body' => 'Typed by HR']);

    $counts = announcementSyncWith([oracleRow('1', 'Oracle changed it again', ['expireDate' => '2099-11-30'])])->sync();

    $ann->refresh();

    expect($ann->title)->toBe('A clearer title')
        ->and($ann->body)->toBe('Typed by HR')
        // expires_at was never edited here, so Oracle still owns it.
        ->and($ann->expires_at->toDateString())->toBe('2099-11-30')
        ->and($counts['kept'])->toBe(1);
});

it('takes a field back once Oracle\'s own text is restored', function () {
    announcementSyncWith([oracleRow('1', 'Oracle wording')])->sync();

    $ann = Announcement::sole();
    $ann->update(['title' => 'Mine now']);
    announcementSyncWith([oracleRow('1', 'Oracle wording')])->sync();

    // Put Oracle's wording back by hand: the sync owns the field again.
    $ann->refresh()->update(['title' => 'Oracle wording']);
    announcementSyncWith([oracleRow('1', 'Oracle moved on')])->sync();

    expect(Announcement::sole()->title)->toBe('Oracle moved on');
});

it('withdraws a notice that leaves the feed without deleting it', function () {
    announcementSyncWith([oracleRow('1', 'Here'), oracleRow('2', 'Also here')])->sync();

    $counts = announcementSyncWith([oracleRow('1', 'Here')])->sync();

    $gone = Announcement::where('external_id', '2')->sole();

    expect($counts['removed'])->toBe(1)
        ->and(Announcement::count())->toBe(2)
        ->and($gone->removed_at)->not->toBeNull()
        ->and($gone->is_published)->toBeFalse();
});

it('puts a notice back on the board when Oracle lists it again', function () {
    announcementSyncWith([oracleRow('1', 'Here'), oracleRow('2', 'Also here')])->sync();
    announcementSyncWith([oracleRow('1', 'Here')])->sync();

    $counts = announcementSyncWith([oracleRow('1', 'Here'), oracleRow('2', 'Also here')])->sync();

    $back = Announcement::where('external_id', '2')->sole();

    expect($counts['restored'])->toBe(1)
        ->and($back->removed_at)->toBeNull()
        ->and($back->is_published)->toBeTrue();
});

it('does not republish a notice somebody chose to hide', function () {
    announcementSyncWith([oracleRow('1', 'Here'), oracleRow('2', 'Also here')])->sync();

    // An admin unpublishes it deliberately, and only then does Oracle drop it.
    Announcement::where('external_id', '2')->sole()->update(['is_published' => false]);
    announcementSyncWith([oracleRow('1', 'Here')])->sync();
    announcementSyncWith([oracleRow('1', 'Here'), oracleRow('2', 'Also here')])->sync();

    expect(Announcement::where('external_id', '2')->sole()->is_published)->toBeFalse();
});

it('changes nothing when the response is too small to believe', function () {
    $rows = collect(range(1, 40))->map(fn ($i) => oracleRow((string) $i, "Notice {$i}"))->all();
    announcementSyncWith($rows)->sync();

    expect(Announcement::count())->toBe(40);

    // A truncated response would otherwise withdraw 38 live notices.
    expect(fn () => announcementSyncWith([oracleRow('1', 'Notice 1'), oracleRow('2', 'Notice 2')])->sync())
        ->toThrow(RuntimeException::class);

    expect(Announcement::whereNotNull('removed_at')->count())->toBe(0);
});

it('rolls a dry run back completely', function () {
    $counts = announcementSyncWith([oracleRow('1', 'A notice')])->sync(dryRun: true);

    expect($counts['created'])->toBe(1)
        ->and($counts['dry_run'])->toBeTrue()
        ->and(Announcement::count())->toBe(0);
});

it('writes one audit row for the run, not one per announcement', function () {
    DB::table('activity_logs')->delete();

    announcementSyncWith([
        oracleRow('1', 'One'), oracleRow('2', 'Two'), oracleRow('3', 'Three'),
    ])->sync();

    $logs = DB::table('activity_logs')->get();

    // The model is audited like any other, so without muting the writes this
    // would be four rows an hour, burying the edits people actually make.
    expect($logs->where('model_type', Announcement::class)->count())->toBe(1)
        ->and($logs->firstWhere('model_type', Announcement::class)->action)->toBe('announcement_sync');

    // And the run must not log the settings row's own clock moving either.
    expect($logs->where('action', 'updated')->count())->toBe(0);
});
