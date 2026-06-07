<?php

namespace App\Services\EmailMarketing;

use App\Models\EmailMarketing\EmailCampaign;
use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * Decides whether a campaign needs IT (super_admin) approval before it can send.
 *
 * Rule: a campaign is exempt only when EVERY resolved recipient is on an internal
 * domain (samirgroup.com / sssegypt.com by default, configurable). A global
 * "require all approval" setting overrides the exemption. Recipient resolution is
 * delegated to the dispatcher's read-only resolver so we never create rows here.
 */
class CampaignApprovalService
{
    public function __construct(private CampaignDispatcher $dispatcher) {}

    /**
     * Internal domains (lowercased, no leading @) that exempt a campaign.
     *
     * @return array<int,string>
     */
    public function internalDomains(): array
    {
        $raw = Setting::get()->email_marketing_internal_domains;
        $raw = is_string($raw) && trim($raw) !== '' ? $raw : 'samirgroup.com,sssegypt.com';

        return collect(preg_split('/[,\s]+/', strtolower($raw)))
            ->map(fn ($d) => ltrim(trim((string) $d), '@'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Does this campaign need approval before it can be sent?
     */
    public function requiresApproval(EmailCampaign $campaign): bool
    {
        if (Setting::get()->email_marketing_require_all_approval) {
            return true;
        }

        return ! $this->isInternalOnly($campaign);
    }

    /**
     * True only when the campaign has recipients and every one of them is internal.
     */
    public function isInternalOnly(EmailCampaign $campaign): bool
    {
        $emails = $this->dispatcher->recipientEmails($campaign)->all();

        // No recipients resolved → not "internal-only"; let an approver look.
        if ($emails === []) {
            return false;
        }

        return $this->emailsAreInternal($emails, $this->internalDomains());
    }

    /**
     * Pure check: are ALL the given emails on one of the internal domains?
     *
     * @param  array<int,string>  $emails
     * @param  array<int,string>  $internalDomains
     */
    public function emailsAreInternal(array $emails, array $internalDomains): bool
    {
        if ($emails === [] || $internalDomains === []) {
            return false;
        }

        foreach ($emails as $email) {
            $domain = strtolower(Str::after((string) $email, '@'));
            if ($domain === '' || ! in_array($domain, $internalDomains, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Distinct external (non-internal) recipient domains — shown to the approver.
     *
     * @return array<int,string>
     */
    public function externalDomains(EmailCampaign $campaign): array
    {
        $internal = $this->internalDomains();

        return $this->dispatcher->recipientEmails($campaign)
            ->map(fn ($e) => strtolower(Str::after((string) $e, '@')))
            ->reject(fn ($d) => $d === '' || in_array($d, $internal, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Recipient counts + a sample of external addresses for the approvals UI.
     *
     * @return array{total:int,external_count:int,external_domains:array<int,string>,external_sample:array<int,string>}
     */
    public function summary(EmailCampaign $campaign): array
    {
        $emails = $this->dispatcher->recipientEmails($campaign);
        $internal = $this->internalDomains();
        $external = $emails->filter(
            fn ($e) => ! in_array(strtolower(Str::after((string) $e, '@')), $internal, true)
        );

        return [
            'total' => $emails->count(),
            'external_count' => $external->count(),
            'external_domains' => $this->externalDomains($campaign),
            'external_sample' => $external->take(10)->values()->all(),
        ];
    }
}
