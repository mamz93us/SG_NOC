<?php

namespace Database\Factories;

use App\Models\AccessVisit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessVisit>
 */
class AccessVisitFactory extends Factory
{
    protected $model = AccessVisit::class;

    public function definition(): array
    {
        $apps = ['noc', 'noc', 'noc', 'em', 'portal']; // weighted to NOC
        $branches = ['Jeddah', 'Riyadh', 'Al-Khobar', 'Abha', 'Cairo', 'unknown'];
        $browsers = ['Chrome', 'Edge', 'Firefox', 'Safari'];
        $platforms = ['Windows', 'Windows', 'macOS', 'Android', 'iOS'];
        $devices = ['desktop', 'desktop', 'desktop', 'mobile', 'tablet'];
        $uid = $this->faker->numberBetween(1, 25);

        return [
            'occurred_at' => $this->faker->dateTimeBetween('-30 days', 'now'),
            'user_id' => $uid,
            'user_name' => $this->faker->name(),
            'user_email' => 'user'.$uid.'@samirgroup.com',
            'app' => $this->faker->randomElement($apps),
            'event' => $this->faker->boolean(20) ? 'login' : 'access',
            'path' => '/admin',
            'ip_address' => $this->faker->ipv4(),
            'branch' => $this->faker->randomElement($branches),
            'user_agent' => $this->faker->userAgent(),
            'browser' => $this->faker->randomElement($browsers),
            'platform' => $this->faker->randomElement($platforms),
            'device_type' => $this->faker->randomElement($devices),
            'session_id' => $this->faker->sha1(),
        ];
    }
}
