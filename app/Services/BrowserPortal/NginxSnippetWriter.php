<?php

namespace App\Services\BrowserPortal;

use App\Models\BrowserSession;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Renders /etc/nginx/sites-dynamic/{session_id}.conf from a template, then
 * tests and reloads Nginx via sudo. If nginx -t fails after a write we roll
 * the file back to whatever was there before (or delete it if new).
 */
class NginxSnippetWriter
{
    protected string $templatePath;
    protected string $snippetsDir = '/etc/nginx/sites-dynamic';
    protected string $nginxBin = '/usr/sbin/nginx';

    public function __construct()
    {
        $this->templatePath = base_path('deployment/browser-portal/nginx/session-template.conf');
    }

    public function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * Write the per-session snippet and reload Nginx.
     * Throws RuntimeException if nginx -t rejects the result.
     */
    public function write(BrowserSession $session): void
    {
        if (empty($session->internal_ip)) {
            throw new \RuntimeException("Cannot write nginx snippet: session {$session->session_id} has no internal_ip yet");
        }

        $template = @file_get_contents($this->templatePath);
        if ($template === false) {
            throw new \RuntimeException("Missing nginx session template at {$this->templatePath}");
        }

        $rendered = strtr($template, [
            '{{SESSION_ID}}'   => $session->session_id,
            '{{CONTAINER_IP}}' => $session->internal_ip,
        ]);

        $target = "{$this->snippetsDir}/{$session->session_id}.conf";

        if ($this->isWindows()) {
            Log::info('NginxSnippetWriter (Windows mock): would write snippet', [
                'target' => $target,
                'bytes'  => strlen($rendered),
            ]);
            return;
        }

        $previous = file_exists($target) ? file_get_contents($target) : null;

        if (@file_put_contents($target, $rendered) === false) {
            throw new \RuntimeException("Failed to write nginx snippet at $target — check perms on {$this->snippetsDir}");
        }

        try {
            $this->testAndReload();
        } catch (\Throwable $e) {
            if ($previous === null) {
                @unlink($target);
            } else {
                @file_put_contents($target, $previous);
            }
            throw $e;
        }
    }

    /**
     * Remove the snippet and reload Nginx. Silent if the file was already gone.
     */
    public function remove(string $sessionId): void
    {
        $target = "{$this->snippetsDir}/{$sessionId}.conf";

        if ($this->isWindows()) {
            Log::info('NginxSnippetWriter (Windows mock): would remove snippet', ['target' => $target]);
            return;
        }

        if (file_exists($target)) {
            @unlink($target);
            $this->testAndReload();
        }
    }

    protected function testAndReload(): void
    {
        $test = new Process(['sudo', $this->nginxBin, '-t']);
        $test->setTimeout(10);
        $test->run();

        if (!$test->isSuccessful()) {
            $err = trim($test->getErrorOutput() ?: $test->getOutput());
            Log::error('NginxSnippetWriter: nginx -t failed', ['error' => $err]);
            throw new \RuntimeException("nginx -t rejected config: $err");
        }

        $reload = new Process(['sudo', $this->nginxBin, '-s', 'reload']);
        $reload->setTimeout(10);
        $reload->run();

        if (!$reload->isSuccessful()) {
            $err = trim($reload->getErrorOutput() ?: $reload->getOutput());
            Log::error('NginxSnippetWriter: nginx reload failed', ['error' => $err]);
            throw new \RuntimeException("nginx reload failed: $err");
        }
    }
}
