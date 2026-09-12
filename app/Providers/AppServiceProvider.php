<?php

namespace App\Providers;

use App\Events\EmployeeCreated;
use App\Events\HostStatusChanged;
use App\Events\PoorVoiceQualityDetected;
use App\Listeners\FireVoiceQualityAlert;
use App\Listeners\WorkflowTriggerListener;
// Models
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\NocEvent;
use App\Models\RolePermission;
use App\Models\WorkflowRequest;
// Observers — side effects only. Auditing is attached to every model by
// discovery in registerAuditing(), not by an import per model.
use App\Observers\EmployeeAssetObserver;
use App\Observers\EmployeeObserver;
use App\Observers\NocEventObserver;
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
        // Shared across every firewall sync in a run — loading the Wi-Fi MAC
        // set once instead of per-firewall.
        $this->app->singleton(\App\Services\Network\WifiMacDirectory::class);
    }

    public function boot(): void
    {
        // Bootstrap 5 pagination
        Paginator::useBootstrapFive();

        // ── Audit trail: every model, one observer ───────────────────
        // Replaces 20 near-identical per-model observers. See
        // registerAuditing() for how the model list is discovered, and
        // config/audit.php for the exclusions.
        $this->registerAuditing();

        // ── Model side effects (NOT audit — these do real work) ──────
        // Each of these fires business logic on a transition: dynamic list
        // reconciliation, the termination cascade, incident auto-escalation,
        // closing asset-return tasks, notifying a workflow's requester. The
        // audit rows they used to write are the AuditObserver's job now; the
        // semantic rows they still write (asset_returned,
        // auto_escalated_from_noc, termination_cascade) record facts a
        // column diff cannot express.
        Employee::observe(EmployeeObserver::class);
        EmployeeAsset::observe(EmployeeAssetObserver::class);
        NocEvent::observe(NocEventObserver::class);
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
        // A superuser role is implicitly granted every permission — same
        // contract as EnsurePermission and User::hasPermission(). Gate::before
        // runs before every check and short-circuits on `true`.
        //
        // Reads the role's is_super flag rather than comparing the slug to
        // 'super_admin', so @can in a Blade view agrees with the route gate for a
        // renamed or additional superuser role. Wrapped because this closure runs
        // for every @can on every page, including before the roles table exists.
        Gate::before(function ($user) {
            try {
                if (method_exists($user, 'isSuperAdmin') ? $user->isSuperAdmin() : ($user->role ?? null) === 'super_admin') {
                    return true;
                }
            } catch (\Throwable) {
                // Fall through to the per-permission checks below.
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

        // Only registered slugs get a gate, so `@can('some-unregistered-slug')`
        // in a view is false for everyone but a superuser. That was the other
        // half of the missing-registry bug: `@can('view-phone-firmware')` in the
        // nav hid the menu item even for people whose role held the grant.
        // The registry is complete now, and RolePermission::unregisteredSlugs()
        // is surfaced on both the Roles and Permissions pages so future drift is
        // reported rather than silently hiding a page.
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

    /**
     * Attach the generic AuditObserver to every model in app/Models.
     *
     * Discovery rather than a hand-maintained list, because the previous
     * arrangement — one observer per model, registered by hand — covered 22 of
     * ~208 models. The other 186 had no audit trail at all, and nothing about
     * adding a model prompted anyone to notice.
     *
     * The class list is resolved from the composer classmap when one exists
     * (production runs `composer install --optimize-autoloader`, so this is a
     * plain array read with no filesystem walk), and falls back to a directory
     * scan in development.
     *
     * config('audit.exclude') keeps the telemetry firehoses out; see the notes
     * there for why each is excluded.
     */
    private function registerAuditing(): void
    {
        if (! config('audit.enabled', true)) {
            return;
        }

        $excluded = array_flip((array) config('audit.exclude', []));

        foreach ($this->discoverModels() as $class) {
            if (isset($excluded[$class])) {
                continue;
            }

            $class::observe(\App\Observers\AuditObserver::class);
        }
    }

    /**
     * Every concrete Eloquent model under App\Models.
     *
     * A directory scan, memoised per process. The composer classmap would avoid
     * the filesystem work, but it only lists a class after `dump-autoload` has
     * run — so a model added today would go unaudited until someone happened to
     * regenerate it. A silent gap in the audit trail is a worse trade than one
     * walk of one directory per request, which the OS serves from cache anyway.
     *
     * @return array<int,class-string<\Illuminate\Database\Eloquent\Model>>
     */
    private function discoverModels(): array
    {
        static $models = null;

        return $models ??= $this->filterModels($this->scanModelFiles());
    }

    /**
     * @return array<int,string>
     */
    private function scanModelFiles(): array
    {
        $base = app_path('Models');
        $candidates = [];

        foreach (\Illuminate\Support\Facades\File::allFiles($base) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // app/Models/Attendance/BiotimeSource.php → App\Models\Attendance\BiotimeSource
            $relative = str_replace([$base.DIRECTORY_SEPARATOR, '.php'], '', $file->getPathname());

            $candidates[] = 'App\\Models\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
        }

        return $candidates;
    }

    /**
     * Keep only the concrete Eloquent models.
     *
     * @param  array<int,string>  $candidates
     * @return array<int,class-string<\Illuminate\Database\Eloquent\Model>>
     */
    private function filterModels(array $candidates): array
    {
        $models = [];

        foreach ($candidates as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract()
                || ! $reflection->isSubclassOf(\Illuminate\Database\Eloquent\Model::class)) {
                continue;
            }

            $models[] = $class;
        }

        return $models;
    }
}
