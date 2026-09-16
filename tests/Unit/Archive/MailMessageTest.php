<?php

use App\Services\Archive\Mail\MailMessage;

/**
 * Reading a scan out of an e-mail a copier sent.
 *
 * This reader is written by hand because there is nothing to use: no
 * mail-mime-parser, symfony/mime can only build messages, and no mailparse
 * extension. So every case a library would have handled is a case that has to be
 * pinned here instead — and the failure mode is silence, because a message whose
 * attachment is not found simply looks like a copier that never sent anything.
 *
 * The messages below are the shapes real machines produce: the Ricoh MP C3003's
 * multipart/mixed, a folded Content-Type whose boundary wraps onto the next line,
 * base64 broken at 76 columns, and nested multiparts from a driver that attaches
 * a body as alternative text.
 */
uses(Tests\TestCase::class);

function mailWith(string $body, string $boundary = 'SGNOC1'): string
{
    return implode("\r\n", [
        'Return-Path: <scanner@samirgroup.com>',
        'Delivered-To: u-abc123@scan.archive.samirgroup.net',
        'From: scanner@samirgroup.com',
        'To: u-abc123@scan.archive.samirgroup.net',
        'Subject: Scan from RICOH MP C3003',
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="'.$boundary.'"',
        '',
        $body,
    ]);
}

function attachmentPart(string $name, string $contents, string $boundary = 'SGNOC1'): string
{
    return implode("\r\n", [
        '--'.$boundary,
        'Content-Type: application/pdf; name="'.$name.'"',
        'Content-Disposition: attachment; filename="'.$name.'"',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($contents), 76, "\r\n"),
        '--'.$boundary.'--',
        '',
    ]);
}

// ─── Where it was sent ───────────────────────────────────────────

test('every address it was sent to is found', function () {
    $message = MailMessage::parse(mailWith(attachmentPart('scan.pdf', 'x')));

    // Delivered-To is what Postfix records and is the authoritative one, but a
    // copier configured with several destinations sends one message to all of
    // them and each is a separate inbox.
    expect($message->recipients())->toContain('u-abc123@scan.archive.samirgroup.net');
});

test('the sender is never what routing uses', function () {
    // The NOC relay rewrites every sender to one SES-verified identity, so From
    // says nothing about which copier sent this. It is read only for the record.
    $message = MailMessage::parse(mailWith(attachmentPart('scan.pdf', 'x')));

    expect($message->from())->toBe('scanner@samirgroup.com');
    expect($message->recipients())->not->toContain('scanner@samirgroup.com');
});

test('a Cc destination counts too', function () {
    $raw = implode("\r\n", [
        'To: u-one@scan.archive.samirgroup.net',
        'Cc: a-two@scan.archive.samirgroup.net, someone@samirgroup.com',
        'Content-Type: text/plain',
        '',
        'no attachment',
    ]);

    $recipients = MailMessage::parse($raw)->recipients();

    expect($recipients)->toContain('u-one@scan.archive.samirgroup.net');
    expect($recipients)->toContain('a-two@scan.archive.samirgroup.net');
});

test('a subject in an encoded word comes back readable', function () {
    $raw = implode("\r\n", [
        'To: u-abc123@scan.archive.samirgroup.net',
        'Subject: =?UTF-8?B?'.base64_encode('فاتورة').'?=',
        'Content-Type: text/plain',
        '',
        'body',
    ]);

    expect(MailMessage::parse($raw)->subject())->toBe('فاتورة');
});

// ─── Getting the file out ────────────────────────────────────────

test('a base64 attachment comes back byte for byte', function () {
    // The bytes are a PDF header: a scan that arrives corrupted is worse than one
    // that does not arrive, because it gets filed.
    $pdf = "%PDF-1.4\nsome scanned bytes\n%%EOF";

    $scans = MailMessage::parse(mailWith(attachmentPart('invoice.pdf', $pdf)))->attachments();

    expect($scans)->toHaveCount(1);
    expect($scans[0]['name'])->toBe('invoice.pdf');
    expect($scans[0]['bytes'])->toBe($pdf);
});

test('the message body is not mistaken for an attachment', function () {
    $body = implode("\r\n", [
        '--SGNOC1',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Scanned at reception.',
        '',
    ]).attachmentPart('scan.pdf', 'pdf bytes');

    $scans = MailMessage::parse(mailWith($body))->attachments();

    // A part with no filename is the note the copier wrote, not a document.
    expect($scans)->toHaveCount(1);
    expect($scans[0]['name'])->toBe('scan.pdf');
});

