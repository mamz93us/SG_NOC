<?php

use App\Support\RouteAccess;
use Illuminate\Support\Facades\Route;

/**
 * The admin menu shows each link to exactly the people its page lets in.
 *
 * Links sit in @canroute blocks, which read the permission gates off the named
 * routes. These checks keep the blocks honest as the menu changes. A link must
 * not be shown to someone its page turns away (Alert Rules was shown to every
 * NOC viewer and needs manage-noc), nor sit inside a block that someone who may
 * open the page does not pass (attendance, tickets and announcements sat inside
 * manage-settings blocks and were hidden from the roles that held them).
 */
uses(Tests\TestCase::class);

function adminMenu(): string
{
    $blade = file_get_contents(resource_path('views/layouts/admin.blade.php'));
    $start = strpos($blade, '<!-- NAVBAR -->');
    $end = strpos($blade, 'Notification Bell');

    if ($start === false || $end === false) {
        throw new RuntimeException('Cannot find the menu bar in layouts/admin.blade.php');
    }

    return substr($blade, $start, $end - $start);
}

/** @return array<int, array<int, string>> */
function menuGates(string $name): array
{
    $route = Route::getRoutes()->getByName($name);

    return $route ? RouteAccess::gates($route) : [];
}

/**
 * Whether everyone who passes gates $a also passes gates $b: each gate of $b
 * has a gate in $a whose every alternative it accepts.
 */
function gatesImply(array $a, array $b): bool
{
    foreach ($b as $accepted) {
        $covered = false;

        foreach ($a as $alternatives) {
            if (array_diff($alternatives, $accepted) === []) {
                $covered = true;
                break;
            }
        }

        if (! $covered) {
            return false;
        }
    }

    return true;
}

/**
 * Each route() link in the menu, with the @canroute blocks around it,
 * outermost first.
 *
 * @return array<int, array{name: string, blocks: array<int, array<int, string>>}>
 */
function menuLinks(): array
{
    preg_match_all("/@canroute\\(([^)]*)\\)|@endcanroute|(?<![\\w>:])route\\(\\s*'([^']+)'/", adminMenu(), $tokens, PREG_SET_ORDER);

    $stack = [];
    $links = [];

    foreach ($tokens as $token) {
        if ($token[0] === '@endcanroute') {
            if (array_pop($stack) === null) {
                throw new RuntimeException('@endcanroute without a matching @canroute');
            }
        } elseif (str_starts_with($token[0], '@canroute(')) {
            preg_match_all("/'([^']+)'/", $token[1], $names);
            $stack[] = $names[1];
        } else {
            $links[] = ['name' => $token[2], 'blocks' => $stack];
        }
    }

    if ($stack !== []) {
        throw new RuntimeException(count($stack).' @canroute block(s) never closed');
    }

    return $links;
}

it('names only routes that exist', function () {
    preg_match_all('/@canroute\(([^)]*)\)/', adminMenu(), $blocks);

    // A missing name in @canroute silently hides its block; one in a link's
    // route() throws on every admin page whose menu shows it.
    $names = collect($blocks[1])
        ->flatMap(fn (string $args) => preg_match_all("/'([^']+)'/", $args, $m) ? $m[1] : [])
        ->merge(collect(menuLinks())->pluck('name'))
        ->unique()
        ->values();

    expect($names)->not->toBeEmpty();
    expect($names->reject(fn (string $name) => Route::has($name))->values()->all())->toBe([]);
});

it('never shows a link to someone its page turns away', function () {
    $problems = [];

    foreach (menuLinks() as $link) {
        $gates = menuGates($link['name']);

        if ($gates === []) {
            continue; // a page for anyone signed in
        }

        $innermost = end($link['blocks']) ?: [];

        if ($innermost === [] || collect($innermost)->contains(fn (string $name) => ! Route::has($name) || ! gatesImply(menuGates($name), $gates))) {
            $problems[] = $link['name'].($innermost === [] ? ' (no @canroute around it)' : " inside @canroute('".implode("', '", $innermost)."')");
        }
    }

    expect($problems)->toBe([]);
});

it('never hides a link from someone its page lets in', function () {
    $problems = [];

    foreach (menuLinks() as $link) {
        $gates = menuGates($link['name']);

        if ($gates === []) {
            continue;
        }

        foreach ($link['blocks'] as $block) {
            if (! collect($block)->contains(fn (string $name) => Route::has($name) && gatesImply($gates, menuGates($name)))) {
                $problems[] = $link['name']." inside @canroute('".implode("', '", $block)."')";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('links admin pages by route name, not a typed path', function () {
    expect(adminMenu())->not->toMatch('#href="/admin/#');
});
