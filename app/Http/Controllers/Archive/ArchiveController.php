<?php

namespace App\Http\Controllers\Archive;

use App\Http\Controllers\Controller;
use App\Models\Archive\Archive;
use App\Services\Archive\ArchiveAccess;
use App\Services\Archive\DocumentSearch;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The archive portal's own pages: what you may see, and finding things in it.
 *
 * Every action starts from ArchiveAccess and nothing here trusts an id from the
 * URL. An archive somebody is not a member of is not "forbidden", it is absent
 * — a 404 — because the existence of an archive named after a supplier or a
 * person is itself worth something to whoever is guessing.
 */
class ArchiveController extends Controller
{
    /**
     * The archives this person may open.
     *
     * Someone signed in with no membership anywhere gets the no-access page
     * rather than an empty list, so "you have not been given access" reads as
     * an answer instead of looking like a broken portal.
     */
    public function index(Request $request): View
    {
        $access = ArchiveAccess::for($request->user());

        $archives = $access->archives()->get();

        if ($archives->isEmpty()) {
            return view('auth.archive-no-access');
        }

        return view('archive.index', [
            'archives' => $archives,
            'canManage' => $access->mayManage(),
        ]);
    }

    /**
     * One archive: its search form and the matching documents.
     *
     * The form is built from the archive's own fields, which for a mirrored
     * project are ArcMate's (invoice number on S1, PO number on S2), so the
     * people moving off ArcMate search on exactly what they searched on before.
     */
    public function show(Request $request, string $slug): View
    {
        $access = ArchiveAccess::for($request->user());

        $archive = $access->archives()->where('slug', $slug)->first();

        abort_unless($archive, 404);

        $archive->load('fields');

        $search = new DocumentSearch($access);
        $criteria = $search->criteriaFrom($request, $archive);
        $documents = $search->run($archive, $criteria)->paginate(50)->withQueryString();

        // Choices for any list field, resolved here rather than in the view:
        // ArcMate leaves most list fields with no values defined, so what people
        // pick from is what the documents actually hold.
        $choices = [];

        foreach ($archive->fields as $field) {
            if ($field->isList()) {
                $choices[$field->key] = $search->choices($field);
            }
        }

        return view('archive.show', [
            'archive' => $archive,
            'fields' => $archive->fields,
            'documents' => $documents,
            'criteria' => $criteria,
            'choices' => $choices,
            'hasFilters' => $search->hasFilters($criteria),
            'canManage' => $access->canOnArchive($archive, 'can_manage'),
            // Two halves, granted and refused separately: the person needs
            // use-archive-ai, and this archive has to have ai_chat switched on.
            // Offering the box to somebody whose every question would be refused
            // is worse than not offering it.
            'canAsk' => $access->mayUseAi() && (bool) $archive->ai_chat,
        ]);
    }
}
