<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employee's KnowBe4 security-awareness figures.
 *
 * Only ever shown to the person it describes — see the migration. Nothing here
 * should acquire a "list everyone's scores" query.
 */
class Knowbe4Score extends Model
{
    protected $table = 'knowbe4_scores';

    protected $fillable = [
        'kb4_user_id', 'email', 'employee_id',
        'risk_score', 'phish_fail_count', 'phish_sent_count',
        'trainings_completed', 'trainings_outstanding',
        'status', 'last_phish_failed_at', 'synced_at',
    ];

    protected $casts = [
        'risk_score' => 'float',
        'phish_fail_count' => 'integer',
        'phish_sent_count' => 'integer',
        'trainings_completed' => 'integer',
        'trainings_outstanding' => 'integer',
        'last_phish_failed_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * KnowBe4 risk is 0–100 where HIGHER IS WORSE. The card colours off this,
     * so getting the direction backwards would tell a careful person they are
     * the problem — hence the explicit banding rather than an inline ternary.
     */
    public function riskBand(): string
    {
        $score = $this->risk_score;

        if ($score === null) {
            return 'unknown';
        }

        return match (true) {
            $score < 25 => 'low',
            $score < 50 => 'medium',
            default => 'high',
        };
    }

    public function riskClass(): string
    {
        return match ($this->riskBand()) {
            'low' => 'risk-low',
            'medium' => 'risk-medium',
            'high' => 'risk-high',
            default => '',
        };
    }

    public function riskLabel(): string
    {
        return $this->risk_score === null
            ? '—'
            : rtrim(rtrim(number_format($this->risk_score, 1), '0'), '.');
    }
}
