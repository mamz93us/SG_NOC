<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The "From" address a given kind of system email goes out as.
 *
 * Onboarding and offboarding can send as it@samirgroup.com while monitoring
 * alerts send as noc@samirgroup.com, rather than everything sharing the single
 * global smtp_from_address.
 *
 * Resolution order for every field: this row → the global Setting default →
 * app config. So an empty row means "inherit", not "send from nothing".
 *
 * Usage at a send point:
 *
 *     $sender = MailSender::for(MailSender::ONBOARDING);
 *     Mail::to($to)->send((new SomeMail(...))->from($sender['address'], $sender['name']));
 */
class MailSender extends Model
{
    public const ONBOARDING = 'onboarding';

    public const OFFBOARDING = 'offboarding';

    public const WORKFLOWS = 'workflows';

    public const ALERTS = 'alerts';

    public const PRINTERS = 'printers';

    public const NOTIFICATIONS = 'notifications';

    public const BACKUPS = 'backups';

    /**
     * The catalogue lives in code, not the database — a service only exists if
     * something actually sends mail for it. The table stores the addresses.
     *
     * @var array<string, array{label:string, description:string, example:string}>
     */
    public const SERVICES = [
        self::ONBOARDING => [
            'label' => 'Employee Onboarding',
            'description' => 'Manager setup form, IT provisioning summary, and the new hire’s welcome email.',
            'example' => 'it@samirgroup.com',
        ],
        self::OFFBOARDING => [
            'label' => 'Employee Offboarding',
            'description' => 'Manager decision form, reminders, IT escalation, backup download links and HR feedback.',
            'example' => 'it@samirgroup.com',
        ],
        self::WORKFLOWS => [
            'label' => 'Workflow Approvals',
            'description' => 'Approval requests, workflow action emails and request status updates.',
            'example' => 'it@samirgroup.com',
        ],
        self::ALERTS => [
            'label' => 'NOC & Monitoring Alerts',
            'description' => 'SNMP/syslog alert rules, device and link incidents.',
            'example' => 'noc@samirgroup.com',
        ],
        self::PRINTERS => [
            'label' => 'Printers',
            'description' => 'Printer setup invitations, toner/supply alerts and the toner digest.',
            'example' => 'noc@samirgroup.com',
        ],
        self::NOTIFICATIONS => [
            'label' => 'General Notifications',
            'description' => 'Everything else routed through the in-app notification system.',
            'example' => 'noc@samirgroup.com',
        ],
        self::BACKUPS => [
            'label' => 'Backups',
            'description' => 'AvePoint backup-ready notices and backup job reports.',
            'example' => 'noc@samirgroup.com',
        ],
    ];

    protected $fillable = [
        'service_key',
        'from_address',
        'from_name',
        'reply_to',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Per-request memo so a batch of sends doesn't re-query per message. */
    private static array $cache = [];

    /**
     * Resolve the sender for a service, falling back to the global default.
     *
     * Never throws and never returns an empty address when a global default
     * exists — a missing or misconfigured row degrades to current behaviour
     * rather than breaking the send.
     *
     * @return array{address:?string, name:?string, reply_to:?string}
     */
    public static function for(string $serviceKey): array
    {
        if (isset(self::$cache[$serviceKey])) {
            return self::$cache[$serviceKey];
        }

        $settings = Setting::get();

        $defaultAddress = $settings->smtp_from_address ?: $settings->ses_default_from_email;
        $defaultName = $settings->smtp_from_name ?: ($settings->ses_default_from_name ?: config('app.name'));

        $row = null;
        try {
            $row = static::where('service_key', $serviceKey)->where('is_active', true)->first();
        } catch (\Throwable) {
            // Table not migrated yet — fall through to the global default.
        }

        return self::$cache[$serviceKey] = [
            'address' => ($row?->from_address ?: null) ?? $defaultAddress,
            'name' => ($row?->from_name ?: null) ?? $defaultName,
            'reply_to' => $row?->reply_to ?: null,
        ];
    }

    /**
     * Convenience for the many jobs that only need the two values.
     *
     * @return array{0:?string, 1:?string} [address, name]
     */
    public static function fromFor(string $serviceKey): array
    {
        $s = static::for($serviceKey);

        return [$s['address'], $s['name']];
    }

    /**
     * Stamp a Mailable with this service's sender and reply-to. Preferred over
     * fromFor() at Mailable send points because it also carries reply_to —
     * e.g. alerts sent as noc@ that should be replied to at it@.
     *
     * Usage: Mail::to($x)->send(MailSender::apply(new SomeMail(...), MailSender::ALERTS));
     */
    public static function apply(\Illuminate\Mail\Mailable $mailable, string $serviceKey): \Illuminate\Mail\Mailable
    {
        $s = static::for($serviceKey);

        if ($s['address']) {
            $mailable->from($s['address'], $s['name']);
        }
        if ($s['reply_to']) {
            $mailable->replyTo($s['reply_to']);
        }

        return $mailable;
    }

    /**
     * Same, for the Mail::raw()/Mail::send() closure style where you get a
     * Message rather than a Mailable.
     */
    public static function applyToMessage(\Illuminate\Mail\Message $message, string $serviceKey): void
    {
        $s = static::for($serviceKey);

        if ($s['address']) {
            $message->from($s['address'], $s['name']);
        }
        if ($s['reply_to']) {
            $message->replyTo($s['reply_to']);
        }
    }

    /** Reset the memo — call after saving from the admin page. */
    public static function flushCache(): void
    {
        self::$cache = [];
    }

    public function label(): string
    {
        return self::SERVICES[$this->service_key]['label'] ?? ucfirst(str_replace('_', ' ', $this->service_key));
    }

    public function description(): string
    {
        return self::SERVICES[$this->service_key]['description'] ?? '';
    }

    public function example(): string
    {
        return self::SERVICES[$this->service_key]['example'] ?? '';
    }

    /** The domain part, for checking against SES verified identities. */
    public function domain(): ?string
    {
        if (! $this->from_address || ! str_contains($this->from_address, '@')) {
            return null;
        }

        return strtolower(explode('@', $this->from_address)[1]);
    }
}
