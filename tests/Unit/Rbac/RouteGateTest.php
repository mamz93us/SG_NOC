<?php

use App\Models\RolePermission;
use App\Support\RouteAccess;
use Illuminate\Support\Facades\Route;

/**
 * Every admin page is opened by a permission a role can be given, and by that
 * permission alone.
 *
 * Until 2026-09-16 a role could hold a page's permission and still be turned
 * away, three ways: a gate on a slug with no checkbox (see
 * RolePermission::unregisteredSlugs()); about 110 pages nested inside the
 * manage-settings route group, so they needed that permission as well; and
 * pages that only manage-settings opened, with nothing of their own to grant.
 */
uses(Tests\TestCase::class);

function adminRoutesForGateTest(): Illuminate\Support\Collection
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => $route->uri() === 'admin' || str_starts_with($route->uri(), 'admin/'));
}

it('asks only for permissions a role can be given', function () {
    $registered = RolePermission::allSlugs();

    $unregistered = collect(Route::getRoutes()->getRoutes())
        ->flatMap(fn ($route) => collect(RouteAccess::gates($route))->flatten()
            ->reject(fn (string $slug) => in_array($slug, $registered, true))
            ->map(fn (string $slug) => "{$slug} on {$route->uri()}"))
        ->unique()
        ->values()
        ->all();

    expect($unregistered)->toBe([]);
});

it('gates every admin page with a permission', function () {
    // Pages that belong to whoever is signed in rather than to a permission.
    $personal = [
        'admin.dashboard',
        'admin.my-printers',
        'admin.notifications.index', 'admin.notifications.settings', 'admin.notifications.settings.update',
        'admin.notifications.unread-count', 'admin.notifications.read', 'admin.notifications.read-all',
        'admin.profile.password',
        'admin.quick-links.store', 'admin.quick-links.destroy',
        'admin.toggle-dark-mode',
        'admin.two-factor.setup', 'admin.two-factor.confirm', 'admin.two-factor.disable',
        // Superusers only, checked in TwoFactorController.
        'admin.two-factor.preview',
        // The phone directory, which /phonebook.xml serves to anyone.
        'admin.xml.preview',
    ];

    $ungated = adminRoutesForGateTest()
        ->filter(fn ($route) => RouteAccess::gates($route) === [])
        ->map(fn ($route) => $route->getName() ?? implode('|', $route->methods()).' '.$route->uri())
        ->reject(fn (string $name) => in_array($name, $personal, true))
        ->values()
        ->all();

    expect($ungated)->toBe([]);
});

it('never makes a page with its own permission need manage-settings as well', function () {
    $stacked = collect(Route::getRoutes()->getRoutes())
        ->filter(function ($route) {
            $gates = RouteAccess::gates($route);

            return count($gates) > 1 && in_array(['manage-settings'], $gates, true);
        })
        ->map(fn ($route) => $route->uri())
        ->values()
        ->all();

    expect($stacked)->toBe([]);
});
