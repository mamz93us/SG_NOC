<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class WorldCupFetchFlags extends Command
{
    protected $signature = 'worldcup:fetch-flags {--force : Re-download flags that already exist}';

    protected $description = 'Download World Cup team flag images into public/'.'images/flags so they are served from NOC';

    public function handle(): int
    {
        $teams    = config('worldcup.teams', []);
        $relPath  = trim((string) config('worldcup.flag_path', 'images/flags'), '/');
        $template = (string) config('worldcup.remote_template', 'https://flagcdn.com/w160/{code}.png');
        $dir      = public_path($relPath);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Could not create directory: {$dir}");

            return self::FAILURE;
        }

        $downloaded = 0;
        $skipped    = 0;
        $failed     = 0;

        foreach ($teams as $team) {
            $code = $team['code'] ?? null;
            if (! $code) {
                continue;
            }

            $dest = $dir.DIRECTORY_SEPARATOR.$code.'.png';

            if (file_exists($dest) && ! $this->option('force')) {
                $skipped++;

                continue;
            }

            $url = str_replace('{code}', $code, $template);

            try {
                $response = Http::timeout(20)->get($url);

                if ($response->successful() && strlen($response->body()) > 0) {
                    file_put_contents($dest, $response->body());
                    $this->line("  <info>✓</info> {$team['name']} ({$code})");
                    $downloaded++;
                } else {
                    $this->line("  <error>✗</error> {$team['name']} ({$code}) — HTTP {$response->status()}");
                    $failed++;
                }
            } catch (\Throwable $e) {
                $this->line("  <error>✗</error> {$team['name']} ({$code}) — {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Flags: {$downloaded} downloaded, {$skipped} already present, {$failed} failed → {$dir}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
