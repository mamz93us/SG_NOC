<?php

/**
 * Production runs no long-lived queue worker: the only one is the scheduled
 * `queue:work --stop-when-empty` in routes/console.php (see CLAUDE.md,
 * "Scheduler-as-worker"). `queue:work` with no --queue reads `default` only,
 * so a job dispatched onto any other queue is written to `jobs` and never run
 * — and nothing reports a failure, because the job never starts.
 *
 * That is what stopped offboarding ever unenrolling a laptop from Intune:
 * RemoveIntuneDevicesJob ships on `offboarding`. This test reads the onQueue()
 * calls out of app/ and fails the moment one names a queue the drainer does
 * not drain.
 */
/** The repo root, read straight off this file: the guard is about files on disk, so it must not need the app booted. */
function queueDrainerRepoRoot(): string
{
    return dirname(__DIR__, 3);
}

it('drains every queue the app dispatches onto', function () {
    $console = file_get_contents(queueDrainerRepoRoot().'/routes/console.php');

    expect($console)->toContain('queue:work');
    preg_match("/'(queue:work[^']*)'/", $console, $command);
    expect($command)->not->toBeEmpty('the scheduled queue:work command should be a plain string');

    preg_match('/--queue=([A-Za-z0-9_,\-]+)/', $command[1], $queues);
    $drained = $queues ? explode(',', $queues[1]) : ['default'];

    $used = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(queueDrainerRepoRoot().'/app'));
    foreach ($files as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }
        if (preg_match_all("/onQueue\(\s*'([A-Za-z0-9_\-]+)'/", file_get_contents($file->getPathname()), $matches)) {
            $used = array_merge($used, $matches[1]);
        }
    }

    $used = array_values(array_unique($used));
    sort($used);

    expect($used)->not->toBeEmpty()
        ->and(array_values(array_diff($used, $drained)))->toBe(
            [],
            'these queues are dispatched onto but never drained: '.implode(', ', array_diff($used, $drained))
        );
});

it('keeps the drainer short-lived, in the background and non-overlapping', function () {
    $console = file_get_contents(queueDrainerRepoRoot().'/routes/console.php');

    preg_match("/Schedule::command\('queue:work.*?->name\('queue-drainer'\);/s", $console, $block);
    expect($block)->not->toBeEmpty();

    // A worker that never exits would outlive the minute it started in, and two
    // of them would race; CLAUDE.md requires both of these on a slow task.
    expect($block[0])->toContain('--stop-when-empty')
        ->and($block[0])->toContain('->runInBackground()')
        ->and($block[0])->toContain('->withoutOverlapping(');
});
