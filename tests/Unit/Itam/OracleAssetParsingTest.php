<?php

use App\Services\Itam\Oracle\AssetLineKind;
use App\Services\Itam\Oracle\ComputerFacts;

/**
 * Reading Oracle's free-text asset names. Every description here is a real
 * line from the September 2026 register ("assets 7-2026.xlsx").
 */
it('keeps laptops and desktops and leaves licences and monitors out', function (string $description, ?string $kind) {
    expect(AssetLineKind::of($description))->toBe($kind);
})->with([
    'Windows OEM licence' => ['OEM MS WINDOWS 11 PRO - 64 bit - ENGLISH', null],
    'Office licence' => ['708844- MS OFFICE HOME and BUSINESS 2019 English Mac & Win', null],
    'Salesforce subscription' => ['Sales Cloud - Enterprise Edition (15-Jan-2026 - 14-Jan-2027)', null],
    'monitor' => ['NEC EA261WM 26" LCD BLK CN', null],
    'laptop' => ['(969K9ET)HP 250G10 i7-1355U 15 16GB/512 PC Intel i7-1355U / 15.6" FHD AG LED SVA', 'laptop'],
    'laptop with a licence bundled' => ['DELL 5459-I7-6500U 6TH GEN-1TB-14HD+OEM MS WINDOWS 10PRO+OFFICE 2016 ENGLISH', 'laptop'],
    'laptop that names Windows' => ['ThinkBook Intel Core Ultra 7 155H 32GB 2TB Windows 11 Pro', 'laptop'],
    'laptop sold with a monitor' => ['TOSHIBA C850-B819 15.4" MONITOR', 'laptop'],
    'tower' => ['DELL 9020 OPTILEX CORE I7-4770-1GB 500 GB - DVD 3.4', 'desktop'],
    'all-in-one called a laptop' => ['Laptop HP I7-13700T 24-CA2000MX SERIAL NUMBER 8CC333157P', 'desktop'],
    'iMac' => ['Apple iMac 24-INCH M1 CHIP WITH 8-CORE CPU 2 PORTS 8GB RAM-256GB', 'desktop'],
    'Mac mini' => ['Mac mini M4 512Gb, 16 GB for office use', 'desktop'],
    'Inspiron typed as OptiPlex' => ["DELL OPTILEX N5520 I7 3612QM 6GB 15.6'", 'laptop'],
    'nothing but a shape' => ['W8PRO/CI74TH GEN/8GB/256GB/INT', 'laptop'],
]);

it('reads the brand, model keys and CPU from a description', function (string $description, ?string $brand, array $keys, ?string $cpu) {
    $facts = ComputerFacts::fromOracle($description, null);

    expect($facts->brand)->toBe($brand)
        ->and($facts->modelKeys)->toBe($keys)
        ->and($facts->cpu)->toBe($cpu);
})->with([
    ['LENOVO ThinkPad E14 Gn2 (20TA00C0AD) - i7 - 16 GB - 512GB SSD - Nvidia MX450 2GB', 'LENOVO', ['LEN:20TA'], null],
    ['20VE00TAD-TB 15.8G8.512GB,SSD,16.6" WIN 10 PRO ,3 YEAR CARRY-IN MINERAL GREY', 'LENOVO', ['LEN:20VE'], null],
    ['21KE005DADX1 2-in-1 U7-155U 32GB B', 'LENOVO', ['LEN:21KE'], 'ULTRA 7 155U'],
    ['(969K9ET)HP 250G10 i7-1355U 15 16GB/512 PC Intel i7-1355U / 15.6" FHD AG LED SVA', 'HP', ['HPG:250-G10'], 'I7-1355U'],
    ['HP Laptop 15 fd0027nx- (8N2B8EA) - Core i7 - 1355U - 16GB- 512GB SSD - 15.6"FHD', 'HP', ['HP:15-FD0'], 'I7-1355U'],
    ['HP N/B 15-DW3002NX  CI7 BLACK SERIAL NO: 0195908482246 FOR  Mohammed Abdul Mukta', 'HP', ['HP:15-DW3'], null],
    ['DELL Vostro 3510 -  i5 - 8 GB - 512 GB SSD -NVIDIA GeForce MX350 2GB - Black - 1', 'DELL', ['DELL:3510'], null],
    ['Dell Latitude E3500 / 8th Gen Intel Core i5-8265U Processor (6M Cache , up to 3.', 'DELL', ['DELL:3500'], 'I5-8265U'],
    ['XPS 13 Plus 9320 Laptop', 'DELL', ['DELL:9320'], null],
    ['HT-210ANUYBNSLV – NB TCH XPS 13 I7-8550U 1.8GHZ', 'DELL', [], 'I7-8550U'],
]);

