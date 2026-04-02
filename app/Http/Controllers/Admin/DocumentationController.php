<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentationController extends Controller
{
    // ───────────────────────────────────────────────────────────────────
    // INDEX — list all uploaded docs
    // ───────────────────────────────────────────────────────────────────
    public function index()
    {
        $files = collect(Storage::disk('local')->files('documentation'))
            ->map(function (string $path) {
                return [
                    'name'      => basename($path),
                    'path'      => $path,
                    'size'      => Storage::disk('local')->size($path),
                    'modified'  => Storage::disk('local')->lastModified($path),
                    'slug'      => Str::before(basename($path), '.html'),
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

        // If a custom title was given, slugify it and use as filename
        if ($request->filled('title')) {
            $original = Str::slug($request->input('title')) . '.html';
        }

        // Prevent path traversal — strip any directory segments
        $filename = basename($original);

        // Ensure unique name so uploads don't silently overwrite each other
        if (Storage::disk('local')->exists('documentation/' . $filename)) {
            $stem      = Str::before($filename, '.html');
            $filename  = $stem . '-' . now()->format('YmdHis') . '.html';
        }

        $request->file('file')->storeAs('documentation', $filename, 'local');

        return redirect()->route('admin.documentation.index')
            ->with('success', "'{$filename}' uploaded successfully.");
    }

    // ───────────────────────────────────────────────────────────────────
    // SHOW — render the HTML file inside a sandboxed iframe
    // ───────────────────────────────────────────────────────────────────
    public function show(string $filename)
    {
        // Sanitise — only allow alphanumeric, dash, underscore, dot
        if (! preg_match('/^[\w\-\.]+\.html?$/i', $filename)) {
            abort(404);
        }

        $path = 'documentation/' . $filename;

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $html = Storage::disk('local')->get($path);

        return view('admin.documentation.show', [
            'filename' => $filename,
            'html'     => $html,
        ]);
    }

    // ───────────────────────────────────────────────────────────────────
    // RAW — serve the HTML file directly (open in new tab)
    // ───────────────────────────────────────────────────────────────────
    public function raw(string $filename)
    {
        if (! preg_match('/^[\w\-\.]+\.html?$/i', $filename)) {
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
    // DESTROY — delete a doc
    // ───────────────────────────────────────────────────────────────────
    public function destroy(string $filename)
    {
        if (! preg_match('/^[\w\-\.]+\.html?$/i', $filename)) {
            abort(404);
        }

        Storage::disk('local')->delete('documentation/' . $filename);

        return redirect()->route('admin.documentation.index')
            ->with('success', "'{$filename}' deleted.");
    }
}
