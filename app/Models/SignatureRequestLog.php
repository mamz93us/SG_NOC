<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per call to the signature API — the audit trail of who fetched a
 * signature from NOC and what was served. Written by SignatureController;
 * read by the Signature Request Log admin page. Insert-only (no updated_at).
 */
class SignatureRequestLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'upn',
        'endpoint',
        'type',
        'domain',
        'gender',
        'template_id',
        'template_name',
        'resolved_name',
        'status',
        'ip',
        'api_key_id',
        'user_agent',
    ];

    public function template()
    {
        return $this->belongsTo(EmailSignatureTemplate::class, 'template_id');
    }

    public function apiKey()
    {
        return $this->belongsTo(HrApiKey::class, 'api_key_id');
    }
}
