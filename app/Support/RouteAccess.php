<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * Whether a user may open a named route, read from the route's own
 * `permission:` middleware.
 *
 * The admin menu asks this through `@canroute` instead of repeating a
 * permission slug beside each link. The two used to be typed separately and
 * drifted apart in both directions: links sat inside another permission's
 * block, so a role holding the page's own permission never saw them, and links
 * were shown to people the page then turned away. Reading the gate off the
 * route shows a link to exactly the people its page lets in.
 *
 * Same rules as EnsurePermission: every `permission:` entry on the route must
 * pass, one slug is enough within an entry (`permission:a,b` is an OR), and a
 * superuser passes all of them (User::hasPermission()).
 */
final class RouteAccess
{
    /**
     * Whether the user may open at least one of the named routes — how a menu
     * or a section decides whether it has anything to show.
     */
    public static function allowsAny(?User $user, string ...$names): bool
    {
        foreach ($names as $name) {
            if (self::allows($user, $name)) {
                return true;
            }
        }

        return false;
    }

    public static function allows(?User $user, string $name): bool
    {
        if (! $user) {
            return false;
        }

        $route = Router::getRoutes()->getByName($name);

        // Hidden rather than thrown: the menu renders on every admin page, and
        // one stale name must not take them all down. AdminMenuTest checks that
        // every name the menu asks about exists.
        if (! $route) {
            return false;
        }

        foreach (self::gates($route) as $slugs) {
            if (! collect($slugs)->contains(fn (string $slug) => $user->hasPermission($slug))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The route's permission gates. Each entry is one `permission:` middleware
     * and lists alternatives; the entries all have to pass.
     *
     * @return array<int, array<int, string>>
     */
    public static function gates(Route $route): array
    {
        $gates = [];

        foreach ($route->middleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                $gates[] = explode(',', substr($middleware, strlen('permission:')));
            }
        }

        return $gates;
    }
}
