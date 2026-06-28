<?php

namespace Database\Factories;

use App\Models\TicketVisit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TicketVisit>
 */
class TicketVisitFactory extends Factory
{
    protected $model = TicketVisit::class;

    public function definition(): array
    {
        $branches = ['Jeddah', 'Riyadh', 'Al-Khobar', 'Abha', 'Cairo', 'unknown'];
        $browsers = ['Chrome', 'Edge', 'Firefox', 'Safari', 'Opera'];
        $platforms = ['Windows', 'macOS', 'Android', 'iOS', 'Linux'];
        $devices = ['desktop', 'desktop', 'desktop', 'mobile', 'tablet']; // weighted to desktop

        $visitedAt = $this->faker->dateTimeBetween('-30 days', 'now');

        return [
            'visited_at' => $visitedAt,
            'ip_address' => $this->faker->ipv4(),
            'branch' => $this->faker->randomElement($branches),
            'user_agent' => $this->faker->userAgent(),
            'browser' => $this->faker->randomElement($browsers),
            'platform' => $this->faker->randomElement($platforms),
            'device_type' => $this->faker->randomElement($devices),
            'referrer' => $this->faker->optional(0.3)->url(),
            'session_id' => Str::random(40),
            'is_unique_today' => $this->faker->boolean(40),
            'country' => null,
            'city' => null,
        ];
    }
}
