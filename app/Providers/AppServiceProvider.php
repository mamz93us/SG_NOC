<?php

namespace App\Providers;

use App\Events\EmployeeCreated;
use App\Events\HostStatusChanged;
use App\Events\PoorVoiceQualityDetected;
use App\Listeners\FireVoiceQualityAlert;
use App\Listeners\WorkflowTriggerListener;
use App\Models\AlertRule;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Credential;
// Models
use App\Models\Device;
use App\Models\DnsAccount;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\Incident;
use App\Models\IpamSubnet;
use App\Models\IpReservation;
use App\Models\IspConnection;
use App\Models\ItTask;
use App\Models\License;
use App\Models\NetworkSwitch;
use App\Models\NocEvent;
use App\Models\NotificationRule;
use App\Models\Printer;
use App\Models\RolePermission;
use App\Models\SophosFirewall;
use App\Models\User;
use App\Models\VpnTunnel;
use App\Models\WorkflowRequest;
use App\Observers\AlertRuleObserver;
use App\Observers\BranchObserver;
use App\Observers\ContactObserver;
use App\Observers\CredentialObserver;
// Observers
use App\Observers\DeviceObserver;
use App\Observers\DnsAccountObserver;
use App\Observers\EmployeeAssetObserver;
use App\Observers\EmployeeObserver;
use App\Observers\IncidentObserver;
use App\Observers\IpamSubnetObserver;
use App\Observers\IpReservationObserver;
use App\Observers\IspConnectionObserver;
use App\Observers\ItTaskObserver;
use App\Observers\LicenseObserver;
use App\Observers\NetworkSwitchObserver;
use App\Observers\NocEventObserver;
use App\Observers\NotificationRuleObserver;
use App\Observers\PrinterObserver;
use App\Observers\SophosFirewallObserver;
use App\Observers\UserObserver;
use App\Observers\VpnTunnelObserver;
use App\Observers\WorkflowRequestObserver;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
// Events
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use League\Flysystem\Filesystem;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;

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
        EmployeeAsset::observe(EmployeeAssetObserver::class);
        Incident::observe(IncidentObserver::class);
        NocEvent::observe(NocEventObserver::class);
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

        // ── Auth audit trail (last-login + activity log for login/logout/fail/lockout) ─
        Event::listen(\Illuminate\Auth\Events\Login::class, function ($event) {
            if ($event->user) {
                $ip = request()?->ip();
                $event->user->forceFill([
                    'last_login_at' => now(),
                    'last_login_ip' => $ip,
                ])->saveQuietly();
                \App\Models\ActivityLog::create([
                    'model_type' => \App\Models\User::class,
                    'model_id' => $event->user->id,
                    'action' => 'login',
                    'changes' => [
                        'guard' => $event->guard ?? null,
                        'remember' => $event->remember ?? null,
                        'ip' => $ip,
                        'agent' => request()?->userAgent(),
                    ],
                    'user_id' => $event->user->id,
                ]);
            }
        });

        Event::listen(\Illuminate\Auth\Events\Logout::class, function ($event) {
            if ($event->user) {
                \App\Models\ActivityLog::create([
                    'model_type' => \App\Models\User::class,
                    'model_id' => $event->user->id,
                    'action' => 'logout',
                    'changes' => ['guard' => $event->guard ?? null],
                    'user_id' => $event->user->id,
                ]);
            }
        });

        Event::listen(\Illuminate\Auth\Events\Failed::class, function ($event) {
            \App\Models\ActivityLog::create([
                'model_type' => \App\Models\User::class,
                'model_id' => $event->user?->id ?? 0,
                'action' => 'login_failed',
                'changes' => [
                    'guard' => $event->guard ?? null,
                    'attempted' => [
                        'email' => $event->credentials['email'] ?? null,
                    ],
                    'matched_user' => $event->user?->id,
                ],
                'user_id' => $event->user?->id,
            ]);
        });

        Event::listen(\Illuminate\Auth\Events\Lockout::class, function ($event) {
            \App\Models\ActivityLog::create([
                'model_type' => \App\Models\User::class,
                'model_id' => 0,
                'action' => 'login_lockout',
                'changes' => [
                    'email' => $event->request->input('email'),
                ],
                'user_id' => null,
            ]);
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
                return method_exists($user, 'hasPermission')
                    ? $user->hasPermission($permission)
                    : RolePermission::roleHas($user->role ?? '', $permission);
            } catch (\Exception) {
                return false;
            }
        };

        foreach (RolePermission::allSlugs() as $slug) {
            Gate::define($slug, fn ($user) => $gateCheck($user, $slug));
        }

        Gate::define('edit-content', fn ($user) => $gateCheck($user, 'manage-contacts'));

        // ── Load SMTP settings from DB ───────────────────────────────
        try {
            (new \App\Services\SmtpConfigService)->loadFromSettings();
        } catch (\Exception) {
            // Skip if DB not ready yet
        }

        // ── Azure Blob disk for offboarding backups ──────────────────
        // Reads credentials from Setting singleton on every disk resolve
        // (settings UI overrides env). Silently no-ops if the
        // league/flysystem-azure-blob-storage package isn't installed yet,
        // so a fresh checkout can still boot before `composer require`.
        if (class_exists(AzureBlobStorageAdapter::class) && class_exists(BlobRestProxy::class)) {
            Storage::extend('azure', function ($app, $config) {
                $account = $config['account'] ?? null;
                $key = $config['key'] ?? null;
                $container = $config['container'] ?? 'noc-offboarding-backups';
                $suffix = $config['endpoint'] ?? 'core.windows.net';

                try {
                    $settings = \App\Models\Setting::get();
                    $account = $settings->azure_blob_account ?: $account;
                    $key = $settings->azure_blob_key ?: $key;
                    $container = $settings->azure_blob_container ?: $container;
                    $suffix = $settings->azure_blob_endpoint_suffix ?: $suffix;
                } catch (\Throwable) {
                    // settings table may not exist yet during migrations
                }

                if (! $account || ! $key) {
                    // Return a noop adapter rather than crashing; callers should check
                    // testConnection() before relying on writes.
                    throw new \RuntimeException(
                        'Azure Blob disk is not configured (account/key missing). '
                        .'Set via Admin → Settings → Azure Blob or env AZURE_BLOB_ACCOUNT / AZURE_BLOB_KEY.'
                    );
                }

                $connection = "DefaultEndpointsProtocol=https;AccountName={$account};AccountKey={$key};EndpointSuffix={$suffix}";
                $client = BlobRestProxy::createBlobService($connection);
                $adapter = new AzureBlobStorageAdapter($client, $container, $config['prefix'] ?? null);

                return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
            });
        }
    }
}
