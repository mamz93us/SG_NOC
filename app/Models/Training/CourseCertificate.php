<?php

namespace App\Models\Training;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CourseCertificate extends Model
{
    protected $fillable = [
        'course_id',
        'employee_id',
        'email',
        'file_path',
        'file_mime',
        'original_filename',
        'file_size',
        'token',
        'sent_at',
        'viewed_at',
        'view_count',
        'created_by',
    ];

    protected $casts = [
        'sent_at'    => 'datetime',
        'viewed_at'  => 'datetime',
        'view_count' => 'integer',
        'file_size'  => 'integer',
    ];

    public const DISK = 'azure_certificates';

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publicUrl(): string
    {
        return route('certificates.show', ['token' => $this->token]);
    }

    public function downloadUrl(): string
    {
        return route('certificates.download', ['token' => $this->token]);
    }

    public function isOrphaned(): bool
    {
        return $this->employee_id === null;
    }

    public static function generateToken(): string
    {
        // 64 hex chars from 32 random bytes — same width / entropy
        // as the unsubscribe / opt-in tokens used elsewhere.
        return bin2hex(random_bytes(32));
    }

    public function suggestedDownloadName(): string
    {
        $ext = pathinfo($this->original_filename ?? $this->file_path, PATHINFO_EXTENSION) ?: 'pdf';
        $courseName = $this->course?->name ?? 'Certificate';
        $slug = Str::slug($courseName) ?: 'certificate';

        return $slug.'.'.strtolower($ext);
    }
}
