<?php

use App\Services\Archive\ArcMate\ArcDesignParser;
use App\Services\Archive\ArcMate\ArcMateDates;
use App\Services\Archive\ArcMate\ArcMatePaths;

/**
 * The three pure pieces of reading ArcMate: its little config files, its text
 * dates, and where its files actually are.
 *
 * Every fixture here is copied from the live share or the live database rather
 * than invented, because the whole point of these classes is that ArcMate's
 * real shape is not what its schema suggests.
 *
 * No app boot and no database: these are functions.
 */

// ─── project.inf ─────────────────────────────────────────────────

test('the archive name is the second line of project.inf', function () {
    expect(ArcDesignParser::name("ArcMate Inf File ver 7\r\nSPS INVOICES\r\n"))->toBe('SPS INVOICES');
    expect(ArcDesignParser::name("ArcMate Inf File ver 7\r\nCESClinical_ServiceReports\r\n"))
        ->toBe('CESClinical_ServiceReports');
});

test('a project.inf with only the banner has no name', function () {
    expect(ArcDesignParser::name("ArcMate Inf File ver 7\r\n"))->toBeNull();
    expect(ArcDesignParser::name(''))->toBeNull();
});

// ─── project.config ──────────────────────────────────────────────

test('project.config gives the database and the encryption flag', function () {
    $xml = <<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <Project>
      <Project_Login>spsHR.htm</Project_Login>
      <DataBase_Type>2</DataBase_Type>
      <Server_Name>ArcMate\SQLEXPRESS</Server_Name>
      <Db_Name>AM7220130318_HR</Db_Name>
      <User_Name>sa</User_Name>
      <User_Pass>33,180,28,111,152,132,40,130,</User_Pass>
      <Encrypt>0</Encrypt>
      <Project_ID>20130318132318998</Project_ID>
    </Project>
    XML;

    $config = ArcDesignParser::config($xml);

    expect($config['database'])->toBe('AM7220130318_HR');
    expect($config['encrypted'])->toBeFalse();
    expect($config['project_id'])->toBe('20130318132318998');
});

test('the encrypted Test project is recognised', function () {
    // AM72PR20150503 — the only one of the twelve with Encrypt=1. Its files are
    // ciphertext only ArcMate can undo, so the portal must skip it rather than
    // sync documents nobody can open.
    $config = ArcDesignParser::config('<Project><Db_Name>AM72PR20150503</Db_Name><Encrypt>1</Encrypt></Project>');

    expect($config['encrypted'])->toBeTrue();
});

test('ArcMate sa credentials are never returned by the parser', function () {
    $xml = '<Project><Db_Name>X</Db_Name><User_Name>sa</User_Name><User_Pass>33,180,28,111,</User_Pass></Project>';

    expect(ArcDesignParser::config($xml))
        ->not->toHaveKey('username')
        ->not->toHaveKey('password');
});

test('a damaged config does not raise', function () {
    expect(ArcDesignParser::config('<Project><Db_Name>oops'))->toBe([
        'database' => null,
        'encrypted' => false,
        'login_page' => null,
        'project_id' => null,
    ]);
});

// ─── arcDesign.xml ───────────────────────────────────────────────

/** The real SPS_Invoices design: two text fields on S1 and S2. */
function invoicesDesign(): string
{
    return <<<'XML'
    <?xml version="1.0" encoding="utf-8"?>
    <Design>
      <Field>
        <Name>InvoiceNo</Name>
        <Size>20</Size>
        <Required>False</Required>
        <Caption>InvoiceNo</Caption>
        <DBName>S1</DBName>
        <Type>1</Type>
        <Unique>False</Unique>
        <UniquePerApplication>False</UniquePerApplication>
      </Field>
      <Field>
        <Name>PONo</Name>
        <Size>50</Size>
        <Required>False</Required>
        <Caption>PONo</Caption>
        <DBName>S2</DBName>
        <Type>1</Type>
        <Unique>False</Unique>
        <UniquePerApplication>False</UniquePerApplication>
      </Field>
    </Design>
    XML;
}

