<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentationController extends Controller
{
    private const META_PATH = 'documentation/meta.json';

    // ───────────────────────────────────────────────────────────────────
    // Helpers — read / write the JSON meta file that tracks public status
    // ───────────────────────────────────────────────────────────────────
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

    // ───────────────────────────────────────────────────────────────────
    // Sanitise filename — strip path separators, validate characters
    // Allows: word chars, dash, underscore, dot, spaces, parentheses
    // ───────────────────────────────────────────────────────────────────
    private function sanitise(string $filename): string
    {
        return basename($filename);
    }

    private function validFilename(string $filename): bool
    {
        return (bool) preg_match('/^[\w\-\. ()]+\.html?$/i', $filename);
    }

    // ───────────────────────────────────────────────────────────────────
    // INDEX — list all uploaded docs (admin)
    // ───────────────────────────────────────────────────────────────────
    public function index()
    {
        $meta = $this->getMeta();

        $files = collect(Storage::disk('local')->files('documentation'))
            ->filter(fn($p) => basename($p) !== 'meta.json')
            ->map(function (string $path) use ($meta) {
                $name = basename($path);
                return [
                    'name'      => $name,
                    'path'      => $path,
                    'size'      => Storage::disk('local')->size($path),
                    'modified'  => Storage::disk('local')->lastModified($path),
                    'slug'      => Str::before($name, '.html'),
                    'is_public' => $meta[$name]['is_public'] ?? false,
                ];
            })
            ->sortByDesc('modified')
            ->values();

        return view('admin.documentation.index', compact('files'));
    }

    // ───────────────────────────────────────────────────────────────────
    // STORE — upload an HTML file
    // ───────────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $request->validate([
            'file'  => 'required|file|mimes:html,htm|max:10240',
            'title' => 'nullable|string|max:120',
        ]);

        $original = $request->file('file')->getClientOriginalName();

        if ($request->filled('title')) {
            $original = Str::slug($request->input('title')) . '.html';
        }

        $filename = basename($original);

        if (Storage::disk('local')->exists('documentation/' . $filename)) {
            $stem     = Str::before($filename, '.html');
            $filename = $stem . '-' . now()->format('YmdHis') . '.html';
        }

        $request->file('file')->storeAs('documentation', $filename, 'local');

        return redirect()->route('admin.documentation.index')
            ->with('success', "'{$filename}' uploaded successfully.");
    }

    // ───────────────────────────────────────────────────────────────────
    // SHOW — render the HTML file inside a sandboxed iframe (admin)
    // ───────────────────────────────────────────────────────────────────
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

        $html = Storage::disk('local')->get($path);

        return view('admin.documentation.show', [
            'filename'  => $filename,
            'html'      => $html,
            'is_public' => $this->getMeta()[$filename]['is_public'] ?? false,
        ]);
    }

    // ───────────────────────────────────────────────────────────────────
    // RAW — serve the HTML file directly (open in new tab)
    // ───────────────────────────────────────────────────────────────────
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

    // ───────────────────────────────────────────────────────────────────
    // TOGGLE PUBLIC — mark a doc as public or private
    // ───────────────────────────────────────────────────────────────────
    public function togglePublic(string $filename)
    {
        $filename = $this->sanitise($filename);

        if (! $this->validFilename($filename)) {
            abort(404);
        }

        if (! Storage::disk('local')->exists('documentation/' . $filename)) {
            abort(404);
        }

        $meta = $this->getMeta();
        $current = $meta[$filename]['is_public'] ?? false;
        $meta[$filename]['is_public'] = ! $current;
        $this->saveMeta($meta);

        $state = $meta[$filename]['is_public'] ? 'public' : 'private';

        return redirect()->route('admin.documentation.index')
            ->with('success', "'{$filename}' is now {$state}.");
    }

    // ───────────────────────────────────────────────────────────────────
    // DESTROY — delete a doc
    // ───────────────────────────────────────────────────────────────────
    public function destroy(string $filename)
    {
        $filename = $this->sanitise($filename);

        if (! $this->validFilename($filename)) {
            abort(404);
        }

        Storage::disk('local')->delete('documentation/' . $filename);

        // Remove from meta
        $meta = $this->getMeta();
        unset($meta[$filename]);
        $this->saveMeta($meta);

        return redirect()->route('admin.documentation.index')
            ->with('success', "'{$filename}' deleted.");
    }

    // ───────────────────────────────────────────────────────────────────
    // PUBLIC INDEX — list all public docs (no auth required)
    // ───────────────────────────────────────────────────────────────────
    public function publicIndex()
    {
        $meta = $this->getMeta();

        $files = collect(Storage::disk('local')->files('documentation'))
            ->filter(fn($p) => basename($p) !== 'meta.json')
            ->map(function (string $path) use ($meta) {
                $name = basename($path);
                return [
                    'name'      => $name,
                    'size'      => Storage::disk('local')->size($path),
                    'modified'  => Storage::disk('local')->lastModified($path),
                    'is_public' => $meta[$name]['is_public'] ?? false,
                ];
            })
            ->filter(fn($f) => $f['is_public'])
            ->sortByDesc('modified')
            ->values();

        return view('documentation.public', compact('files'));
    }

    // ───────────────────────────────────────────────────────────────────
    // PUBLIC SHOW — render a public doc (no auth required)
    // ───────────────────────────────────────────────────────────────────
    public function publicShow(string $filename)
    {
        $filename = $this->sanitise($filename);

        if (! $this->validFilename($filename)) {
            abort(404);
        }

        $meta = $this->getMeta();

        if (! ($meta[$filename]['is_public'] ?? false)) {
            abort(404);
        }

        $path = 'documentation/' . $filename;

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $html = Storage::disk('local')->get($path);

        return view('documentation.public-show', compact('filename', 'html'));
    }
}
