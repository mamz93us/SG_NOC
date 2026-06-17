<?php

namespace App\Http\Controllers\Portal\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\FormTemplate;
use App\Services\WorldCup\ContestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * World Cup "Guess the Score" contests, self-service inside the MARKETING portal
 * (em.samirgroup.net) so marketing staff don't need NOC/admin access. A contest is
 * a FormTemplate themed `worldcup`; storage/export reuse the existing forms pipeline.
 */
class WorldCupContestController extends Controller
{
    public function __construct(private ContestService $contests)
    {
    }

    /** GET /contests */
    public function index()
    {
        $forms = FormTemplate::worldCup()->withCount('submissions')->orderByDesc('created_at')->get();

        return view('portal.marketing.contests.index', compact('forms'));
    }

    /** GET /contests/create */
    public function create()
    {
        return view('portal.marketing.contests.create', [
            'teams' => $this->contests->teams(),
        ]);
    }

    /** POST /contests */
    public function store(Request $request): RedirectResponse
    {
        $codes = collect($this->contests->teams())->pluck('code')->all();

        $data = $request->validate([
            'name'       => 'required|string|max:150',
            'home'       => ['required', 'string', 'in:'.implode(',', $codes)],
            'away'       => ['required', 'string', 'different:home', 'in:'.implode(',', $codes)],
            'kickoff'    => 'nullable|string|max:60',
            'expires_at' => 'nullable|date',
        ], [
            'away.different' => 'The two teams must be different.',
        ]);

        $form = $this->contests->createForm([
            'name'       => $data['name'],
            'home'       => $data['home'],
            'away'       => $data['away'],
            'kickoff'    => $data['kickoff'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'created_by' => Auth::id(),
        ]);

        ActivityLog::create([
            'model_type' => FormTemplate::class,
            'model_id'   => $form->id,
            'action'     => 'worldcup_contest_created',
            'changes'    => ['name' => $form->name, 'slug' => $form->slug],
            'user_id'    => Auth::id(),
        ]);

        return redirect()->route('portal.marketing.contests.show', $form)
            ->with('success', 'Contest "'.$form->name.'" created. Copy the link below into an email campaign.');
    }

    /** GET /contests/{form} — share link + responses */
    public function show(FormTemplate $form)
    {
        abort_unless(($form->settings['theme'] ?? null) === 'worldcup', 404);

        $submissions = $form->submissions()->with('submittedBy')->paginate(50);
        $url         = url('/forms/'.$form->slug);

        return view('portal.marketing.contests.show', compact('form', 'submissions', 'url'));
    }

    /** POST /contests/{form}/toggle — open/close submissions */
    public function toggle(FormTemplate $form): RedirectResponse
    {
        abort_unless(($form->settings['theme'] ?? null) === 'worldcup', 404);

        $form->update(['is_active' => ! $form->is_active]);

        return back()->with('success', $form->is_active ? 'Contest re-opened.' : 'Contest closed — no longer accepting guesses.');
    }

    /** GET /contests/{form}/export — CSV of all guesses */
    public function export(FormTemplate $form): StreamedResponse
    {
        abort_unless(($form->settings['theme'] ?? null) === 'worldcup', 404);

        $submissions = $form->submissions()->with('submittedBy')->get();

        ActivityLog::create([
            'model_type' => FormTemplate::class,
            'model_id'   => $form->id,
            'action'     => 'worldcup_contest_exported',
            'changes'    => ['slug' => $form->slug, 'count' => $submissions->count()],
            'user_id'    => Auth::id(),
        ]);

        $home = $form->settings['worldcup']['home']['name'] ?? 'Home';
        $away = $form->settings['worldcup']['away']['name'] ?? 'Away';

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="contest_'.$form->slug.'.csv"',
        ];

        $callback = function () use ($submissions, $home, $away) {
            $fh = fopen('php://output', 'w');
            fputcsv($fh, ['#', 'Employee', 'Email', 'Submitted At', $home.' goals', $away.' goals']);
            foreach ($submissions as $i => $s) {
                fputcsv($fh, [
                    $i + 1,
                    $s->submittedBy?->name ?? 'Anonymous',
                    $s->submitter_email ?? '—',
                    $s->created_at?->toDateTimeString(),
                    $s->data['home_score'] ?? '',
                    $s->data['away_score'] ?? '',
                ]);
            }
            fclose($fh);
        };

        return response()->stream($callback, 200, $headers);
    }
}
