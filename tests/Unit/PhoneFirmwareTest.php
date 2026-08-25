<?php

use App\Models\PhoneFirmware;
use App\Services\Phone\FirmwarePublisher;

uses(Tests\TestCase::class);

/**
 * The ZIP unpacker is the only part of the firmware server with no existing
 * analogue in the repo, and it is the part that takes an untrusted archive from
 * a vendor download page — so it carries the tests. Everything here is
 * DB-free on purpose: the Feature suite errors under the SQLite :memory:
 * config because of MySQL-only migrations.
 */
function makeZip(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'fwtest_').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();

    return $path;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/fwtest_*') as $leftover) {
        @unlink($leftover);
    }
});

it('keeps only the .bin images from a vendor package and preserves their names', function () {
    $zip = makeZip([
        'Release_GRP260X_1.0.13.59/grp2601fw.bin' => 'IMAGE-A',
        'Release_GRP260X_1.0.13.59/grp2602fw.bin' => 'IMAGE-B',
        'Release_GRP260X_1.0.13.59/release_notes.pdf' => 'not firmware',
        'Release_GRP260X_1.0.13.59/md5sum.txt' => 'not firmware',
    ]);

    $images = (new FirmwarePublisher)->extractImages($zip, 'grp260x_1.0.13.59.zip');

    $names = array_column($images, 'filename');
    sort($names);

    expect($names)->toBe(['grp2601fw.bin', 'grp2602fw.bin']);
    expect(file_get_contents($images[0]['path']))->toBeIn(['IMAGE-A', 'IMAGE-B']);

    foreach ($images as $image) {
        @unlink($image['path']);
        @rmdir(dirname($image['path']));
    }
    @unlink($zip);
});

it('refuses an archive whose entry walks outside the extraction directory', function () {
    $zip = makeZip(['../../../../tmp/evil.bin' => 'PWNED']);

    expect(fn () => (new FirmwarePublisher)->extractImages($zip, 'evil.zip'))
        ->toThrow(RuntimeException::class, 'unsafe entry path');

    expect(file_exists(sys_get_temp_dir().'/evil.bin'))->toBeFalse();

    @unlink($zip);
});

it('refuses an absolute entry path', function () {
    $zip = makeZip(['/etc/cron.d/evil.bin' => 'PWNED']);

    expect(fn () => (new FirmwarePublisher)->extractImages($zip, 'evil.zip'))
        ->toThrow(RuntimeException::class, 'unsafe entry path');

    @unlink($zip);
});

it('treats backslash separators and drive letters as unsafe too', function () {
    $publisher = new FirmwarePublisher;

    expect($publisher->isUnsafeEntry('a\\..\\..\\b.bin'))->toBeTrue();
    expect($publisher->isUnsafeEntry('C:\\windows\\evil.bin'))->toBeTrue();
    expect($publisher->isUnsafeEntry('Release_GRP260X/grp2601fw.bin'))->toBeFalse();
});

it('rejects an archive that carries no firmware image at all', function () {
    $zip = makeZip(['readme.txt' => 'nothing to see']);

    expect(fn () => (new FirmwarePublisher)->extractImages($zip, 'empty.zip'))
        ->toThrow(RuntimeException::class, 'No .bin firmware image');

    @unlink($zip);
});

it('never renames a vendor image, because the phone asks for that exact name', function () {
    $publisher = new FirmwarePublisher;

    expect($publisher->safeBinName('grp2601fw.bin'))->toBe('grp2601fw.bin');
    expect($publisher->safeBinName('sub/dir/grp2616fw.bin'))->toBe('grp2616fw.bin');
    expect(fn () => $publisher->safeBinName('notfirmware.pdf'))->toThrow(RuntimeException::class);
});

it('guesses the model and version the way the vendor names things', function () {
    $publisher = new FirmwarePublisher;

    expect($publisher->guessModel('grp2601fw.bin'))->toBe('GRP2601');
    expect($publisher->guessModel('gxp1780fw.bin'))->toBe('GXP1780');
    expect($publisher->guessVersion('grp260x_1.0.13.59.zip'))->toBe('1.0.13.59');
    expect($publisher->guessVersion('no-version-here.zip'))->toBeNull();
});

it('matches phone models exactly or by prefix wildcard', function () {
    $firmware = new PhoneFirmware(['model' => 'GRP2601', 'covers' => 'GRP2601*, GRP2602']);

    expect($firmware->coversModel('GRP2601'))->toBeTrue();
    expect($firmware->coversModel('GRP2601W'))->toBeTrue();   // wildcard
    expect($firmware->coversModel('grp2602'))->toBeTrue();    // case-insensitive
    expect($firmware->coversModel('GRP2603'))->toBeFalse();
    expect($firmware->coversModel(null))->toBeFalse();
});

it('falls back to the primary model when no covers list was given', function () {
    $firmware = new PhoneFirmware(['model' => 'GXP1780', 'covers' => null]);

    expect($firmware->coversModel('GXP1780'))->toBeTrue();
    expect($firmware->coversModel('GXP1782'))->toBeFalse();
});
