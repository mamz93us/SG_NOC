<?php

namespace App\Console\Commands;

use App\Models\EmailSignatureTemplate;
use Illuminate\Console\Command;

/**
 * Repairs signature templates pasted from Word/Outlook so their logos render for
 * every recipient. Word paste injects: (a) file:// local-path images inside VML
 * conditional-comment blocks, (b) a junk base64 GIF placeholder, and (c) relative
 * image paths — all of which show as broken images in real mail clients.
 *
 * This strips the Word VML blocks and repoints every non-absolute <img> at the
 * hosted NOC logo for the template's domain. Dry-run by default; --apply writes.
 */
class SignaturesFixImages extends Command
{
    protected $signature = 'signatures:fix-images {--apply : Write the cleaned HTML}';

    protected $description = 'Strip Word image junk and repoint signature logos to hosted NOC URLs';

    /** Absolute, publicly-reachable NOC logo per sending domain. */
    private const LOGO_BY_DOMAIN = [
        'sssegypt.com'   => 'https://noc.samirgroup.net/images/signatures/template-2-logo.png',
        'samirgroup.com' => 'https://noc.samirgroup.net/images/worldcup/samir_group_logo.png',
    ];

    private const DEFAULT_LOGO = 'https://noc.samirgroup.net/images/worldcup/samir_group_logo.png';

    public function handle(): int
    {
        $apply   = (bool) $this->option('apply');
        $touched = 0;

        foreach (EmailSignatureTemplate::all() as $t) {
            $orig = $t->html_body;
            $html = $orig;

            // 1. Drop Word junk that carries file:// refs and only renders in ancient Outlook:
            //    conditional-comment blocks, raw VML <v:shape> blocks, and stray
            //    v:/o:/w:/m: namespaced tags + downlevel <![if]> markers.
            $html = preg_replace('/<!--\[if[^\]]*\]>.*?<!\[endif\]-->/is', '', $html);
            $html = preg_replace('/<v:shape\b[^>]*>.*?<\/v:shape>/is', '', $html);
            $html = preg_replace('/<\/?(?:v|o|w|m):[a-z0-9]+\b[^>]*>/i', '', $html);
            $html = preg_replace('/<!\[if[^\]]*\]>|<!\[endif\]>/i', '', $html);

            // 2. Repoint every <img> whose src is NOT an absolute http(s) URL to the
            //    hosted logo for this domain (fixes junk GIFs, file://, and relative paths).
            $logo    = self::LOGO_BY_DOMAIN[$t->domain] ?? self::DEFAULT_LOGO;
            $changed = [];
            $html    = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($logo, &$changed) {
                $img = $m[0];
                if (preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $img, $s)) {
                    $src = $s[2];
                    if (! preg_match('#^https?://#i', $src)) {
                        $changed[] = mb_strimwidth($src, 0, 46, '…');
                        $img = preg_replace('/\bsrc\s*=\s*(["\']).*?\1/i', 'src="'.$logo.'"', $img, 1);
                    }
                } else {
                    $changed[] = '(no src)';
                    $img = preg_replace('/<img\b/i', '<img src="'.$logo.'"', $img, 1);
                }

                return $img;
            }, $html);

            if ($html === $orig) {
                $this->line("#{$t->id} {$t->name} — no change");

                continue;
            }

            $touched++;
            $this->info("#{$t->id} {$t->name} ({$t->domain}) -> {$logo}");
            foreach ($changed as $c) {
                $this->line("      replaced: {$c}");
            }
            $this->line('      size: '.strlen($orig).' -> '.strlen($html).' bytes');

            if ($apply) {
                $t->html_body = $html;
                $t->save();
            }
        }

        $this->newLine();
        $this->info(($apply ? 'Applied to ' : 'Would change ')."{$touched} template(s).");
        if (! $apply) {
            $this->line('Re-run with --apply to write.');
        }

        return self::SUCCESS;
    }
}
