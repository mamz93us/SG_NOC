<?php

namespace App\Http\Controllers\Portal\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\FormToken;
use App\Services\WorldCup\ContestService;
use App\Support\Marketing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            'stage'      => 'nullable|string|max:40',
            'match_date' => 'nullable|string|max:40',
            'kickoff'    => 'nullable|string|max:60',
            'expires_at' => 'nullable|date',
            'logo'       => 'nullable|in:samir,sss',
            'wallpaper'  => 'nullable|image|max:6144',
        ], [
            'away.different' => 'The two teams must be different.',
        ]);

        $wallpaper = $request->hasFile('wallpaper')
            ? $request->file('wallpaper')->store('contest-wallpapers', 'public')
            : null;

        $form = $this->contests->createForm([
            'name'       => $data['name'],
            'home'       => $data['home'],
            'away'       => $data['away'],
            'stage'      => $data['stage'] ?? null,
            'match_date' => $data['match_date'] ?? null,
            'kickoff'    => $data['kickoff'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'logo'       => $data['logo'] ?? 'samir',
            'wallpaper'  => $wallpaper,
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
            ->with('success', 'Contest "'.$form->name.'" created. Copy the merge tag below into your email campaign.');
    }

    /** GET /contests/{form} — share link + responses */
    public function show(FormTemplate $form)
    {
        abort_unless(($form->settings['theme'] ?? null) === 'worldcup', 404);

        $submissions = $form->submissions()->with(['submittedBy', 'token'])->paginate(50);

        // Each employee gets their own one-use link via this merge tag in the campaign.
        $mergeTag = '{{guess_link:'.$form->slug.'}}';

        // A reusable preview token so staff can open the form themselves to check it.
        $previewToken = FormToken::firstOrCreate(
            ['form_id' => $form->id, 'email' => '__preview__'],
            ['token' => Str::random(48), 'label' => 'Preview', 'uses_limit' => null, 'expires_at' => null]
        );
        $previewUrl = rtrim(Marketing::url('/'), '/').'/forms/'.$form->slug.'?token='.$previewToken->token;

        return view('portal.marketing.contests.show', compact('form', 'submissions', 'mergeTag', 'previewUrl'));
    }

    /** POST /contests/{form}/toggle — open/close submissions */
    public function toggle(FormTemplate $form): RedirectResponse
    {
        abort_unless(($form->settings['theme'] ?? null) === 'worldcup', 404);

        $form->update(['is_active' => ! $form->is_active]);

        return back()->with('success', $form->is_active ? 'Contest re-opened.' : 'Contest closed — no longer accepting guesses.');
    }

    /** POST /contests/{form}/test-link — generate a tokenised test link for a given name+email */
    public function testLink(Request $request, FormTemplate $form): RedirectResponse
    {
        abort_unless(($form->settings['theme'] ?? null) === 'worldcup', 404);

        $data = $request->validate([
            'name'  => 'required|string|max:100',
            'email' => 'required|email|max:150',
        ]);

        // Reusable token (no uses_limit) so the tester can open it repeatedly.
        $token = FormToken::create([
            'form_id'    => $form->id,
            'token'      => Str::random(48),
            'label'      => $data['name'],
            'email'      => $data['email'],
            'uses_limit' => null,
            'expires_at' => null,
        ]);

        $url = rtrim(Marketing::url('/'), '/').'/forms/'.$form->slug.'?token='.$token->token;

        return back()->with('test_link', $url)->with('test_for', $data['name'].' <'.$data['email'].'>');
    }

    /** DELETE /contests/{form}/submissions/{submission} — remove one response */
    public function destroySubmission(FormTemplate $form, FormSubmission $submission): RedirectResponse
    {
        abort_unless(($form->settings['theme'] ?? null) === 'worldcup', 404);
        abort_unless($submission->form_id === $form->id, 404);

        // Free the one-use link so that person can enter again.
        if ($submission->token && $submission->token->uses_count > 0) {
            $submission->token->decrement('uses_count');
        }

        $submission->delete();

        return back()->with('success', 'Response deleted.');
    }

    /** POST /contests/{form}/appearance — change logo + wallpaper on an existing contest */
    public function updateAppearance(Request $request, FormTemplate $form): RedirectResponse
    {
        abort_unless(($form->settings['theme'] ?? null) === 'worldcup', 404);

        $request->validate([
            'logo'             => 'nullable|in:samir,sss',
            'wallpaper'        => 'nullable|image|max:6144',
            'remove_wallpaper' => 'nullable|boolean',
        ]);

        $settings = $form->settings;
        $wc       = $settings['worldcup'] ?? [];
        $wc['logo'] = $request->input('logo', $wc['logo'] ?? 'samir');

        if ($request->boolean('remove_wallpaper')) {
            if (! empty($wc['wallpaper'])) {
                Storage::disk('public')->delete($wc['wallpaper']);
            }
            $wc['wallpaper'] = null;
        } elseif ($request->hasFile('wallpaper')) {
            if (! empty($wc['wallpaper'])) {
                Storage::disk('public')->delete($wc['wallpaper']);
            }
            $wc['wallpaper'] = $request->file('wallpaper')->store('contest-wallpapers', 'public');
        }

        $settings['worldcup'] = $wc;
        $form->update(['settings' => $settings]);

        return back()->with('success', 'Contest appearance updated.');
    }

    /** GET /contests/{form}/export — CSV of all guesses */
    public function export(FormTemplate $form): StreamedResponse
    {
        abort_unless(($form->settings['theme'] ?? null) === 'worldcup', 404);

        $submissions = $form->submissions()->with(['submittedBy', 'token'])->get();

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
                $who = $s->submittedBy?->name ?? $s->token?->label ?? 'Anonymous';
                fputcsv($fh, [
                    $i + 1,
                    $who,
                    $s->submitter_email ?? $s->token?->email ?? '—',
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
