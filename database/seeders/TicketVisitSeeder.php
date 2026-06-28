<?php

namespace Database\Seeders;

use App\Models\TicketVisit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Generates demo ticket visits so the dashboard can be shown without waiting
 * for real traffic. Run with: php artisan db:seed --class=TicketVisitSeeder
 *
 * Produces a realistic shape: business-hours weighting, a handful of recurring
 * "employee" sessions (repeat visitors), and a branch mix.
 */
class TicketVisitSeeder extends Seeder
{
    public function run(): void
    {
        $branches = ['Jeddah', 'Riyadh', 'Al-Khobar', 'Abha', 'Cairo', 'unknown'];
        $browsers = ['Chrome', 'Edge', 'Firefox', 'Safari'];
        $platforms = ['Windows', 'Windows', 'macOS', 'Android', 'iOS'];

        // A pool of recurring visitors (sticky session + branch + device).
        $visitors = collect(range(1, 60))->map(fn () => [
            'session_id' => Str::random(40),
            'ip' => fake()->ipv4(),
            'branch' => fake()->randomElement($branches),
            'browser' => fake()->randomElement($browsers),
            'platform' => fake()->randomElement($platforms),
            'device' => fake()->randomElement(['desktop', 'desktop', 'desktop', 'mobile', 'tablet']),
        ]);

        $rows = [];
        $now = CarbonImmutable::now();

        for ($daysAgo = 30; $daysAgo >= 0; $daysAgo--) {
            $day = $now->subDays($daysAgo);
            // Fewer hits on weekends (Fri=5, Sat=6 locally).
            $isWeekend = in_array($day->dayOfWeek, [5, 6], true);
            $hits = $isWeekend ? random_int(3, 12) : random_int(20, 70);

            $seenToday = [];
            for ($i = 0; $i < $hits; $i++) {
                $v = $visitors->random();
                $hour = $this->businessHour();
                $at = $day->setTime($hour, random_int(0, 59), random_int(0, 59));

                $unique = ! isset($seenToday[$v['session_id']]);
                $seenToday[$v['session_id']] = true;

                $rows[] = [
                    'visited_at' => $at,
                    'ip_address' => $v['ip'],
                    'branch' => $v['branch'],
                    'user_agent' => "Mozilla/5.0 ({$v['platform']}) {$v['browser']}",
                    'browser' => $v['browser'],
                    'platform' => $v['platform'],
                    'device_type' => $v['device'],
                    'referrer' => null,
                    'session_id' => $v['session_id'],
                    'is_unique_today' => $unique,
                    'country' => null,
                    'city' => null,
                    'created_at' => $at,
                    'updated_at' => $at,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            TicketVisit::insert($chunk);
        }
    }

    /** Bias toward 8:00–18:00. */
    private function businessHour(): int
    {
        return random_int(0, 9) < 8 ? random_int(8, 18) : random_int(0, 23);
    }
}
