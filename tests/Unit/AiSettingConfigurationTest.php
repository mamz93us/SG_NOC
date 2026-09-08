<?php

use App\Models\AiSetting;

/**
 * configurationIssue() gates the chat endpoint and drives the settings page's
 * status pill — every missing field must fail with a specific sentence
 * rather than a generic 500 from inside a completion call. No database: an
 * in-memory model, same pattern as Setting/WhatsAppService's own tests.
 */
uses(Tests\TestCase::class);

it('reports switched off when not enabled', function () {
    $settings = new AiSetting(['enabled' => false]);

    expect($settings->isConfigured())->toBeFalse();
    expect($settings->configurationIssue())->toContain('switched off');
});

it('reports a missing endpoint', function () {
    $settings = new AiSetting(['enabled' => true]);

    expect($settings->configurationIssue())->toContain('endpoint');
});

it('reports a missing api key', function () {
    $settings = new AiSetting([
        'enabled' => true,
        'azure_endpoint' => 'https://example.openai.azure.com',
    ]);

    expect($settings->configurationIssue())->toContain('API key');
});

it('reports a missing chat deployment', function () {
    $settings = new AiSetting([
        'enabled' => true,
        'azure_endpoint' => 'https://example.openai.azure.com',
        'azure_api_key' => 'secret',
    ]);

    expect($settings->configurationIssue())->toContain('chat deployment');
});

it('is configured once every required field is set', function () {
    $settings = new AiSetting([
        'enabled' => true,
        'azure_endpoint' => 'https://example.openai.azure.com',
        'azure_api_key' => 'secret',
        'chat_deployment' => 'gpt-4o',
    ]);

    expect($settings->configurationIssue())->toBeNull();
    expect($settings->isConfigured())->toBeTrue();
});

it('encrypts the api key at rest and decrypts it back transparently', function () {
    $settings = new AiSetting(['azure_api_key' => 'super-secret']);

    expect($settings->getAttributes()['azure_api_key'])->not->toBe('super-secret');
    expect($settings->azure_api_key)->toBe('super-secret');
});

it('needs an embedding deployment specifically for embeddingsConfigured', function () {
    $settings = new AiSetting([
        'enabled' => true,
        'azure_endpoint' => 'https://example.openai.azure.com',
        'azure_api_key' => 'secret',
        'chat_deployment' => 'gpt-4o',
    ]);

    expect($settings->embeddingsConfigured())->toBeFalse();

    $settings->embedding_deployment = 'text-embedding-3-small';

    expect($settings->embeddingsConfigured())->toBeTrue();
});
