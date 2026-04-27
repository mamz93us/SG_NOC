<?php

namespace App\Services\Workflow;

use App\Models\Branch;
use App\Models\Setting;
use App\Models\UcmServer;
use App\Services\IppbxApiService;

class ExtensionProvisioningService
{
    /**
     * Get the first available extension number in the range [start..end].
     * Queries the UCM API to get all used extensions, then returns the first gap.
     */
    public function getFirstAvailable(UcmServer $server, int $start, int $end): string
    {
        $api = new IppbxApiService($server);
        $api->login();

        $extensions  = $api->listExtensions();
        $usedNumbers = collect($extensions)
            ->pluck('extension')
            ->map(fn ($e) => (int) $e)
            ->toArray();

        for ($n = $start; $n <= $end; $n++) {
            if (! in_array($n, $usedNumbers)) {
                return (string) $n;
            }
        }

        throw new \RuntimeException("Extension range {$start}–{$end} is fully exhausted.");
    }

    /**
     * Create a UCM extension for a new user.
     * Voicemail and call_waiting are always disabled.
     * Secret and permission come from settings.
     *
     * $profile may include: department, phone_number, location, first_name, last_name.
     * If first_name/last_name are not provided, they are derived from $displayName.
     */
    public function createForUser(
        UcmServer $server,
        string    $extension,
        string    $displayName,
        string    $email,
        array     $profile = []
    ): array {
        $settings = Setting::get();

        $api = new IppbxApiService($server);
        $api->login();

        // Derive first/last name from displayName if not explicitly provided.
        $firstName = trim((string)($profile['first_name'] ?? ''));
        $lastName  = trim((string)($profile['last_name']  ?? ''));
        if ($firstName === '' && $lastName === '' && $displayName !== '') {
            $parts     = explode(' ', trim($displayName), 2);
            $firstName = $parts[0] ?? '';
            $lastName  = $parts[1] ?? '';
        }

        // UCM strictly requires an alphanumeric password WITH special characters
        // AND it must contain at least one uppercase and one lowercase letter.
        // IMPORTANT: UCM only accepts these special chars: @!*#  (same as UI generator)
        $complexSecret = substr(str_shuffle('abcdefghjkmnpqrstuvwxyz'), 0, 3) .
                         substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ'), 0, 3) .
                         substr(str_shuffle('23456789'), 0, 3) .
                         substr(str_shuffle('@!*#'), 0, 3);
        $complexSecret = str_shuffle($complexSecret);

        // Ensure permission string matches UCM cumulative format
        $rawPerm = $settings->ext_default_permission ?: 'national';
        $permMap = [
            'internal'      => 'internal',
            'local'         => 'internal-local',
            'national'      => 'internal-local-national',
            'international' => 'internal-local-national-international',
        ];
        $finalPerm = $permMap[$rawPerm] ?? $rawPerm;

        // 1. Create — include name + email so UCM saves them on the user record
        // from the start (the post-create update was sometimes a no-op when the
        // user record wasn't immediately fetchable via getUser).
        $createPayload = [
            'extension'     => (string) $extension,
            'secret'        => (string) $complexSecret,
            'user_password' => (string) $complexSecret,
            'vmsecret'      => (string) random_int(100000, 999999),
            'permission'    => (string) $finalPerm,
            'fullname'      => (string) $displayName,
            'first_name'    => (string) $firstName,
            'last_name'     => (string) $lastName,
            'email'         => (string) $email,
        ];
        $result = $api->createExtension($createPayload);

        // 2. Update with remaining profile fields and SIP options
        // (also re-send name/email defensively in case create silently dropped them).
        try {
            $updatePayload = [
                'fullname'     => $displayName,
                'first_name'   => $firstName,
                'last_name'    => $lastName,
                'email'        => $email,
                'hasvoicemail' => 'no',
                'call_waiting' => 'no',
                'dnd'          => 'no',
            ];

            if (!empty($profile['department'])) {
                $updatePayload['department'] = $profile['department'];
            }
            if (!empty($profile['phone_number'])) {
                $updatePayload['phone_number'] = $profile['phone_number'];
            }
            if (!empty($profile['location']) && empty($profile['department'])) {
                $updatePayload['department'] = $profile['location']; // Fallback location to department
            }

            $api->updateExtension($extension, $updatePayload);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("ExtensionProvisioningService: failed post-create update for {$extension}", [
                'error' => $e->getMessage()
            ]);
        }

        // Wait for UCM cooldown — createExtension() already called applyChanges() internally
        sleep(16);
        $api->applyChanges();

        // Merge the generated secret into the result so callers can store it
        return array_merge(is_array($result) ? $result : [], [
            'secret' => $complexSecret,
        ]);
    }

    /**
     * Replace template variables and build Azure profile fields.
     *
     * Supported variables: {branch_name}, {branch_phone}, {extension},
     *                       {first_name}, {last_name}, {upn}
     *
     * @param  array<string, string|null> $templates  ['officeLocation' => '...', 'phone' => '...']
     * @return array<string, string>
     */
    public function buildProfileFields(
        Branch $branch,
        string $extension,
        string $firstName,
        string $lastName,
        string $upn,
        array  $templates
    ): array {
        $vars = [
            '{branch_name}'  => $branch->name          ?? '',
            '{branch_phone}' => $branch->phone_number  ?? '',
            '{extension}'    => $extension,
            '{first_name}'   => $firstName,
            '{last_name}'    => $lastName,
            '{upn}'          => $upn,
        ];

        $result = [];
        foreach ($templates as $key => $template) {
            if ($template) {
                $result[$key] = str_replace(array_keys($vars), array_values($vars), $template);
            }
        }

        return $result;
    }
}
