<?php

use App\Models\BrowserSession;
use App\Services\BrowserPortal\DockerClient;
use App\Services\BrowserPortal\NginxSnippetWriter;
use App\Services\BrowserPortal\SessionManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Boot the Laravel app (facades, Eloquent) but NOT RefreshDatabase: the full
// migration set contains raw-MySQL DDL (ALTER ... MODIFY COLUMN) that SQLite
// can't parse, so we hand-build just the two tables this logic touches.
uses(Tests\TestCase::class);

beforeEach(function () {
    foreach (['browser_sessions', 'browser_portal_settings'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('browser_portal_settings', function (Blueprint $t) {
        $t->id();
        $t->integer('idle_minutes')->default(240);
        $t->integer('max_concurrent_sessions')->default(10);
        $t->integer('udp_port_range_start')->default(52000);
        $t->integer('udp_port_range_end')->default(52100);
        $t->integer('ports_per_session')->default(10);
        $t->string('neko_image')->default('img:latest');
        $t->string('desktop_resolution')->default('1920x1080@30');
        $t->boolean('auto_request_control')->default(true);
        $t->boolean('hide_neko_branding')->default(true);
        $t->timestamps();
    });

    Schema::create('browser_sessions', function (Blueprint $t) {
        $t->id();
        $t->string('session_id', 12)->unique();
        $t->unsignedBigInteger('user_id');
        $t->string('container_name')->unique();
        $t->string('volume_name');
        $t->unsignedSmallInteger('webrtc_port_start');
        $t->unsignedSmallInteger('webrtc_port_end');
        $t->string('status')->default('starting');
        $t->timestamp('stopped_at')->nullable();
        $t->timestamps();
    });
});

/**
 * Fake DockerClient: a test declares which neko containers are "running" and we
 * record every rm() — so reclaim/allocation behaviour can be asserted without a
 * real docker daemon.
 */
function bpFakeDocker(array $running = []): DockerClient
{
    return new class($running) extends DockerClient
    {
        public array $removed = [];

        public function __construct(public array $running = []) {}

        public function listNekoContainers(): array
        {
            return $this->running;
        }

        public function rm(string $containerName, bool $force = false): void
        {
            $this->removed[] = $containerName;
        }
    };
}

/** SessionManager with the protected allocator exposed for direct assertion. */
function bpManager(DockerClient $docker): SessionManager
{
    return new class($docker, new NginxSnippetWriter) extends SessionManager
    {
        public function allocate(): array
        {
            return $this->allocatePortChunk();
        }
    };
}

function bpSeedSession(string $container, int $start, int $end, string $status): BrowserSession
{
    return BrowserSession::create([
        'session_id' => substr($container, 5), // strip "neko-"
        'user_id' => 1,
        'container_name' => $container,
        'volume_name' => 'neko-user-1',
        'webrtc_port_start' => $start,
        'webrtc_port_end' => $end,
        'status' => $status,
    ]);
}

it('skips the first chunk when a still-running container holds it but its DB row is inactive', function () {
    // The bug scenario: row is 'stopped' (inactive) yet the container is still
    // up and bound to 52000-52009. The allocator must not hand 52000 back out.
    bpSeedSession('neko-aaaaaaaaaaaa', 52000, 52009, 'stopped');

    $docker = bpFakeDocker(running: ['neko-aaaaaaaaaaaa']);

    expect(bpManager($docker)->allocate())->toBe([52010, 52019]);
});

it('reuses the first chunk once the orphan container is actually gone', function () {
    bpSeedSession('neko-aaaaaaaaaaaa', 52000, 52009, 'stopped');

    // Docker reports nothing running -> the inactive row alone must not block 52000.
    $docker = bpFakeDocker(running: []);

    expect(bpManager($docker)->allocate())->toBe([52000, 52009]);
});

it('reclaims running neko containers with no active session and leaves the rest', function () {
    // Genuinely active session — must NOT be reclaimed.
    bpSeedSession('neko-bbbbbbbbbbbb', 52010, 52019, 'running');

    // 'neko-aaaaaaaaaaaa' runs in docker but has no active row -> orphan.
    $docker = bpFakeDocker(running: ['neko-aaaaaaaaaaaa', 'neko-bbbbbbbbbbbb']);
    $manager = bpManager($docker);

    expect($manager->reconcileOrphans())->toBe(1);
    expect($docker->removed)->toBe(['neko-aaaaaaaaaaaa']);
});