test('a folded Content-Type still yields its boundary', function () {
    // A long Content-Type wraps, and the boundary lands on the continuation line.
    // Miss the unfolding and every multipart message reads as having no parts.
    $raw = implode("\r\n", [
        'To: u-abc123@scan.archive.samirgroup.net',
        'Content-Type: multipart/mixed;',
        "\tboundary=\"----=_NextPart_000_0012_01D9ABCD.12345678\"",
        '',
        attachmentPart('folded.pdf', 'bytes', '----=_NextPart_000_0012_01D9ABCD.12345678'),
    ]);

    $scans = MailMessage::parse($raw)->attachments();

    expect($scans)->toHaveCount(1);
    expect($scans[0]['name'])->toBe('folded.pdf');
});

test('an attachment nested inside another multipart is still found', function () {
    // A driver that sends body-as-alternative wraps the scan one level deeper.
    $inner = attachmentPart('deep.pdf', 'deep bytes', 'INNER');

    $outer = implode("\r\n", [
        '--SGNOC1',
        'Content-Type: multipart/alternative; boundary="INNER"',
        '',
        $inner,
        '--SGNOC1--',
        '',
    ]);

    expect(MailMessage::parse(mailWith($outer))->attachments())->toHaveCount(1);
});

test('bare newlines parse the same as CRLF', function () {
    // Postfix stores CRLF, a copier may send bare LF, and a spool file touched by
    // hand can hold either.
    $crlf = mailWith(attachmentPart('scan.pdf', 'same bytes'));
    $lf = str_replace("\r\n", "\n", $crlf);

    expect(MailMessage::parse($lf)->attachments())->toEqual(MailMessage::parse($crlf)->attachments());
});

test('quoted-printable is decoded', function () {
    $raw = mailWith(implode("\r\n", [
        '--SGNOC1',
        'Content-Type: text/csv; name="list.csv"',
        'Content-Disposition: attachment; filename="list.csv"',
        'Content-Transfer-Encoding: quoted-printable',
        '',
        'total=3D12,500=2E50',
        '--SGNOC1--',
        '',
    ]));

    expect(MailMessage::parse($raw)->attachments()[0]['bytes'])->toContain('total=12,500.50');
});

// ─── Only what is a scan ─────────────────────────────────────────

test('scans are picked by extension, not by the declared type', function () {
    // Copiers lie about MIME routinely — a Ricoh sends PDFs as
    // application/octet-stream — and the archive probe found extensions honest
    // across every real file.
    $raw = mailWith(implode("\r\n", [
        '--SGNOC1',
        'Content-Type: application/octet-stream; name="invoice.pdf"',
        'Content-Disposition: attachment; filename="invoice.pdf"',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('pdf'),
        '--SGNOC1',
        'Content-Type: application/pdf; name="notes.txt"',
        'Content-Disposition: attachment; filename="notes.txt"',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('not a scan'),
        '--SGNOC1--',
        '',
    ]));

    $scans = MailMessage::parse($raw)->scans(['pdf', 'tif', 'tiff', 'jpg', 'jpeg', 'png']);

    expect($scans)->toHaveCount(1);
    expect($scans[0]['name'])->toBe('invoice.pdf');
});

test('an empty attachment is not an attachment', function () {
    $raw = mailWith(implode("\r\n", [
        '--SGNOC1',
        'Content-Disposition: attachment; filename="empty.pdf"',
        'Content-Transfer-Encoding: base64',
        '',
        '',
        '--SGNOC1--',
        '',
    ]));

    expect(MailMessage::parse($raw)->attachments())->toBe([]);
});

// ─── A filename off the network ──────────────────────────────────

test('a filename that tries to escape its directory cannot', function () {
    // This name reaches a blob path. It came from the network.
    $raw = mailWith(attachmentPart('../../etc/passwd', 'bytes'));

    $name = MailMessage::parse($raw)->attachments()[0]['name'];

    expect($name)->not->toContain('..');
    expect($name)->not->toContain('/');
    expect($name)->toBe('passwd');
});

test('a filename carrying markup is made safe', function () {
    $raw = mailWith(attachmentPart('<script>alert(1)</script>.pdf', 'bytes'));

    $name = MailMessage::parse($raw)->attachments()[0]['name'];

    expect($name)->not->toContain('<');
    expect($name)->not->toContain('>');
    expect($name)->toEndWith('.pdf');
});

// ─── Nonsense in, nothing out ────────────────────────────────────

test('a message that makes no sense has no attachments and does not throw', function () {
    // The spool will contain surprises. None of them may stop the run.
    expect(MailMessage::parse('')->attachments())->toBe([]);
    expect(MailMessage::parse('not a message at all')->attachments())->toBe([]);
    expect(MailMessage::parse("Content-Type: multipart/mixed; boundary=\"X\"\r\n\r\nno parts here")->attachments())->toBe([]);
    expect(MailMessage::parse('')->recipients())->toBe([]);
});
