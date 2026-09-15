<?php

use App\Models\Recruitment\RecruitmentJob;
use App\Models\Recruitment\RecruitmentScreening;
use App\Services\Recruitment\ApplicantSync;
use App\Services\Recruitment\CandidateScreener;
use App\Services\Recruitment\CvReader;
use App\Services\Recruitment\CvUnreadable;
use App\Services\Recruitment\JobAd;
use App\Services\Recruitment\ScreeningResult;
use App\Services\Recruitment\ScreeningRunner;
use App\Services\Teamtailor\TeamtailorApiService;
use Tests\Unit\Recruitment\RecruitmentTestSchema;

uses(Tests\TestCase::class);

beforeEach(function () {
    RecruitmentTestSchema::create();
    RecruitmentTestSchema::fakeTeamtailor(RecruitmentTestSchema::routes());

    // Azure and poppler are replaced; Teamtailor is faked at the HTTP layer.
    $this->screener = new class extends CandidateScreener
    {
        public int $calls = 0;

        public ?Throwable $throw = null;

        public function __construct() {}

        public function evaluate(JobAd $ad, array $mustHaves, string $cvText, array $images, string $answers): array
        {
            $this->calls++;

            if ($this->throw) {
                throw $this->throw;
            }

            return [
                'result' => ScreeningResult::parse(json_encode([
                    'score' => 80,
                    'summary' => "Read for {$ad->title}",
                    'must_haves' => array_map(fn (string $m) => ['requirement' => $m, 'met' => 'yes'], $mustHaves),
                ]), 'stop', $mustHaves),
                'tokens_in' => 1200,
                'tokens_out' => 300,
            ];
        }
    };

    $this->cvs = new class extends CvReader
    {
        public ?Throwable $throw = null;

        public function __construct() {}

        public function read(string $url): array
        {
            if ($this->throw) {
                throw $this->throw;
            }

            return ['text' => "CV at {$url}", 'pages' => 2, 'images' => []];
        }
    };

    $this->runner = new ScreeningRunner(new ApplicantSync(new TeamtailorApiService), $this->cvs, $this->screener);
    $this->job = RecruitmentJob::create(['teamtailor_job_id' => '77', 'screening_enabled' => true, 'must_haves' => 'SAP']);
});

afterEach(fn () => RecruitmentTestSchema::drop());

function applicant(RecruitmentJob $job, string $candidateId): RecruitmentScreening
{
    return $job->screenings()->where('teamtailor_candidate_id', $candidateId)->firstOrFail();
}

it('reads the applicants, screens those with a CV and marks those without', function () {
    $totals = $this->runner->run(microtime(true) + 60);
    $job = $this->job->fresh();
    $mona = applicant($job, '501');

    expect($totals)->toBe(['screened' => 1, 'failed' => 0, 'throttled' => false])
        ->and($job->title)->toBe('Senior Accountant')
        ->and($job->applicant_count)->toBe(2)
        ->and($job->last_screened_at)->not->toBeNull()
        ->and($mona->status)->toBe(RecruitmentScreening::STATUS_SCREENED)
        ->and($mona->score)->toBe(80)
        ->and($mona->must_haves_met)->toBe(1)
        ->and($mona->cv_text)->toBe('CV at https://s3.example/501-fresh.pdf')
        ->and($mona->answers_text)->toContain('Q: Notice period?')
        ->and($mona->criteria_hash)->toBe($job->criteria_hash)
        ->and($mona->tokens_in)->toBe(1200)
        ->and(applicant($job, '502')->status)->toBe(RecruitmentScreening::STATUS_NO_CV);
});

it('keeps the CV text encrypted at rest', function () {
    $this->runner->run(microtime(true) + 60);

    $raw = DB::table('recruitment_screenings')->where('teamtailor_candidate_id', '501')->value('cv_text');

    expect($raw)->not->toBeNull()->not->toContain('s3.example');
});

it('screens nobody again until the must-haves change, then everyone', function () {
    $this->runner->run(microtime(true) + 60);
    $this->runner->run(microtime(true) + 60);
    expect($this->screener->calls)->toBe(1);

    $this->job->update(['must_haves' => "SAP\nArabic"]);
    $this->runner->run(microtime(true) + 60);

    expect($this->screener->calls)->toBe(2)
        ->and(applicant($this->job, '501')->must_haves_total)->toBe(2);
});

it('stops the run when Azure throttles and leaves the applicant queued', function () {
    $this->screener->throw = new RuntimeException('HTTP 429 from https://example.openai.azure.com: Too Many Requests');

    $totals = $this->runner->run(microtime(true) + 60);
    $mona = applicant($this->job, '501');

    expect($totals['throttled'])->toBeTrue()
        ->and($mona->status)->toBe(RecruitmentScreening::STATUS_PENDING)
        ->and($mona->attempts)->toBe(0);
});

it('fails an unreadable CV at once', function () {
    $this->cvs->throw = new CvUnreadable('The CV is not a PDF, so it cannot be read.');

    $this->runner->run(microtime(true) + 60);
    $mona = applicant($this->job, '501');

    expect($mona->status)->toBe(RecruitmentScreening::STATUS_FAILED)
        ->and($mona->error)->toContain('not a PDF');
});

it('gives a failing download one try per run, and fails it after three runs', function () {
    $this->cvs->throw = new RuntimeException('The CV could not be downloaded (HTTP 403).');

    $this->runner->run(microtime(true) + 60);
    expect(applicant($this->job, '501'))->status->toBe(RecruitmentScreening::STATUS_PENDING)->attempts->toBe(1);

    $this->runner->run(microtime(true) + 60);
    expect(applicant($this->job, '501'))->status->toBe(RecruitmentScreening::STATUS_PENDING)->attempts->toBe(2);

    $this->runner->run(microtime(true) + 60);
    expect(applicant($this->job, '501'))->status->toBe(RecruitmentScreening::STATUS_FAILED)->attempts->toBe(3);
});