it('does not take a GPU, a disk speed or a CPU number for a Dell model', function () {
    expect(ComputerFacts::fromOracle('DELL Gaming Laptop G15 5511 - i7 - 32 GB - 512 GB - GeForce RTX 3050 4GB', null)->modelKeys)->toBe(['DELL:5511'])
        ->and(ComputerFacts::fromOracle('DELL 9020 OPTILEX CORE I7-4770-4GB 500GB', null)->modelKeys)->toBe(['DELL:9020'])
        ->and(ComputerFacts::fromOracle('DELL LAPTOP 1TB 5400 RPM', null)->modelKeys)->toBe([])
        ->and(ComputerFacts::fromOracle('HP Pavilion 14- CI7-4500U (1.80 GHz) 4CPUs', null)->modelKeys)->toBe([]);
});

it('reads Intune model strings into the same keys', function (string $manufacturer, string $model, string $brand, array $keys) {
    $facts = ComputerFacts::fromIntune($manufacturer, $model, null, null, null);

    expect($facts->brand)->toBe($brand)->and($facts->modelKeys)->toBe($keys);
})->with([
    ['LENOVO', '20TA00C0AD', 'LENOVO', ['LEN:20TA']],
    ['LENOVO', '83EM', 'LENOVO', ['LEN:83EM']],
    ['Hewlett-Packard', 'HP 250 15.6 inch G10 Notebook PC', 'HP', ['HPG:250-G10']],
    ['HP', 'HP 250R 15.6 inch G10 Notebook PC', 'HP', ['HPG:250-G10']],
    ['HP', 'HP Laptop 15-fd0xxx', 'HP', ['HP:15-FD0']],
    ['Dell Inc.', 'Vostro 15 3510', 'DELL', ['DELL:3510']],
    ['Dell Inc.', 'Latitude 3500', 'DELL', ['DELL:3500']],
]);

it('proves a form only where the text says it', function (string $side, string $text, ?string $form) {
    $facts = $side === 'oracle'
        ? ComputerFacts::fromOracle($text, null)
        : ComputerFacts::fromIntune(...explode('|', $text), ...[null, null, null]);

    expect($facts->form)->toBe($form);
})->with([
    ['oracle', 'P15v,i7,16GB,512GB,nVIDIA 4GB', 'laptop'],
    ['oracle', 'DELL 9020 OPTILEX CORE I7-4770-4GB 500GB', 'desktop'],
    ['oracle', 'W8PRO/CI74TH GEN/8GB/256GB/INT', null],
    ['intune', 'LENOVO|10MLS4RX00', 'desktop'],
    ['intune', 'LENOVO|21DJ', 'laptop'],
    ['intune', 'HP|HP Pavilion All-in-One Desktop 24-ca2xxx', 'desktop'],
    ['intune', 'Hewlett-Packard|HP 250 15.6 inch G10 Notebook PC', 'laptop'],
    ['intune', 'Dell Inc.|Vostro 3591', null],
]);

it('reads a CPU the way the NOC device script reports it', function () {
    expect(ComputerFacts::cpuOf('13th Gen Intel(R) Core(TM) i7-1355U'))->toBe('I7-1355U')
        ->and(ComputerFacts::cpuOf('Intel(R) Core(TM) Ultra 7 155H'))->toBe('ULTRA 7 155H')
        ->and(ComputerFacts::cpusAgree('I7-1165', 'I7-1165G7'))->toBeTrue()
        ->and(ComputerFacts::cpusAgree('I7-1255U', 'I7-1355U'))->toBeFalse()
        ->and(ComputerFacts::cpusAgree(null, 'I7-1355U'))->toBeNull();
});

it('compares HP, Dell and Lenovo on model keys only, not on family names', function () {
    // "x360" and "E14" name families that span generations.
    expect(ComputerFacts::fromOracle('818S3EA#A2N UMA i7-1355U x360 G10/14/1TB/Win/1yw/3yw', null)->looseKeys)->toBe([])
        ->and(ComputerFacts::fromOracle('LENOVO ThinkPad E14 Gn2 (20TA00C0AD)', null)->looseKeys)->toBe([])
        ->and(ComputerFacts::fromOracle('LAPTOP NB GP78HX-13VH I9-13980HX 1.6G', null)->looseKeys)->toBe(['GP78HX']);
});
