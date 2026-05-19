<?php

use App\Models\Employee;
use App\Models\Training\Course;
use App\Models\Training\CourseCertificate;
use App\Services\Training\CertificateUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(CourseCertificate::DISK);
    $this->course = Course::create(['name' => 'Cyber Awareness 2026']);
    $this->service = new CertificateUploadService;
});

it('parses an email out of the filename', function () {
    expect($this->service->extractEmail('ahmed@samirgroup.com.pdf'))->toBe('ahmed@samirgroup.com');
    expect($this->service->extractEmail('AHMED@SAMIRGROUP.COM.JPG'))->toBe('ahmed@samirgroup.com');
    expect($this->service->extractEmail('not-an-email.pdf'))->toBeNull();
    expect($this->service->extractEmail('mixed.dots.email@samirgroup.com.pdf'))->toBe('mixed.dots.email@samirgroup.com');
});

it('links a certificate to the matching active employee', function () {
    $emp = Employee::create(['name' => 'Ahmed Saleh', 'email' => 'ahmed@samirgroup.com', 'status' => 'active']);

    $report = $this->service->handleUpload($this->course, [
        UploadedFile::fake()->create('ahmed@samirgroup.com.pdf', 100, 'application/pdf'),
    ]);

    expect($report['imported'])->toBe(1);
    expect($report['orphaned'])->toBe(0);

    $cert = CourseCertificate::first();
    expect($cert->employee_id)->toBe($emp->id);
    expect($cert->email)->toBe('ahmed@samirgroup.com');
    expect(strlen($cert->token))->toBe(64);
    Storage::disk(CourseCertificate::DISK)->assertExists($cert->file_path);
});

it('stores orphans when no employee matches', function () {
    $report = $this->service->handleUpload($this->course, [
        UploadedFile::fake()->create('unknown@samirgroup.com.pdf', 100, 'application/pdf'),
    ]);

    expect($report['imported'])->toBe(0);
    expect($report['orphaned'])->toBe(1);

    $cert = CourseCertificate::first();
    expect($cert->employee_id)->toBeNull();
    expect($cert->email)->toBe('unknown@samirgroup.com');
});

it('rejects files with unsupported extensions', function () {
    $report = $this->service->handleUpload($this->course, [
        UploadedFile::fake()->create('foo@samirgroup.com.exe', 10),
    ]);

    expect($report['rejected'])->toBe(1);
    expect(CourseCertificate::count())->toBe(0);
});

it('rejects files whose name is not an email', function () {
    $report = $this->service->handleUpload($this->course, [
        UploadedFile::fake()->create('not-an-email.pdf', 10, 'application/pdf'),
    ]);

    expect($report['rejected'])->toBe(1);
    expect(CourseCertificate::count())->toBe(0);
});

it('replaces the existing file but keeps the token on re-upload', function () {
    Employee::create(['name' => 'Ahmed', 'email' => 'ahmed@samirgroup.com', 'status' => 'active']);

    $first = $this->service->handleUpload($this->course, [
        UploadedFile::fake()->create('ahmed@samirgroup.com.pdf', 10, 'application/pdf'),
    ]);
    $cert = CourseCertificate::first();
    $cert->update(['sent_at' => now(), 'viewed_at' => now(), 'view_count' => 3]);
    $originalToken = $cert->token;

    // Re-upload with the same recipient but as JPG to also exercise the extension-change cleanup.
    $second = $this->service->handleUpload($this->course, [
        UploadedFile::fake()->create('ahmed@samirgroup.com.jpg', 10, 'image/jpeg'),
    ]);

    expect($second['replaced'])->toBe(1);
    expect(CourseCertificate::count())->toBe(1);

    $cert->refresh();
    expect($cert->token)->toBe($originalToken);
    expect($cert->sent_at)->toBeNull();
    expect($cert->viewed_at)->toBeNull();
    expect($cert->view_count)->toBe(0);
    expect(str_ends_with($cert->file_path, '.jpg'))->toBeTrue();
});
