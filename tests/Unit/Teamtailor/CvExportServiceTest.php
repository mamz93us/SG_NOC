<?php

use App\Models\TeamtailorCvExport;
use App\Services\Teamtailor\TeamtailorCvExportService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

// Boot the app WITHOUT the Feature suite's RefreshDatabase: a fistful of
// pre-existing migrations use raw MySQL `MODIFY COLUMN`, which the test DB
// (SQLite :memory:) can't parse, so a full migrate aborts before any test
// body runs. This service never touches another table, so we create just the
// one it needs and exercise the real zip → upload path against fakes.
uses(Tests\TestCase::class);

beforeEach(function () {
    config()->set('teamtailor.base_url', 'https://api.teamtailor.com');
    config()->set('teamtailor.api_key', 'test-key-123');
    config()->set('teamtailor.api_version', '20240904');
    config()->set('teamtailor.page_size', 25);
    config()->set('teamtailor.timeout', 5);

    Schema::dropIfExists('teamtailor_cv_exports');
    (require base_path('database/migrations/2026_05_30_000010_create_teamtailor_cv_exports_table.php'))->up();
});

afterEach(function () {
    Schema::dropIfExists('teamtailor_cv_exports');
});

it('zips every applicant résumé and uploads it to the azure disk', function () {
    Storage::fake('azure_resumes');

    Http::fake([
        // One page of applicants, both with a résumé URL.
        'api.teamtailor.com/*' => Http::response([
            'data' => [
                ['id' => '1', 'type' => 'candidates', 'attributes' => [
                    'first-name' => 'Mona', 'last-name' => 'Ali',
                    'resume' => 'https://files.example.com/mona.pdf',
                ]],
                ['id' => '2', 'type' => 'candidates', 'attributes' => [
                    'first-name' => 'Sara', 'last-name' => 'Nabil',
                    'resume' => 'https://files.example.com/sara.docx',
                ]],
            ],
            'meta' => ['record-count' => 2, 'page-count' => 1],
        ], 200),
        // The résumé file host returns bytes.
        'files.example.com/*' => Http::response('%PDF-1.4 fake cv bytes', 200),
    ]);

    $export = TeamtailorCvExport::create([
        'job_id' => '123',
        'job_title' => 'Network Engineer',
        'status' => TeamtailorCvExport::STATUS_PENDING,
        'disk' => 'azure_resumes',
    ]);

    app(TeamtailorCvExportService::class)->process($export);

    $export->refresh();

    expect($export->status)->toBe(TeamtailorCvExport::STATUS_COMPLETED)
        ->and($export->cv_count)->toBe(2)
        ->and($export->failed_count)->toBe(0)
        ->and($export->total_candidates)->toBe(2)
        ->and($export->file_path)->not->toBeNull();

    Storage::disk('azure_resumes')->assertExists($export->file_path);
    expect($export->file_path)->toStartWith('123/')->toEndWith('.zip');
})->skip(fn () => ! class_exists(ZipArchive::class), 'requires the PHP zip extension');

it('fails cleanly when no applicant has a résumé', function () {
    Storage::fake('azure_resumes');

    Http::fake([
        'api.teamtailor.com/*' => Http::response([
            'data' => [
                ['id' => '1', 'type' => 'candidates', 'attributes' => [
                    'first-name' => 'Omar', 'last-name' => 'Said', 'resume' => null,
                ]],
            ],
            'meta' => ['record-count' => 1, 'page-count' => 1],
        ], 200),
    ]);

    $export = TeamtailorCvExport::create([
        'job_id' => '123',
        'status' => TeamtailorCvExport::STATUS_PENDING,
        'disk' => 'azure_resumes',
    ]);

    app(TeamtailorCvExportService::class)->process($export);

    $export->refresh();

    expect($export->status)->toBe(TeamtailorCvExport::STATUS_FAILED)
        ->and($export->error)->toContain('No résumé')
        ->and($export->cv_count)->toBe(0);
})->skip(fn () => ! class_exists(ZipArchive::class), 'requires the PHP zip extension');

it('counts résumé downloads that fail without aborting the export', function () {
    Storage::fake('azure_resumes');

    Http::fake([
        'api.teamtailor.com/*' => Http::response([
            'data' => [
                ['id' => '1', 'type' => 'candidates', 'attributes' => [
                    'first-name' => 'Mona', 'last-name' => 'Ali',
                    'resume' => 'https://files.example.com/ok.pdf',
                ]],
                ['id' => '2', 'type' => 'candidates', 'attributes' => [
                    'first-name' => 'Dead', 'last-name' => 'Link',
                    'resume' => 'https://files.example.com/gone.pdf',
                ]],
            ],
            'meta' => ['record-count' => 2, 'page-count' => 1],
        ], 200),
        'files.example.com/ok.pdf' => Http::response('%PDF ok', 200),
        'files.example.com/gone.pdf' => Http::response('', 404),
    ]);

    $export = TeamtailorCvExport::create([
        'job_id' => '123',
        'status' => TeamtailorCvExport::STATUS_PENDING,
        'disk' => 'azure_resumes',
    ]);

    app(TeamtailorCvExportService::class)->process($export);

    $export->refresh();

    // One good CV still produces a completed zip; the dead link is counted.
    expect($export->status)->toBe(TeamtailorCvExport::STATUS_COMPLETED)
        ->and($export->cv_count)->toBe(1)
        ->and($export->failed_count)->toBe(1);

    Storage::disk('azure_resumes')->assertExists($export->file_path);
})->skip(fn () => ! class_exists(ZipArchive::class), 'requires the PHP zip extension');
