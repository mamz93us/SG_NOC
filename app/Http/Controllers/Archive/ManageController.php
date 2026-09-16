<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveField;
use App\Models\Archive\ArchiveMember;
use App\Models\Archive\ArchiveSource;
use App\Models\Archive\ArchiveTask;
use App\Models\User;
use App\Services\Archive\ArcMate\ArcMateConnection;
use App\Services\Archive\ArcMate\ArcMateDiscovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Setting the archive up: the ArcMate connection, which projects to mirror, and
 * who may read each one.
 *
 * Gated by `manage-archive-portal` at the route. Deliberately separate from
 * reading: being able to configure the mirror is not being able to read an
 * invoice, and the two permissions are granted to different people.
 *
 * The connection test is the page that matters most on day one. It answers the
 * three questions that decide whether anything works at all — can the SQL login
 * connect, is the file share mounted, and do the recorded paths actually lead to
 * files — and it answers them separately, because they fail separately and for
 * completely different reasons.
 */
class ManageController extends Controller
{
    public function index(Request $request): View
    {
        $source = $this->source();
        $discovery = new ArcMateDiscovery($source);

        $mounted = $discovery->mountAvailable();

        return view('archive.manage.index', [
            'source' => $source,
            'mounted' => $mounted,
            'driverAvailable' => ArcMateConnection::driverAvailable(),
            // Only scanned when the share is actually there: a scan of a missing
            // mount returns an empty list, which reads as "ArcMate has no
            // projects" when the truth is "the mount is gone".
            'projects' => $mounted ? $discovery->scan() : [],
            'archives' => Archive::withCount('members')->orderBy('sort_order')->orderBy('name')->get(),
            'testResult' => session('archive_test'),
        ]);
    }

