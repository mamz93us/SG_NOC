<?php

namespace App\Services\Teamtailor;

use Illuminate\Support\Facades\Cache;

/**
 * Per-job Teamtailor data that many candidates share and that rarely changes:
 * the pipeline's stage names, and the job's picked (application) questions —
 * how an answer is known to belong to this job. Cached ten minutes. A failed
 * fetch throws out of remember(), so it is never cached, and reads as empty.
 */
class TeamtailorJobLookups
{
    private const CACHE_SECONDS = 600;

    public function __construct(private TeamtailorApiService $api) {}

    /** @return list<string> */
    public function pickedQuestionIds(string $jobId): array
    {
        try {
            return Cache::remember("teamtailor:job:{$jobId}:picked-question-ids", self::CACHE_SECONDS, function () use ($jobId) {
                $ids = [];

                for ($page = 1; $page <= 5; $page++) {
                    $batch = $this->api->listJobPickedQuestions($jobId, $page)['data'] ?? [];

                    foreach ($batch as $picked) {
                        $ids[] = (string) ($picked['id'] ?? '');
                    }

                    if (count($batch) < 30) {
                        break;
                    }
                }

                return array_values(array_filter($ids));
            });
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string,string> stage id => name */
    public function stageNames(string $jobId): array
    {
        try {
            return Cache::remember("teamtailor:job:{$jobId}:stage-names", self::CACHE_SECONDS, function () use ($jobId) {
                $names = [];

                foreach ($this->api->listJobStages($jobId)['data'] ?? [] as $stage) {
                    if (isset($stage['id'], $stage['attributes']['name'])) {
                        $names[(string) $stage['id']] = (string) $stage['attributes']['name'];
                    }
                }

                return $names;
            });
        } catch (\Throwable) {
            return [];
        }
    }
}