test('invoice fields map to their ArcMate columns', function () {
    $fields = ArcDesignParser::fields(invoicesDesign());

    expect($fields)->toHaveCount(2);
    expect($fields[0]['key'])->toBe('invoiceno');
    expect($fields[0]['label'])->toBe('InvoiceNo');
    expect($fields[0]['arcmate_column'])->toBe('S1');
    expect($fields[0]['type'])->toBe('text');
    expect($fields[0]['max_length'])->toBe(20);
    expect($fields[1]['arcmate_column'])->toBe('S2');
});

test('ArcMate field types become dates and lists', function () {
    // CESClinicalServReports: a date on D1 and a fixed list on C1.
    $xml = <<<'XML'
    <Design>
      <Field><Caption>ServiceReqDate</Caption><DBName>D1</DBName><Type>3</Type><Size>0</Size></Field>
      <Field><Caption>DocumentType</Caption><DBName>C1</DBName><Type>5</Type><Size>0</Size><Values /></Field>
      <Field><Caption>InstanceNo</Caption><DBName>S3</DBName><Type>1</Type><Size>20</Size></Field>
    </Design>
    XML;

    $fields = ArcDesignParser::fields($xml);

    expect($fields[0]['type'])->toBe('date');
    expect($fields[0]['max_length'])->toBeNull();
    expect($fields[1]['type'])->toBe('list');
    expect($fields[1]['options'])->toBe([]);
    expect($fields[2]['type'])->toBe('text');
});

test('required and unique are read from ArcMate', function () {
    $xml = <<<'XML'
    <Design>
      <Field><Caption>Supplier Name</Caption><DBName>S3</DBName><Type>1</Type><Size>250</Size>
        <Required>True</Required><Unique>False</Unique><UniquePerApplication>True</UniquePerApplication></Field>
    </Design>
    XML;

    $field = ArcDesignParser::fields($xml)[0];

    expect($field['key'])->toBe('supplier_name');
    expect($field['required'])->toBeTrue();
    expect($field['is_unique'])->toBeTrue();
});

test('two fields with the same caption still get distinct keys', function () {
    $xml = '<Design>'
        .'<Field><Caption>Date</Caption><DBName>D1</DBName><Type>3</Type></Field>'
        .'<Field><Caption>Date</Caption><DBName>D2</DBName><Type>3</Type></Field>'
        .'</Design>';

    $fields = ArcDesignParser::fields($xml);

    expect($fields[0]['key'])->toBe('date');
    expect($fields[1]['key'])->toBe('date_d2');
});

test('an unknown ArcMate type reads as text but keeps its number', function () {
    $field = ArcDesignParser::fields('<Design><Field><Caption>Odd</Caption><DBName>S9</DBName><Type>7</Type></Field></Design>')[0];

    expect($field['type'])->toBe('text');
    expect($field['arcmate_type'])->toBe(7);
});

// ─── dates ───────────────────────────────────────────────────────

test('ArcMate text timestamps parse as wall clock', function () {
    $moment = ArcMateDates::fromStamp('20260915144709');

    expect($moment)->not->toBeNull();
    // Read back exactly as written: no timezone was applied on the way in, and
    // none must be applied on the way out.
    expect($moment->format('Y-m-d H:i:s'))->toBe('2026-09-15 14:47:09');
});

test('an eight digit ArcMate date is midnight that day', function () {
    expect(ArcMateDates::fromStamp('20240301')->format('Y-m-d H:i:s'))->toBe('2024-03-01 00:00:00');
});

test('unusable ArcMate dates are null rather than a wrong moment', function () {
    expect(ArcMateDates::fromStamp(null))->toBeNull();
    expect(ArcMateDates::fromStamp(''))->toBeNull();
    expect(ArcMateDates::fromStamp('0'))->toBeNull();
    expect(ArcMateDates::fromStamp('00000000000000'))->toBeNull();
    // A real month of 13 — createFromFormat would roll this into 2027.
    expect(ArcMateDates::fromStamp('20261315144709'))->toBeNull();
});

