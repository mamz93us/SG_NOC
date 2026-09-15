<?php

namespace App\Models\Archive;

use Illuminate\Database\Eloquent\Model;

/**
 * What AI over the archive is allowed to cost.
 *
 * One row. The budget is global rather than per archive on purpose: "how much
 * may this spend a month" has exactly one answer, and splitting it per archive
 * is how a cap gets quietly exceeded three times over.
 *
 * `page_read_cost_usd` is keyed in rather than fetched, for the same reason the
 * exchange rates are (see AI_SUBSCRIPTIONS.md): an estimate shown before
 * somebody commits to reading 40,000 pages has to still mean the same thing
 * when they come back to check it.
 *
 * Audited like any other settings row — changing a budget is a decision.
 */
class ArchiveAiSettings extends Model
{
    protected $table = 'archive_ai_settings';

    protected $fillable = [
        'monthly_budget_usd',
        'per_user_daily_pages',
        'page_read_cost_usd',
    ];

    protected $casts = [
        'monthly_budget_usd' => 'decimal:2',
        'per_user_daily_pages' => 'integer',
        'page_read_cost_usd' => 'decimal:5',
    ];

    /** Defaults in PHP as well as the database — see ArchiveSource::$attributes. */
    protected $attributes = [
        // Zero, deliberately: AI reads nothing until somebody decides what it
        // may spend. A default budget would be a decision made by whoever wrote
        // the migration rather than by whoever pays the bill.
        'monthly_budget_usd' => 0,
        'per_user_daily_pages' => 200,
        'page_read_cost_usd' => 0.01,
    ];

    public static function get(): self
    {
        return static::query()->first() ?? static::create([]);
    }

    public function pageCost(): float
    {
        return (float) $this->page_read_cost_usd;
    }

    /** What reading this many pages would cost, for the estimate before a batch. */
    public function estimateFor(int $pages): float
    {
        return round($this->pageCost() * max(0, $pages), 2);
    }

    public function budget(): float
    {
        return (float) $this->monthly_budget_usd;
    }

    /**
     * Whether there is budget left this month.
     *
     * A zero budget means AI is off, not unlimited. That is the safer reading
     * of "nobody has set this yet".
     */
    public function withinBudget(float $additional = 0.0): bool
    {
        $budget = $this->budget();

        if ($budget <= 0) {
            return false;
        }

        return (ArchiveAiUsage::spentThisMonth() + $additional) < $budget;
    }

    public function remainingBudget(): float
    {
        return max(0, $this->budget() - ArchiveAiUsage::spentThisMonth());
    }
}
