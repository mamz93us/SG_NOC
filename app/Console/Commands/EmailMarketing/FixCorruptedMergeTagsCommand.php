<?php

namespace App\Console\Commands\EmailMarketing;

use App\Models\EmailMarketing\EmailTemplate;
use Illuminate\Console\Command;

/**
 * One-shot fix for templates whose rendered_html / design_json contains
 * Blade-compiled PHP echo tokens like `<?php echo e(first_name); ?>`
 * instead of the literal merge tag `{{first_name}}`.
 *
 * Caused by an earlier bug where the template editor's "Available variables"
 * panel embedded the literal {{tag}} pairs in a Blade-parsed array. Blade
 * pre-processed them, so clicking the badge to copy gave the user PHP
 * source which they pasted into the email body.
 *
 * Idempotent — safe to re-run.
 */
class FixCorruptedMergeTagsCommand extends Command
{
    protected $signature = 'email-marketing:fix-merge-tags
                            {--dry-run : Show what would change without saving}';

    protected $description = 'Replace <?php echo e(NAME); ?> tokens in email templates with proper {{NAME}} merge tags.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Match both common Blade outputs:
        //   <?php echo e(first_name); ?>
        //   <?php echo e(first_name, false); ?>
        $pattern = '/<\?php\s+echo\s+e\(\s*([a-zA-Z0-9_.]+)\s*(?:,[^)]+)?\)\s*;\s*\?>/';

        $touched = 0;
        $skipped = 0;

        EmailTemplate::query()
            ->where(function ($q) {
                $q->where('rendered_html', 'like', '%<?php echo e(%')
                  ->orWhere('design_json', 'like', '%<?php echo e(%');
            })
            ->chunkById(50, function ($templates) use ($pattern, $dry, &$touched, &$skipped) {
                foreach ($templates as $t) {
                    $before = [
                        'rendered_html' => $t->rendered_html,
                        'design_json'   => $t->design_json,
                    ];
                    $after = [
                        'rendered_html' => preg_replace($pattern, '{{$1}}', $before['rendered_html'] ?? '') ?? $before['rendered_html'],
                        'design_json'   => preg_replace($pattern, '{{$1}}', $before['design_json'] ?? '') ?? $before['design_json'],
                    ];

                    if ($before === $after) {
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
                        $t->update($after);
                    }
                    $touched++;
                }
            });

        $this->info(($dry ? '[dry-run] Would fix' : 'Fixed').' '.$touched.' template(s). '.$skipped.' already clean.');

        return Command::SUCCESS;
    }
}
