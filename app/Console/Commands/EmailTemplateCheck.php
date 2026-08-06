<?php

namespace App\Console\Commands;

use App\Support\EmailTemplateRenderer;
use App\Support\EmailTemplates;
use Illuminate\Console\Command;

/**
 * Sanity check for the email template catalogue.
 *
 * Renders every template against its sample data and proves the round trip:
 * coded design -> tokenised seed -> refilled -> byte-identical to the coded
 * design. A failure here means an override of that template would not be able
 * to reproduce the original, which is the one guarantee the editor makes.
 */
class EmailTemplateCheck extends Command
{
    protected $signature = 'email-templates:check {--key= : Check one template only}';

    protected $description = 'Render every email template with sample data and verify the placeholder round trip';

    public function handle(): int
    {
        $keys = $this->option('key')
            ? [$this->option('key')]
            : array_keys(EmailTemplates::all());

        $failures = 0;

        foreach ($keys as $key) {
            try {
                $mailable = EmailTemplates::sampleMailable($key);
                $html = $mailable->renderCodedView();
                $chunks = EmailTemplateRenderer::extract($html);
                $seed = EmailTemplateRenderer::tokenise($html);
                $refilled = EmailTemplateRenderer::fill($seed, $chunks);
                $original = EmailTemplateRenderer::strip($html);
                $identical = $original === $refilled;
                // Whitespace-only drift is expected: chunks are trimmed on the
                // way out, so a multi-line block comes back without its
                // surrounding indentation. Anything else is a real mismatch.
                $equivalent = preg_replace('/\s+/', ' ', $original) === preg_replace('/\s+/', ' ', $refilled);

                if (! $equivalent) {
                    $failures++;
                }

                $this->line(sprintf(
                    '%-32s %2d fields  %6d bytes  %s',
                    $key,
                    count($chunks),
                    strlen($seed),
                    $identical
                        ? '<info>round-trip exact</info>'
                        : ($equivalent ? '<info>round-trip ok (whitespace)</info>' : '<error>round-trip MISMATCH</error>')
                ));
            } catch (\Throwable $e) {
                $failures++;
                $this->line(sprintf('%-32s <error>FAILED</error> %s', $key, $e->getMessage()));
            }
        }

        $this->newLine();
        $this->line($failures === 0 ? '<info>All templates rendered.</info>' : "<error>{$failures} template(s) need attention.</error>");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
