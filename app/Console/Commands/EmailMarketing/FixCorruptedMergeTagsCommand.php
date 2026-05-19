<?php

namespace App\Console\Commands\EmailMarketing;

use App\Models\EmailMarketing\EmailTemplate;
use Illuminate\Console\Command;

/**
 * One-shot fix for templates whose stored HTML / design JSON contains
 * Blade-compiled PHP echo tokens (a literal PHP open tag, then
 * "echo e(NAME);", then a PHP close tag) instead of the merge tag
 * `{{NAME}}`.
 *
 * Caused by an earlier bug in the template editor's "Available variables"
 * panel — Blade pre-processed literal {{tag}} pairs inside an array and
 * users copied the compiled PHP source into their email body.
 *
 * Idempotent — safe to re-run.
 */
class FixCorruptedMergeTagsCommand extends Command
{
    protected $signature = 'email-marketing:fix-merge-tags
                            {--dry-run : Show what would change without saving}';

    protected $description = 'Rewrite PHP-echo tokens in email templates back to {{NAME}} merge tags.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Build the PHP open and close tag tokens by concatenation so the
        // literal pair never appears in this source. PHP's lexer treats
        // the closing tag as a real PHP-close — even inside line comments
        // and string literals — which would otherwise break this file.
        $open  = '<'.'?'.'php';
        $close = '?'.'>';

        // Regex pattern matches: (open tag) echo e(NAME); (close tag).
        // Optional second arg in e(NAME, false); is also handled.
        $pattern = '/'
            .preg_quote($open, '/')
            .'\s+echo\s+e\(\s*([a-zA-Z0-9_.]+)\s*(?:,[^)]+)?\)\s*;\s*'
            .preg_quote($close, '/')
            .'/';

        // LIKE marker for the cheap "find templates that might be affected" query.
        $likeMarker = '%'.$open.' echo e(%';

        $touched = 0;
        $skipped = 0;

        EmailTemplate::query()
            ->where(function ($q) use ($likeMarker) {
                $q->where('rendered_html', 'like', $likeMarker)
                  ->orWhere('design_json', 'like', $likeMarker);
            })
            ->chunkById(50, function ($templates) use ($pattern, $dry, &$touched, &$skipped) {
                foreach ($templates as $t) {
                    $beforeHtml   = (string) $t->rendered_html;
                    $beforeDesign = (string) $t->design_json;
                    $afterHtml    = preg_replace($pattern, '{{$1}}', $beforeHtml)   ?? $beforeHtml;
                    $afterDesign  = preg_replace($pattern, '{{$1}}', $beforeDesign) ?? $beforeDesign;

                    if ($beforeHtml === $afterHtml && $beforeDesign === $afterDesign) {
                        $skipped++;
                        continue;
                    }

                    $this->line(sprintf(
                        '%s #%d "%s"',
                        $dry ? '[dry-run]' : 'fixed',
                        $t->id,
                        $t->name,
                    ));

                    if (! $dry) {
                        $t->update([
                            'rendered_html' => $afterHtml,
                            'design_json'   => $afterDesign,
                        ]);
                    }
                    $touched++;
                }
            });

        $this->info(($dry ? '[dry-run] Would fix ' : 'Fixed ').$touched.' template(s). '.$skipped.' already clean.');

        return Command::SUCCESS;
    }
}
