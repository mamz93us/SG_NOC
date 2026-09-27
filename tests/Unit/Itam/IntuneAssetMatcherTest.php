<?php

use App\Services\Itam\Oracle\ComputerFacts;
use App\Services\Itam\Oracle\IntuneAssetMatcher;
use Carbon\CarbonImmutable;

/**
 * Pairing a person's Oracle laptops with their Intune devices. What is
 * defended: a pair needs evidence, a different model or brand is never a
 * pair, and a guess is only made where nothing else could be meant.
 */
function oracleLaptop(string $description, string $purchased): ComputerFacts
{
    return ComputerFacts::fromOracle($description, CarbonImmutable::parse($purchased));
}

function intuneLaptop(string $manufacturer, string $model, ?string $enrolled, ?string $cpu = null, ?string $serial = null): ComputerFacts
{
    return ComputerFacts::fromIntune($manufacturer, $model, $cpu, $serial, $enrolled ? CarbonImmutable::parse($enrolled) : null);
}

it('links the same model enrolled after the purchase', function () {
    $result = (new IntuneAssetMatcher)->assign(
        [1 => oracleLaptop('(969K9ET)HP 250G10 i7-1355U 15 16GB/512 PC', '2025-07-31')],
        ['device:9' => intuneLaptop('HP', 'HP 250 15.6 inch G10 Notebook PC', '2025-08-12')],
    );

    expect($result['links'][1]['candidate'])->toBe('device:9')
        ->and($result['links'][1]['method'])->toBe(IntuneAssetMatcher::METHOD_MODEL);
});

it('never pairs a different model or a different brand', function () {
    $matcher = new IntuneAssetMatcher;

    expect($matcher->score(oracleLaptop('LENOVO ThinkPad E14 Gn2 (20TA00C0AD)', '2022-08-31'), intuneLaptop('LENOVO', '21KE000CAD', '2022-09-01')))->toBeNull()
        ->and($matcher->score(oracleLaptop('DELL Vostro 3510 - i5 - 8 GB', '2022-01-31'), intuneLaptop('HP', 'HP Laptop 15-fd0xxx', '2022-02-01')))->toBeNull();

    $result = $matcher->assign(
        [1 => oracleLaptop('LENOVO ThinkPad E14 Gn2 (20TA00C0AD)', '2022-08-31')],
        ['device:9' => intuneLaptop('LENOVO', '21KE000CAD', '2022-09-01')],
    );

    expect($result['links'])->toBe([]);
});

it('never pairs a laptop with a desktop, even as the holder\'s only machine of that brand', function () {
    // Found on NOC2's dry run: a ThinkPad P15v and a ThinkCentre M710q (machine type 10ML).
    $result = (new IntuneAssetMatcher)->assign(
        [1 => oracleLaptop('P15v,i7,16GB,512GB,nVIDIA 4GB', '2023-05-31')],
        ['device:9' => intuneLaptop('LENOVO', '10MLS4RX00', '2025-08-01')],
    );

    expect($result['links'])->toBe([])
        ->and((new IntuneAssetMatcher)->score(
            oracleLaptop('814730-DELL INS All in One 5490 i7 10510U- 16 GB -1 TB - 23.8"', '2020-06-30'),
            intuneLaptop('Dell Inc.', 'Inspiron 5490 AIO', '2020-07-10'),
        ))->not->toBeNull();
});

it('links on a serial number whatever else differs', function () {
    $result = (new IntuneAssetMatcher)->assign(
        [1 => oracleLaptop('NB SPEC X360 13-AW2002NX 17-11 5CD1281ZM1', '2021-06-30')],
        ['intune:4' => intuneLaptop('HP', 'HP Laptop 15-dw3xxx', '2024-01-01', null, '5CD1281ZM1')],
    );

    expect($result['links'][1]['method'])->toBe(IntuneAssetMatcher::METHOD_SERIAL);
});

it('does not link the same model with a different CPU on its own evidence', function () {
    $matcher = new IntuneAssetMatcher;
    $score = $matcher->score(
        oracleLaptop('HP UMA i7-1355U 250 G10 / 15.6 FHD', '2024-03-31'),
        intuneLaptop('HP', 'HP 250 15.6 inch G10 Notebook PC', '2024-04-10', '13th Gen Intel(R) Core(TM) i5-1335U'),
    );

    expect($score['strong'])->toBeFalse();
});

it('pairs the only laptop of a brand with the only Intune device of that brand', function () {
    $result = (new IntuneAssetMatcher)->assign(
        [1 => oracleLaptop('806202-Lenovo ideapad 3 - 15IML – core i5 – 10210U – 8GB – 1 TB- Nvidia', '2020-06-30')],
        ['device:9' => intuneLaptop('LENOVO', '81WB', '2022-01-15')],
    );

    expect($result['links'][1]['method'])->toBe(IntuneAssetMatcher::METHOD_ONLY_PAIR);
});

it('does not guess between two Intune devices of the same brand', function () {
    $result = (new IntuneAssetMatcher)->assign(
        [1 => oracleLaptop('806202-Lenovo ideapad 3 - 15IML – core i5', '2020-06-30')],
        ['device:9' => intuneLaptop('LENOVO', '81WB', '2022-01-15'), 'intune:3' => intuneLaptop('LENOVO', '82K1', '2023-03-01')],
    );

    expect($result['links'])->toBe([])
        ->and($result['suggestions'][1])->toHaveCount(2);
});

it('does not pair an old laptop with a much newer enrollment on brand alone', function () {
    $result = (new IntuneAssetMatcher)->assign(
        [1 => oracleLaptop('LAPTOP DELL INS Ci7 2.0 TURBO 2.9,6M 4GB', '2012-05-31')],
        ['device:9' => intuneLaptop('Dell Inc.', 'Vostro 15 3510', '2023-02-01')],
    );

    expect($result['links'])->toBe([]);
});

it('does not pair a laptop Intune had long before Oracle bought it', function () {
    $score = (new IntuneAssetMatcher)->score(
        oracleLaptop('DELL Vostro 3510 - i5', '2024-06-30'),
        intuneLaptop('Dell Inc.', 'Vostro 15 3510', '2022-01-10'),
    );

    expect($score['strong'])->toBeFalse();
});

it('gives each of two identical laptops the Intune device enrolled nearest its purchase', function () {
    $result = (new IntuneAssetMatcher)->assign(
        [
            1 => oracleLaptop('DELL Vostro 3510 - i5 - 8 GB', '2022-01-31'),
            2 => oracleLaptop('DELL Vostro 3510 - i5 - 8 GB', '2023-06-30'),
        ],
        [
            'device:8' => intuneLaptop('Dell Inc.', 'Vostro 15 3510', '2023-07-05'),
            'device:9' => intuneLaptop('Dell Inc.', 'Vostro 15 3510', '2022-02-03'),
        ],
    );

    expect($result['links'][1]['candidate'])->toBe('device:9')
        ->and($result['links'][2]['candidate'])->toBe('device:8');
});
