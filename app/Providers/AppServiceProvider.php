<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Pagination\Paginator;

// Models
use App\Models\AlertRule;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Credential;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\IpamSubnet;
use App\Models\IpReservation;
use App\Models\IspConnection;
use App\Models\ItTask;
use App\Models\License;
use App\Models\NetworkSwitch;
use App\Models\NotificationRule;
use App\Models\Printer;
use App\Models\RolePermission;
use App\Models\SophosFirewall;
use App\Models\User;
use App\Models\VpnTunnel;
use App\Models\DnsAccount;
use App\Models\WorkflowRequest;

// Observers
use App\Observers\AlertRuleObserver;
use App\Observers\BranchObserver;
use App\Observers\ContactObserver;
use App\Observers\CredentialObserver;
use App\Observers\DnsAccountObserver;
use App\Observers\DeviceObserver;
use App\Observers\EmployeeObserver;
use App\Observers\IncidentObserver;
use App\Observers\IpamSubnetObserver;
use App\Observers\IpReservationObserver;
use App\Observers\IspConnectionObserver;
use App\Observers\ItTaskObserver;
use App\Observers\LicenseObserver;
use App\Observers\NetworkSwitchObserver;
use App\Observers\NotificationRuleObserver;
use App\Observers\PrinterObserver;
use App\Observers\SophosFirewallObserver;
use App\Observers\UserObserver;
use App\Observers\VpnTunnelObserver;
use App\Observers\WorkflowRequestObserver;

// Events
use App\Events\EmployeeCreated;
use App\Events\HostStatusChanged;
use App\Events\PoorVoiceQualityDetected;
use App\Listeners\WorkflowTriggerListener;
use App\Listeners\FireVoiceQualityAlert;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Bootstrap 5 pagination
        Paginator::useBootstrapFive();

        // ── Audit Log Observers ──────────────────────────────────────
        AlertRule::observe(AlertRuleObserver::class);
        Branch::observe(BranchObserver::class);
        Contact::observe(ContactObserver::class);
        Credential::observe(CredentialObserver::class);
        DnsAccount::observe(DnsAccountObserver::class);
        Device::observe(DeviceObserver::class);
        Employee::observe(EmployeeObserver::class);
        Incident::observe(IncidentObserver::class);
        IpamSubnet::observe(IpamSubnetObserver::class);
        IpReservation::observe(IpReservationObserver::class);
        IspConnection::observe(IspConnectionObserver::class);
        ItTask::observe(ItTaskObserver::class);
        License::observe(LicenseObserver::class);
        NetworkSwitch::observe(NetworkSwitchObserver::class);
        NotificationRule::observe(NotificationRuleObserver::class);
        Printer::observe(PrinterObserver::class);
        SophosFirewall::observe(SophosFirewallObserver::class);
        User::observe(UserObserver::class);
        VpnTunnel::observe(VpnTunnelObserver::class);
        WorkflowRequest::observe(WorkflowRequestObserver::class);

        // ── Workflow Event Triggers ──────────────────────────────────
        Event::listen([EmployeeCreated::class, HostStatusChanged::class], WorkflowTriggerListener::class);

        // ── Voice Quality Alert ──────────────────────────────────────
        Event::listen(PoorVoiceQualityDetected::class, FireVoiceQualityAlert::class);

        // ── Microsoft Socialite ──────────────────────────────────────
        Event::listen(
            \SocialiteProviders\Manager\SocialiteWasCalled::class,
            \SocialiteProviders\Microsoft\MicrosoftExtendSocialite::class.'@handle'
        );

        // ── Track last login timestamp ───────────────────────────────
        Event::listen(\Illuminate\Auth\Events\Login::class, function ($event) {
            if ($event->user) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });

        // ── Permission Gates (DB-driven via role_permissions) ────────
        // super_admin is implicitly granted every permission — same contract
        // as EnsurePermission. Gate::before runs before every check and
        // short-circuits on `true`.
        Gate::before(function ($user) {
            if (($user->role ?? null) === 'super_admin') {
                return true;
            }
        });

        $gateCheck = function ($user, string $permission): bool {
            try {
                return RolePermission::roleHas($user->role ?? '', $permission);
            } catch (\Exception) {
                return false;
            }
        };

        foreach (RolePermission::allSlugs() as $slug) {
            Gate::define($slug, fn($user) => $gateCheck($user, $slug));
        }

        Gate::define('edit-content', fn($user) => $gateCheck($user, 'manage-contacts'));

        // ── Load SMTP settings from DB ───────────────────────────────
        try {
            (new \App\Services\SmtpConfigService())->loadFromSettings();
        } catch (\Exception) {
            // Skip if DB not ready yet
        }
    }
}