test('the capture time comes from the stored file name', function () {
    // tblDocuments has no date column at all; the name ArcMate gives a file is
    // the only record of when the document was captured.
    $moment = ArcMateDates::fromFileName('D2026\\0915\\1440\\20260915144709220434.pdf');

    expect($moment->format('Y-m-d H:i:s'))->toBe('2026-09-15 14:47:09');
});

test('a file name without a timestamp has no capture time', function () {
    expect(ArcMateDates::fromFileName('P0000000001.msg'))->toBeNull();
    expect(ArcMateDates::fromFileName(null))->toBeNull();
});

// ─── where the files are ─────────────────────────────────────────

test('a stored name is relative to the project Documents folder', function () {
    // The live shape, from tblFiles: no drive, no Images/EDocs, no project.
    expect(ArcMatePaths::relative('D2026\\0915\\1440\\20260915144723220434.msg'))
        ->toBe('D2026/0915/1440/20260915144723220434.msg');
});

test('an absolute path left over from the old server is cut down', function () {
    expect(ArcMatePaths::relative('E:\\ArcRepositories\\SPS_Invoices\\Documents\\Images\\D2019\\0101\\1100\\x.pdf'))
        ->toBe('D2019/0101/1100/x.pdf');
});

test('scans resolve under Images and attachments under EDocs', function () {
    $pdf = ArcMatePaths::candidates('/mnt/arcmate', 'SPS_Invoices', 'D2026/0915/1440/x.pdf');
    $msg = ArcMatePaths::candidates('/mnt/arcmate', 'SPS_Invoices', 'D2026/0915/1440/x.msg');

    expect($pdf[0])->toBe('/mnt/arcmate/SPS_Invoices/Documents/Images/D2026/0915/1440/x.pdf');
    expect($msg[0])->toBe('/mnt/arcmate/SPS_Invoices/Documents/EDocs/D2026/0915/1440/x.msg');
});

test('the other directory is still tried, because the type is only a guess', function () {
    $candidates = ArcMatePaths::candidates('/mnt/arcmate', 'SPS_Invoices', 'D2026/0915/1440/x.pdf');

    expect($candidates)->toHaveCount(2);
    expect($candidates[1])->toContain('/Documents/EDocs/');
});

test('resolve returns the file that is actually there', function () {
    $real = '/mnt/arcmate/SPS_Invoices/Documents/EDocs/D2020/0107/0920/x.zip';

    // A .zip is an "electronic document", so EDocs is tried first anyway; the
    // point here is that resolve() answers with the path that exists.
    $found = ArcMatePaths::resolve('/mnt/arcmate', 'SPS_Invoices', 'D2020/0107/0920/x.zip',
        fn (string $path) => $path === $real);

    expect($found)->toBe($real);
});

test('a scan that is filed under EDocs is still found', function () {
    $real = '/mnt/arcmate/SPS_Deliv_Notes/Documents/EDocs/D2021/0415/1500/scan.pdf';

    $found = ArcMatePaths::resolve('/mnt/arcmate', 'SPS_Deliv_Notes', 'D2021/0415/1500/scan.pdf',
        fn (string $path) => $path === $real);

    expect($found)->toBe($real);
});

test('a missing file resolves to null but still has a best guess', function () {
    $found = ArcMatePaths::resolve('/mnt/arcmate', 'SPS_Invoices', 'D2013/0323/1410/gone.pdf', fn () => false);

    expect($found)->toBeNull();
    expect(ArcMatePaths::bestGuess('/mnt/arcmate', 'SPS_Invoices', 'D2013/0323/1410/gone.pdf'))
        ->toBe('/mnt/arcmate/SPS_Invoices/Documents/Images/D2013/0323/1410/gone.pdf');
});

test('an empty name or project resolves to nothing', function () {
    expect(ArcMatePaths::candidates('/mnt/arcmate', 'SPS_Invoices', ''))->toBe([]);
    expect(ArcMatePaths::candidates('/mnt/arcmate', '', 'D2026/0915/1440/x.pdf'))->toBe([]);
});
