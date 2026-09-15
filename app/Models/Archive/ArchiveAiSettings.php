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
        'prompt_token_cost_usd',
        'completion_token_cost_usd',
    ];

    protected $casts = [
        'monthly_budget_usd' => 'decimal:2',
        'per_user_daily_pages' => 'integer',
        'page_read_cost_usd' => 'decimal:5',
        'prompt_token_cost_usd' => 'decimal:5',
        'completion_token_cost_usd' => 'decimal:5',
    ];

    /** Defaults in PHP as well as the database — see ArchiveSource::$attributes. */
    protected $attributes = [
        // Zero, deliberately: AI reads nothing until somebody decides what it
        // may spend. A default budget would be a decision made by whoever wrote
        // the migration rather than by whoever pays the bill.
        'monthly_budget_usd' => 0,
        'per_user_daily_pages' => 200,
        'page_read_cost_usd' => 0.01,
        // gpt-4o list prices per 1,000 tokens as of 2026-09. A starting point to
        // be corrected from a real invoice, not a claim about this tenancy.
        'prompt_token_cost_usd' => 0.0025,
        'completion_token_cost_usd' => 0.01,
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

    /**
     * What one conversation turn actually cost, from the tokens Azure reported.
     *
     * Measured rather than assumed. This used to be a flat half of a page's cost
     * for every call, which meant a six-turn tool loop over 40,000 characters of
     * scanned text recorded the same figure as a one-line question — understating
     * the month's spend, and doing it silently, while the cap that reads that
     * figure let spending carry on.
     */
    public function chatCost(int $promptTokens, int $completionTokens): float
    {
        $cost = (max(0, $promptTokens) / 1000) * (float) $this->prompt_token_cost_usd
            + (max(0, $completionTokens) / 1000) * (float) $this->completion_token_cost_usd;

        return round($cost, 5);
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
