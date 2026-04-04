<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentationController extends Controller
{
    private const META_PATH = 'documentation/meta.json';

    // ── Meta helpers ────────────────────────────────────────────────────
    private function getMeta(): array
    {
        if (! Storage::disk('local')->exists(self::META_PATH)) {
            return [];
        }
        return json_decode(Storage::disk('local')->get(self::META_PATH), true) ?? [];
    }

    private function saveMeta(array $meta): void
    {
        Storage::disk('local')->put(self::META_PATH, json_encode($meta, JSON_PRETTY_PRINT));
    }

    private function sanitise(string $filename): string
    {
        return basename($filename);
    }

    private function validFilename(string $filename): bool
    {
        return (bool) preg_match('/^[\w\-\. ()]+\.html?$/i', $filename);
    }

    // ── INDEX ────────────────────────────────────────────────────────────
    public function index()
    {
        $meta = $this->getMeta();

        $files = collect(Storage::disk('local')->files('documentation'))
            ->filter(fn($p) => basename($p) !== 'meta.json')
            ->map(function (string $path) use ($meta) {
                $name = basename($path);
                $m    = $meta[$name] ?? [];
                return [
                    'name'        => $name,
                    'path'        => $path,
                    'size'        => Storage::disk('local')->size($path),
                    'modified'    => Storage::disk('local')->lastModified($path),
                    'is_public'   => $m['is_public']   ?? false,
                    'title'       => $m['title']       ?? '',
                    'description' => $m['description'] ?? '',
                ];
            })
            ->sortByDesc('modified')
            ->values();

        return view('admin.documentation.index', compact('files'));
    }

    // ── STORE ────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $request->validate([
            'file'        => 'required|file|mimes:html,htm|max:10240',
            'title'       => 'nullable|string|max:120',
            'description' => 'nullable|string|max:500',
        ]);

        $original = $request->file('file')->getClientOriginalName();
        $title    = $request->input('title', '');

        // Use slugified title as filename if provided
        if ($title) {
            $original = Str::slug($title) . '.html';
        }

        $filename = basename($original);

        if (Storage::disk('local')->exists('documentation/' . $filename)) {
            $stem     = Str::before($filename, '.html');
            $filename = $stem . '-' . now()->format('YmdHis') . '.html';
        }

        $request->file('file')->storeAs('documentation', $filename, 'local');

        // Save meta
        $meta            = $this->getMeta();
        $meta[$filename] = [
            'is_public'   => false,
            'title'       => $title ?: $filename,
            'description' => $request->input('description', ''),
        ];
        $this->saveMeta($meta);

        return redirect()->route('admin.documentation.index')
            ->with('success', "'{$filename}' uploaded successfully.");
    }

    // ── SHOW ─────────────────────────────────────────────────────────────
    public function show(string $filename)
    {
        $filename = $this->sanitise($filename);

        if (! $this->validFilename($filename)) {
            abort(404);
        }

        $path = 'documentation/' . $filename;

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $meta = $this->getMeta();
        $m    = $meta[$filename] ?? [];
        $html = Storage::disk('local')->get($path);

        return view('admin.documentation.show', [
            'filename'    => $filename,
            'html'        => $html,
            'is_public'   => $m['is_public']   ?? false,
            'title'       => $m['title']       ?? $filename,
            'description' => $m['description'] ?? '',
        ]);
    }

    // ── RAW ──────────────────────────────────────────────────────────────
    public function raw(string $filename)
    {
        $filename = $this->sanitise($filename);

        if (! $this->validFilename($filename)) {
            abort(404);
        }

        $path = 'documentation/' . $filename;

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return response(Storage::disk('local')->get($path), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('X-Frame-Options', 'SAMEORIGIN');
    }

    // ── UPDATE META (title + description) ────────────────────────────────
    public function updateMeta(Request $request, string $filename)
    {
        $filename = $this->sanitise($filename);

        if (! $this->validFilename($filename)) {
            abort(404);
        }

        if (! Storage::disk('local')->exists('documentation/' . $filename)) {
            abort(404);
        }

        $request->validate([
            'title'       => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
        ]);

        $meta            = $this->getMeta();
        $existing        = $meta[$filename] ?? [];
        $meta[$filename] = array_merge($existing, [
            'title'       => $request->input('title'),
            'description' => $request->input('description', ''),
        ]);
        $this->saveMeta($meta);

        return redirect()->route('admin.documentation.index')
            ->with('success', "'{$filename}' updated.");
    }

    // ── TOGGLE PUBLIC ─────────────────────────────────────────────────────
    public function togglePublic(string $filename)
    {
        $filename = $this->sanitise($filename);

        if (! $this->validFilename($filename)) {
            abort(404);
        }

        if (! Storage::disk('local')->exists('documentation/' . $filename)) {
            abort(404);
        }

        $meta                         = $this->getMeta();
        $current                      = $meta[$filename]['is_public'] ?? false;
        $meta[$filename]['is_public'] = ! $current;
        $this->saveMeta($meta);

        $state = $meta[$filename]['is_public'] ? 'public' : 'private';

        return redirect()->route('admin.documentation.index')
            ->with('success', "'{$filename}' is now {$state}.");
    }

    // ── DESTROY ──────────────────────────────────────────────────────────
    public function destroy(string $filename)
    {
        $filename = $this->sanitise($filename);

        if (! $this->validFilename($filename)) {
            abort(404);
        }

        Storage::disk('local')->delete('documentation/' . $filename);

        $meta = $this->getMeta();
        unset($meta[$filename]);
        $this->saveMeta($meta);

        return redirect()->route('admin.documentation.index')
            ->with('success', "'{$filename}' deleted.");
    }

    // ── PUBLIC INDEX ─────────────────────────────────────────────────────
    public function publicIndex()
    {
        $meta = $this->getMeta();

        $files = collect(Storage::disk('local')->files('documentation'))
            ->filter(fn($p) => basename($p) !== 'meta.json')
            ->map(function (string $path) use ($meta) {
                $name = basename($path);
                $m    = $meta[$name] ?? [];
                return [
                    'name'        => $name,
                    'size'        => Storage::disk('local')->size($path),
                    'modified'    => Storage::disk('local')->lastModified($path),
                    'is_public'   => $m['is_public']   ?? false,
                    'title'       => $m['title']       ?? $name,
                    'description' => $m['description'] ?? '',
                ];
            })
            ->filter(fn($f) => $f['is_public'])
            ->sortByDesc('modified')
            ->values();

        return view('documentation.public', compact('files'));
    }

    // ── PUBLIC SHOW ──────────────────────────────────────────────────────
    public function publicShow(string $filename)
    {
        $filename = $this->sanitise($filename);

        if (! $this->validFilename($filename)) {
            abort(404);
        }

        $meta = $this->getMeta();
        $m    = $meta[$filename] ?? [];

        if (! ($m['is_public'] ?? false)) {
            abort(404);
        }

        $path = 'documentation/' . $filename;

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return view('documentation.public-show', [
            'filename'    => $filename,
            'html'        => Storage::disk('local')->get($path),
            'title'       => $m['title']       ?? $filename,
            'description' => $m['description'] ?? '',
        ]);
    }
}
