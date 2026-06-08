<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public (no-auth) download endpoints so the one-line installer works on a
 * bare branch VM with no credentials:
 *
 *   curl -fsSL https://noc.samirgroup.net/branch-agent/install.sh | sudo bash
 *
 * These are read-only artifacts (the installer script and the prebuilt binary
 * + its checksum) — no secrets — so they sit outside the auth/permission
 * groups. The binary is produced by deployment/branch-agent/build.sh into
 * storage/app/branch-agent/.
 */
class BranchAgentDownloadController extends Controller
{
    /** The installer script (kept in the repo, always available). */
    public function install(): Response|BinaryFileResponse
    {
        $path = base_path('deployment/branch-agent/install.sh');
        if (! is_file($path)) {
            return response('installer not found', 404);
        }

        return response(file_get_contents($path), 200, [
            'Content-Type' => 'text/x-shellscript; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /** The prebuilt agent binary (uploaded by the release/build step). */
    public function binary(): StreamedResponse|Response
    {
        return $this->streamArtifact('sg-branch-agent', 'application/octet-stream');
    }

    /** Published SHA256 the installer verifies the binary against. */
    public function sha256(): StreamedResponse|Response
    {
        return $this->streamArtifact('sg-branch-agent.sha256', 'text/plain; charset=utf-8');
    }

    private function streamArtifact(string $name, string $contentType): StreamedResponse|Response
    {
        $path = storage_path('app/branch-agent/'.$name);
        if (! is_file($path)) {
            return response("artifact {$name} not built yet", 404);
        }

        return response()->stream(function () use ($path) {
            $fh = fopen($path, 'rb');
            fpassthru($fh);
            fclose($fh);
        }, 200, [
            'Content-Type' => $contentType,
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
