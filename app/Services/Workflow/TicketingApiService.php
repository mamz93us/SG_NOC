<?php

namespace App\Services\Workflow;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TicketingApiService
{
    /**
     * POST a new-employee ticket to the configured external ticketing API.
     *
     * Returns the parsed response on success:
     *   [
     *     'laptopAssignedEngineerEmail' => string,
     *     'phoneAssignedEngineerEmail'  => string,
     *     'laptopTicketId'              => int,
     *     'phoneTicketId'               => int,
     *   ]
     *
     * Returns null if the API is disabled / unconfigured / the call fails.
     */
    public function provisionNewEmployee(
        string $title,
        string $description,
        string $location,
        string $callerEmail
    ): ?array {
        $settings = Setting::get();

        if (! $settings->ticketing_api_enabled) {
            return null;
        }

        $url    = $settings->ticketing_api_url;
        $apiKey = $settings->ticketing_api_key;

        if (! $url || ! $apiKey) {
            Log::warning('TicketingApiService: API enabled but URL or key is missing.');
            return null;
        }

        $response = Http::withHeaders([
                'X-API-Key'    => $apiKey,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ])
            ->timeout(30)
            ->post($url, [
                'title'       => $title,
                'description' => $description,
                'location'    => $location,
                'callerEmail' => $callerEmail,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Ticketing API returned HTTP ' . $response->status() . ': ' . $response->body()
            );
        }

        $data = $response->json();

        // Basic shape validation — require ticket IDs to be usable downstream
        if (! is_array($data) || empty($data['laptopTicketId']) || empty($data['phoneTicketId'])) {
            throw new \RuntimeException(
                'Ticketing API response missing ticket IDs: ' . $response->body()
            );
        }

        return $data;
    }
}
