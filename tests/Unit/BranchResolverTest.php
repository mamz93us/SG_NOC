<?php

use App\Services\Ticketing\BranchResolver;
use App\Support\UserAgentParser;

// Pure logic — no DB, so this runs even when the SQLite migration set is unhappy.

$map = [
    'Jeddah' => ['10.10.0.0/16'],
    'Riyadh' => ['10.20.0.0/16'],
    'Al-Khobar' => ['10.30.0.0/16', '192.168.30.0/24'],
    'Cairo' => ['2001:db8:abcd::/48'],
];

it('resolves an IPv4 address to the right branch', function () use ($map) {
    $resolver = new BranchResolver($map);

    expect($resolver->resolve('10.10.5.42'))->toBe('Jeddah');
    expect($resolver->resolve('10.20.255.1'))->toBe('Riyadh');
    expect($resolver->resolve('192.168.30.7'))->toBe('Al-Khobar');
});

it('falls back to unknown when no CIDR matches', function () use ($map) {
    $resolver = new BranchResolver($map);

    expect($resolver->resolve('8.8.8.8'))->toBe(BranchResolver::UNKNOWN);
    expect($resolver->resolve('10.99.0.1'))->toBe(BranchResolver::UNKNOWN);
});

it('returns unknown for empty or invalid input', function () use ($map) {
    $resolver = new BranchResolver($map);

    expect($resolver->resolve(null))->toBe(BranchResolver::UNKNOWN);
    expect($resolver->resolve(''))->toBe(BranchResolver::UNKNOWN);
    expect($resolver->resolve('not-an-ip'))->toBe(BranchResolver::UNKNOWN);
});

it('matches IPv6 CIDRs and does not cross address families', function () use ($map) {
    $resolver = new BranchResolver($map);

    expect($resolver->resolve('2001:db8:abcd:1::5'))->toBe('Cairo');
    // An IPv4 address must never match an IPv6 subnet (and vice versa).
    expect($resolver->ipInCidr('10.10.0.1', '2001:db8:abcd::/48'))->toBeFalse();
});

it('honours boundary masks (/32 and /31)', function () {
    $resolver = new BranchResolver([]);

    expect($resolver->ipInCidr('10.0.0.1', '10.0.0.1/32'))->toBeTrue();
    expect($resolver->ipInCidr('10.0.0.2', '10.0.0.1/32'))->toBeFalse();
    expect($resolver->ipInCidr('10.0.0.0', '10.0.0.1/31'))->toBeTrue();
});

it('parses common user agents into browser/platform/device', function () {
    $chrome = UserAgentParser::parse(
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'
    );
    expect($chrome['browser'])->toBe('Chrome');
    expect($chrome['platform'])->toBe('Windows');
    expect($chrome['device_type'])->toBe('desktop');

    $iphone = UserAgentParser::parse(
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'
    );
    expect($iphone['platform'])->toBe('iOS');
    expect($iphone['device_type'])->toBe('mobile');
});

it('flags bots from the configured needle list', function () {
    $needles = ['bot', 'curl', 'pingdom'];

    expect(UserAgentParser::isBot('Googlebot/2.1', $needles))->toBeTrue();
    expect(UserAgentParser::isBot('curl/8.4.0', $needles))->toBeTrue();
    expect(UserAgentParser::isBot('Mozilla/5.0 Chrome/120', $needles))->toBeFalse();
});
