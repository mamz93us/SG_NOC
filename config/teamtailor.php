<?php

return [
    // API stack base URL. EU: https://api.teamtailor.com — NA: https://api.na.teamtailor.com
    'base_url' => rtrim(env('TEAMTAILOR_BASE_URL', 'https://api.teamtailor.com'), '/'),

    // Recruiter-app base used to deep-link a candidate out to Teamtailor. Paste the
    // part of a candidate URL BEFORE "/candidates/{id}" — e.g.
    // https://app.teamtailor.com/companies/<company> — and the profile page appends
    // "/candidates/{id}". Optional: the "View in Teamtailor" link is hidden when blank.
    'app_url' => rtrim((string) env('TEAMTAILOR_APP_URL', ''), '/'),

    // Admin-scoped API token (Teamtailor → Settings → Integrations → API keys).
    // Listing candidates requires the Admin scope. Never commit this — set it in .env.
    'api_key' => env('TEAMTAILOR_API_KEY', ''),

    // Mandatory X-Api-Version header. Teamtailor pins response behaviour to a dated version.
    'api_version' => env('TEAMTAILOR_API_VERSION', '20240904'),

    // Default page size for candidate listings. Teamtailor hard-caps page[size] at 30.
    'page_size' => (int) env('TEAMTAILOR_PAGE_SIZE', 25),

    // HTTP timeout (seconds) for API calls.
    'timeout' => (int) env('TEAMTAILOR_TIMEOUT', 30),
];
