<?php

use App\Services\Ai\KnowledgeRetriever;

/**
 * Cosine similarity against known vectors — the piece KnowledgeRetriever
 * relies on to rank chunks, verified in isolation from embeddings, chunking
 * or the database.
 */
uses(Tests\TestCase::class);

it('scores identical vectors as 1.0', function () {
    $v = [1.0, 2.0, 3.0];

    expect(KnowledgeRetriever::cosineSimilarity($v, $v))->toBeGreaterThan(0.9999);
});

it('scores orthogonal vectors as 0.0', function () {
    expect(KnowledgeRetriever::cosineSimilarity([1, 0], [0, 1]))->toBe(0.0);
});

it('scores opposite vectors as -1.0', function () {
    expect(KnowledgeRetriever::cosineSimilarity([1, 2, 3], [-1, -2, -3]))->toBeLessThan(-0.9999);
});

it('scores a known 3D pair correctly', function () {
    // dot = 1*4 + 2*5 + 3*6 = 32; |a| = sqrt(14), |b| = sqrt(77)
    $score = KnowledgeRetriever::cosineSimilarity([1, 2, 3], [4, 5, 6]);

    expect($score)->toBeGreaterThan(0.9746)->toBeLessThan(0.9748);
});

it('returns 0.0 rather than NaN for a zero vector', function () {
    expect(KnowledgeRetriever::cosineSimilarity([0, 0, 0], [1, 2, 3]))->toBe(0.0);
    expect(KnowledgeRetriever::cosineSimilarity([], []))->toBe(0.0);
});
