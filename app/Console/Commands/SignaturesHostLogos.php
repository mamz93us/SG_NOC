<?php

namespace App\Console\Commands;

use App\Models\EmailSignatureTemplate;
use Illuminate\Console\Command;

/**
 * Extracts base64-embedded logos from signature templates and rewrites them to
 * hosted NOC URLs (public/images/signatures). Embedded base64 bloats the HTML
 * (~20KB) which exceeds the Exchange transport-rule disclaimer size limit — a
 * hosted <img src="url"> keeps it small (~5KB) and renders identically.
 *
 * Idempotent: templates already using URLs are skipped.
 */
class SignaturesHostLogos extends Command
{
    protected $signature = 'signatures:host-logos';

    protected $description = 'Move base64-embedded signature logos to hosted NOC URLs';

    public function handle(): int
    {
        $dir = public_path('images/signatures');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $base    = rtrim(config('app.url'), '/');
        $touched = 0;

        foreach (EmailSignatureTemplate::all() as $template) {
            $html = $template->html_body;

            if (! preg_match_all('/data:image\/(\w+);base64,([A-Za-z0-9+\/=]+)/', $html, $matches, PREG_SET_ORDER)) {
                continue;
            }

            $i = 0;
            foreach ($matches as $m) {
                $bin = base64_decode($m[2], true);
                if ($bin === false || $bin === '') {
                    continue;
                }
                $i++;
                $ext  = strtolower($m[1]) === 'jpeg' ? 'jpg' : preg_replace('/[^a-z0-9]/', '', strtolower($m[1]));
                $name = "template-{$template->id}-logo".($i > 1 ? "-{$i}" : '').".{$ext}";

                file_put_contents("{$dir}/{$name}", $bin);
                $url  = "{$base}/images/signatures/{$name}";
                $html = str_replace($m[0], $url, $html);

                $this->info("Template #{$template->id}: {$name} (".strlen($bin)." bytes) -> {$url}");
            }

            $template->html_body = $html;
            $template->save();
            $touched++;
        }

        $this->info($touched > 0 ? "Rewrote {$touched} template(s)." : 'No embedded base64 logos found.');

        return self::SUCCESS;
    }
}
