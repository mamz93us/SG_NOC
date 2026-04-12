# Internet Access Group — Provisioning Hook

This file documents where to add the internet access group assignment in `UserProvisioningService` (Step 3c).

## In `UserProvisioningService::provisionUser()`, inside Step 3c block:

```php
// ── Step 3c — Internet access security group (from manager form) ────────────
$internetGroupId = $payload['internet_access_group_id'] ?? null;
if ($internetGroupId) {
    try {
        $graph->addUserToGroup($payload['azure_id'], $internetGroupId);
        Log::info('[Provision] Internet access group assigned', [
            'upn'          => $payload['upn'],
            'group_id'     => $internetGroupId,
            'group_name'   => $payload['internet_access_group_name'] ?? 'unknown',
        ]);
    } catch (\Throwable $e) {
        // 409 = already member — non-fatal
        if (str_contains($e->getMessage(), '409') || str_contains($e->getMessage(), 'already')) {
            Log::warning('[Provision] Internet group 409 (already member)', ['group_id' => $internetGroupId]);
        } else {
            Log::warning('[Provision] Internet access group assignment failed (non-fatal): ' . $e->getMessage());
        }
    }
}
```

## Payload keys set by OnboardingFormController:

| Key | Type | Description |
|---|---|---|
| `internet_level` | string | Display label (e.g. "Full Internet") |
| `internet_level_id` | int | InternetAccessLevel.id |
| `internet_access_group_id` | string|null | Azure AD group Object ID |
| `internet_access_group_name` | string|null | Group display name |

## GraphService method needed:

If `addUserToGroup($userId, $groupId)` does not already exist in `GraphService`, add:

```php
public function addUserToGroup(string $userId, string $groupId): void
{
    $this->client->post("/groups/{$groupId}/members/\$ref", [
        'json' => [
            '@odata.id' => "https://graph.microsoft.com/v1.0/directoryObjects/{$userId}",
        ],
    ]);
}
```
