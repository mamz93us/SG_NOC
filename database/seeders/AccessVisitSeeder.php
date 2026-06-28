<?php

namespace Database\Seeders;

use App\Models\AccessVisit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo access data so the Access Analytics dashboard can be shown without
 * waiting for real sign-ins. Run with:
 *   php artisan db:seed --class=AccessVisitSeeder
 *
 * Shapes a realistic pattern: a pool of recurring employees, each with a sticky
 * app/branch/device, signing in then producing heartbeats through the workday.
 */
class AccessVisitSeeder extends Seeder
{
    public function run(): void
    {
        $branches = ['Jeddah', 'Riyadh', 'Al-Khobar', 'Abha', 'Cairo'];
        $browsers = ['Chrome', 'Edge', 'Firefox', 'Safari'];
        $platforms = ['Windows', 'Windows', 'macOS', 'Android', 'iOS'];

        $users = collect(range(1, 25))->map(fn ($i) => [
            'id' => $i,
            'name' => fake()->name(),
            'email' => 'user'.$i.'@samirgroup.com',
            'app' => fake()->randomElement(['noc', 'noc', 'noc', 'em', 'portal']),
            'branch' => fake()->randomElement($branches),
            'browser' => fake()->randomElement($browsers),
            'platform' => fake()->randomElement($platforms),
            'device' => fake()->randomElement(['desktop', 'desktop', 'desktop', 'mobile', 'tablet']),
            'ip' => fake()->ipv4(),
        ]);

        $rows = [];
        $now = CarbonImmutable::now();

        for ($daysAgo = 30; $daysAgo >= 0; $daysAgo--) {
            $day = $now->subDays($daysAgo);
            $isWeekend = in_array($day->dayOfWeek, [5, 6], true);

            foreach ($users as $u) {
                // Each user signs in on ~70% of weekdays, ~15% of weekends.
                if (fake()->boolean($isWeekend ? 15 : 70) === false) {
                    continue;
                }

                $loginHour = random_int(7, 10);
                $login = $day->setTime($loginHour, random_int(0, 59));
                $rows[] = $this->row($u, 'login', $login);

                // A handful of heartbeats through the day.
                $beats = random_int(2, 9);
                for ($b = 0; $b < $beats; $b++) {
                    $at = $login->addMinutes(random_int(5, 540));
                    if ($at->greaterThan($now)) {
                        continue;
                    }
                    $rows[] = $this->row($u, 'access', $at);
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            AccessVisit::insert($chunk);
        }
    }

    private function row(array $u, string $event, CarbonImmutable $at): array
    {
        return [
            'occurred_at' => $at,
            'user_id' => $u['id'],
            'user_name' => $u['name'],
            'user_email' => $u['email'],
            'app' => $u['app'],
            'event' => $event,
            'path' => $u['app'] === 'em' ? '/' : '/admin',
            'ip_address' => $u['ip'],
            'branch' => $u['branch'],
            'user_agent' => "Mozilla/5.0 ({$u['platform']}) {$u['browser']}",
            'browser' => $u['browser'],
            'platform' => $u['platform'],
            'device_type' => $u['device'],
            'session_id' => substr(md5($u['id'].$at->format('Ymd')), 0, 40),
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }
}