    /** The ArcMate connection details. The password is write-only — never echoed back. */
    public function saveSource(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'mount_path' => ['nullable', 'string', 'max:255'],
            'trust_server_certificate' => ['nullable', 'boolean'],
        ]);

        $source = $this->source();

        $source->fill([
            'host' => $data['host'],
            'port' => $data['port'] ?: 1433,
            'username' => $data['username'],
            'mount_path' => $data['mount_path'] ?: null,
            'trust_server_certificate' => (bool) ($data['trust_server_certificate'] ?? false),
        ]);

        // Left blank means "keep the one you have", so saving an unrelated
        // setting does not silently wipe the credential.
        if (filled($data['password'] ?? null)) {
            $source->password = $data['password'];
        }

        $source->save();

        return back()->with('status', 'ArcMate connection saved.');
    }

    /**
     * Test everything the mirror depends on, separately.
     *
     * Three independent answers, because in this deployment they have already
     * failed independently: SQL Server sits behind a firewall with TCP/IP off by
     * default, the cifs mount needs its own credentials, and the recorded file
     * paths are reconstructed rather than stored (tblMedia is empty), so a
     * perfect connection can still lead nowhere.
     */
    public function testSource(Request $request): RedirectResponse
    {
        $source = $this->source();
        $connector = new ArcMateConnection;
        $discovery = new ArcMateDiscovery($source);

        $result = [
            'at' => now()->format('Y-m-d H:i'),
            'sql' => $connector->test($source),
            'mounted' => $discovery->mountAvailable(),
            'files' => [],
        ];

        // Prove a path we reconstructed actually lands on a file, for each
        // enabled archive. This is the check that would have caught the empty
        // tblMedia before any of the sync was written.
        foreach (Archive::query()->whereNotNull('arcmate_folder')->readable()->limit(10)->get() as $archive) {
            $file = $archive->files()->onArcMate()->first();

            if (! $file) {
                continue;
            }

            $result['files'][] = [
                'archive' => $archive->slug,
                'path' => $file->path,
                'exists' => $file->path !== '' && @is_file($file->path),
            ];
        }

        $source->forceFill([
            'last_test_at' => now(),
            'last_test_ok' => (bool) $result['sql']['ok'],
            'last_test_error' => $result['sql']['error'],
        ])->save();

        return back()->with('archive_test', $result)->with('status', 'Connection tested.');
    }

    /**
     * Start mirroring one ArcMate project.
     *
     * Creating the archive and its fields from what the share says, rather than
     * from anything typed in: the fields are ArcMate's own (arcDesign.xml), so
     * the people moving across keep searching on exactly what they searched on
     * before.
     */
    public function enable(Request $request): RedirectResponse
    {
        $data = $request->validate(['folder' => ['required', 'string', 'max:255']]);

        $source = $this->source();
        $project = (new ArcMateDiscovery($source))->project($data['folder']);

        if (! $project) {
            return back()->with('error', 'That folder is not an ArcMate project.');
        }

        if (Archive::where('arcmate_folder', $project['folder'])->exists()) {
            return back()->with('error', 'That project is already set up.');
        }

        $archive = new Archive([
            'archive_source_id' => $source->getKey(),
            'slug' => $this->uniqueSlug($project['name'] ?: $project['folder']),
            'name' => $project['name'] ?: $project['folder'],
            'arcmate_folder' => $project['folder'],
            'arcmate_database' => $project['database'],
            'mode' => Archive::MODE_MIRROR,
        ]);

        // An encrypted project is recorded rather than refused: it is worth
        // listing so everyone can see it exists and why it is empty, but the
        // sync, the transfer and AI all skip it.
        if ($project['encrypted']) {
            $archive->readable = false;
            $archive->unreadable_reason = 'ArcMate stored this project encrypted (Encrypt=1), so its files can only be opened in ArcMate.';
        }

        $archive->save();

        foreach ($project['fields'] as $index => $field) {
            ArchiveField::create([
                'archive_id' => $archive->getKey(),
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => $field['type'],
                'required' => $field['required'],
                'is_unique' => $field['is_unique'],
                'arcmate_column' => $field['arcmate_column'],
                'arcmate_type' => $field['arcmate_type'],
                'max_length' => $field['max_length'],
                'options' => $field['options'] ?: null,
                'sort_order' => $index + 1,
            ]);
        }

        return redirect()
            ->route('archive.manage.archive', $archive)
            ->with('status', 'Archive created. The sync will start copying documents within five minutes.');
    }

    public function showArchive(Request $request, Archive $archive): View
    {
        $archive->load(['fields', 'members.user']);

        return view('archive.manage.archive', [
            'archive' => $archive,
            'sampleFile' => $archive->files()->first(),
            'modes' => Archive::MODES,
            'abilities' => ArchiveMember::ABILITIES,
        ]);
    }

    /**
     * Add a field ArcMate does not have.
     *
     * A mirrored archive's fields come from ArcMate's own arcDesign.xml, so it
     * has exactly what ArcMate was set up to record — SPS Invoices has an invoice
     * number and a PO number and nothing else. A field added here has no
     * arcmate_column, which is what makes it safe: ArchiveSyncService maps values
     * BY column, so a field without one is invisible to the mirror. The sync will
     * never write it and never clear it.
     *
     * That is what makes it useful too. AI can propose a value for it from the
     * scan (the fill batches) and a person approves it, which is how the archive
     * comes to hold something ArcMate never captured — a customer name, say.
     */
    public function addField(Request $request, Archive $archive): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(ArchiveField::TYPES))],
            'ai_hint' => ['nullable', 'string', 'max:500'],
            'options' => ['nullable', 'string', 'max:2000'],
        ]);

        $key = $this->uniqueFieldKey($archive, $data['label']);

        // A list field's choices, one per line.
        $options = null;

        if ($data['type'] === ArchiveField::TYPE_LIST && filled($data['options'] ?? null)) {
            $options = array_values(array_filter(array_map('trim', preg_split('/\R/', $data['options']) ?: [])));
        }

        $field = ArchiveField::create([
            'archive_id' => $archive->getKey(),
            'key' => $key,
            'label' => $data['label'],
            'type' => $data['type'],
            // Deliberately null: see the note above. This is the whole reason the
            // sync leaves the field alone.
            'arcmate_column' => null,
            'ai_hint' => $data['ai_hint'] ?: null,
            'options' => $options,
            'sort_order' => (int) $archive->fields()->max('sort_order') + 1,
        ]);

        return back()->with('status', $field->label().' added. ArcMate does not know about it, so the sync will not touch it — fill it in by hand, or let an AI fill batch propose values for it.');
    }

    /**
     * A key nothing else in this archive is using.
     *
     * Derived from the label rather than typed, because the key is what the
     * search form, the URL and the AI tools all use — and it can never change
     * afterwards without orphaning every value stored against it.
     */
    private function uniqueFieldKey(Archive $archive, string $label): string
    {
        $base = Str::slug($label, '_') ?: 'field';
        $base = mb_substr($base, 0, 50);
        $key = $base;
        $n = 2;

        while ($archive->fields()->where('key', $key)->exists()) {
            $key = $base.'_'.$n++;
        }

        return $key;
    }

    /** Give somebody access to one archive. */
    public function addMember(Request $request, Archive $archive): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'abilities' => ['nullable', 'array'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            // Accounts come from Entra, so there is nothing useful to create
            // here — an archive membership for an address nobody signs in with
            // would simply never apply to anyone.
            return back()->with('error', 'No NOC account with that address. Add the person from Entra first (Admin ▸ Users ▸ Add User).');
        }

        $abilities = collect(array_keys(ArchiveMember::ABILITIES))
            ->mapWithKeys(fn (string $key) => [$key => in_array($key, (array) ($data['abilities'] ?? []), true)])
            ->all();

        // Viewing is implied by every other ability: nobody can edit a document
        // they cannot open.
        $abilities['can_view'] = true;

        ArchiveMember::updateOrCreate(
            ['archive_id' => $archive->getKey(), 'user_id' => $user->getKey()],
            $abilities,
        );

        $this->logAccessChange($archive, $user, 'archive_member_added', $abilities);

        return back()->with('status', $user->name.' now has access to '.$archive->displayName().'.');
    }

    public function removeMember(Request $request, Archive $archive, ArchiveMember $member): RedirectResponse
    {
        abort_unless($member->archive_id === $archive->getKey(), 404);

        $user = $member->user;
        $member->delete();

        if ($user) {
            $this->logAccessChange($archive, $user, 'archive_member_removed', []);
        }

        return back()->with('status', 'Access removed.');
    }

    /** Queue the heavy buttons rather than running them in the request. */
    public function queueTask(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', [ArchiveTask::TYPE_RECOUNT, ArchiveTask::TYPE_RESCAN_SOURCE])],
            'archive_id' => ['nullable', 'integer'],
        ]);

        ArchiveTask::queue(
            $data['type'],
            array_filter(['archive_id' => $data['archive_id'] ?? null]),
            $request->user()?->getKey(),
        );

        return back()->with('status', 'Queued. It runs within a minute.');
    }

    // ─── Internals ───────────────────────────────────────────────

    /** The single ArcMate source, created empty on first visit. */
    private function source(): ArchiveSource
    {
        return ArchiveSource::query()->first() ?? ArchiveSource::create([
            'name' => 'ArcMate',
            'port' => 1433,
            'mount_path' => (string) config('archive_portal.mount_path'),
            'arcmate_path_prefix' => (string) config('archive_portal.arcmate_path_prefix'),
            'enabled' => true,
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'archive';
        $slug = $base;
        $n = 2;

        while (Archive::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /**
     * Who may read which archive is a security question, so it is logged by
     * hand as a security action rather than left to the model audit.
     *
     * @param  array<string,bool>  $abilities
     */
    private function logAccessChange(Archive $archive, User $user, string $action, array $abilities): void
    {
        try {
            \App\Models\ActivityLog::log(
                $action,
                $archive,
                [
                    'user' => $user->email,
                    'archive' => $archive->slug,
                    'abilities' => array_keys(array_filter($abilities)),
                ],
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[archive] access change not logged: '.$e->getMessage());
        }
    }
}
