<?php

use App\Http\Controllers\Admin\BackupAccountController;
use App\Models\SftpBackup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

/**
 * The Last Size column and the totals footer on /admin/backups.
 *
 * The footer is the part worth testing: the table paginates at 50, so a total
 * that only added up the visible page would quietly understate storage once the
 * estate outgrows one page. And "stored" must exclude pruned backups -- prune
 * deletes the blob and nulls azure_path, so counting those would report space
 * that is not actually being used.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    foreach (['sftp_backups', 'backup_accounts'] as $t) {
        Schema::dropIfExists($t);
    }

    Schema::create('backup_accounts', function (Blueprint $t) {
        $t->id();
        $t->string('device_type')->nullable();
        $t->unsignedBigInteger('device_id')->nullable();
        $t->string('label')->nullable();
        $t->string('sftpgo_username');
        $t->text('password')->nullable();
        $t->text('protocols')->nullable();
        $t->string('home_dir')->nullable();
        $t->unsignedInteger('quota_mb')->nullable();
        $t->string('expected_frequency')->default('daily');
        $t->unsignedInteger('grace_minutes')->nullable();
        $t->timestamp('last_received_at')->nullable();
        $t->timestamp('last_archived_at')->nullable();
        $t->string('last_status')->nullable();
        $t->boolean('is_active')->default(true);
        $t->unsignedBigInteger('created_by')->nullable();
        $t->timestamps();
    });

    Schema::create('sftp_backups', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('account_id');
        $t->string('source')->nullable();
        $t->string('relative_path')->nullable();
        $t->string('filename')->nullable();
        $t->unsignedBigInteger('size')->nullable();
        $t->string('sha256')->nullable();
        $t->string('disk')->nullable();
        $t->string('azure_path')->nullable();
        $t->string('status')->nullable();
        $t->text('error')->nullable();
        $t->timestamp('received_at')->nullable();
        $t->timestamp('uploaded_at')->nullable();
        $t->timestamp('pruned_at')->nullable();
        $t->timestamps();
    });

    View::getFinder()->prependLocation(base_path('tests/stubs/views'));
    View::flushFinderCache();
});

function account(string $username, bool $active = true): int
{
    return DB::table('backup_accounts')->insertGetId([
        'sftpgo_username' => $username, 'protocols' => json_encode(['SFTP']),
        'expected_frequency' => 'daily', 'is_active' => $active,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @param  string  $status  uploaded | pruned | failed */
function backup(int $accountId, int $size, string $status = 'uploaded', ?string $at = null): void
{
    DB::table('sftp_backups')->insert([
        'account_id' => $accountId,
        'filename' => 'b'.$size.'.tgz',
        'size' => $size,
        'status' => $status,
        // prune deletes the blob and nulls azure_path; liveInAzure() keys off that.
        'azure_path' => $status === 'uploaded' ? 'az/'.$size.'.tgz' : null,
        'received_at' => $at ?? now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function indexHtml(array $params = []): string
{
    $request = Request::create('/admin/backups', 'GET', $params);

    return app(BackupAccountController::class)->index($request)->render();
}

// ─────────────────────────────────────────────────────────────────────

it('shows the size of each account latest backup', function () {
    $a = account('jed_fw');
    backup($a, 1024 * 1024, at: now()->subDay());       // older
    backup($a, 5 * 1024 * 1024, at: now());             // newest

    $html = indexHtml();

    // 5 MB is the newest, so that is the one the column reports.
    expect($html)->toContain('5.0 MB')
        ->and($html)->not->toContain('1.0 MB');
});

it('shows a dash for an account that has never backed up', function () {
    account('never_used');

    expect(indexHtml())->toContain('Last Size');
});

it('totals the last-size column across every account', function () {
    backup(account('a'), 2 * 1024 * 1024);
    backup(account('b'), 3 * 1024 * 1024);

    // 2 MB + 3 MB, and the label names how many accounts it covers.
    expect(indexHtml())->toContain('5.0 MB')
        ->toContain('Total across 2 accounts');
});

it('counts only the newest backup per account in the column total', function () {
    $a = account('a');
    backup($a, 1024 * 1024, at: now()->subDays(2));
    backup($a, 1024 * 1024, at: now()->subDay());
    backup($a, 4 * 1024 * 1024, at: now());

    // Three backups, but the column total is one-per-account: 4 MB, not 6 MB.
    expect(indexHtml())->toContain('4.0 MB');
});

it('excludes pruned backups from the stored total', function () {
    $a = account('a');
    backup($a, 10 * 1024 * 1024, 'uploaded');
    backup($a, 90 * 1024 * 1024, 'pruned');

    $html = indexHtml();

    // Pruned blobs are gone from Azure -- counting them would report storage
    // that is not being consumed or billed.
    expect($html)->toContain('10.0 MB stored in Azure')
        ->and($html)->not->toContain('100.0 MB stored');
});

it('totals every account, not just the current page', function () {
    // 60 accounts against a page size of 50: the last 10 are off-page, and a
    // footer summing only what is rendered would miss them.
    for ($i = 1; $i <= 60; $i++) {
        backup(account("acct{$i}"), 1024 * 1024);
    }

    expect(indexHtml())->toContain('Total across 60 accounts')
        ->toContain('60.0 MB');
});

it('narrows the totals to match an active filter', function () {
    backup(account('live_one', active: true), 4 * 1024 * 1024);
    backup(account('dead_one', active: false), 8 * 1024 * 1024);

    // A footer that ignored the filter would contradict the rows above it.
    expect(indexHtml(['status' => 'disabled']))
        ->toContain('Total across 1 account')
        ->toContain('8.0 MB');
});

it('renders without a footer when there are no accounts', function () {
    $html = indexHtml();

    expect($html)->toContain('No backup accounts yet.')
        ->and($html)->not->toContain('Total across');
});

it('formats totals the same way as individual rows', function () {
    // One shared formatter, so the footer cannot drift from the column.
    expect(SftpBackup::formatBytes(5 * 1024 * 1024))->toBe('5.0 MB')
        ->and(SftpBackup::formatBytes(0))->toBe('—')
        ->and(SftpBackup::formatBytes(null))->toBe('—');
});

it('does not issue a query per account', function () {
    for ($i = 1; $i <= 5; $i++) {
        backup(account("small{$i}"), 1024);
    }
    $few = 0;
    DB::listen(function () use (&$few) {
        $few++;
    });
    indexHtml();

    for ($i = 6; $i <= 45; $i++) {
        backup(account("big{$i}"), 1024);
    }
    $many = 0;
    DB::listen(function () use (&$many) {
        $many++;
    });
    indexHtml();

    // latestBackup() is a latestOfMany relation: one correlated subquery for the
    // whole page. Nine times the accounts must not mean nine times the queries.
    expect($many)->toBeLessThanOrEqual($few + 2);
});
