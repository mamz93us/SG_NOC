<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\FormToken;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FormBuilderController extends Controller
{
    /**
     * GET /admin/forms
     */
    public function index()
    {
        $forms = FormTemplate::withCount('submissions')
            ->orderByDesc('created_at')
            ->get();

        return view('admin.forms.index', compact('forms'));
    }

    /**
     * GET /admin/forms/create
     */
    public function create()
    {
        $workflowTemplates = WorkflowTemplate::where('is_active', true)->orderBy('display_name')->get();
        $users             = User::orderBy('name')->get(['id', 'name']);
        return view('admin.forms.builder', [
            'form'              => null,
            'workflowTemplates' => $workflowTemplates,
            'users'             => $users,
            'worldCupTeams'     => config('worldcup.teams', []),
        ]);
    }

    /**
     * POST /admin/forms
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateFormRequest($request);

        $data['settings']   = array_merge(FormTemplate::defaultSettings(), $data['settings'] ?? []);
        $data               = $this->applyWorldCupContest($data);
        $data['slug']       = FormTemplate::generateSlug($data['name']);
        $data['created_by'] = Auth::id();

        $form = FormTemplate::create($data);

        ActivityLog::create([
            'model_type' => FormTemplate::class,
            'model_id'   => $form->id,
            'action'     => 'form_template_created',
            'changes'    => ['name' => $form->name, 'slug' => $form->slug, 'type' => $form->type],
            'user_id'    => Auth::id(),
        ]);

        return redirect()->route('admin.forms.edit', $form)
            ->with('success', 'Form "' . $form->name . '" created.');
    }

    /**
     * GET /admin/forms/{form}/edit
     */
    public function edit(FormTemplate $form)
    {
        $workflowTemplates = WorkflowTemplate::where('is_active', true)->orderBy('display_name')->get();
        $users             = User::orderBy('name')->get(['id', 'name']);
        $worldCupTeams     = config('worldcup.teams', []);
        return view('admin.forms.builder', compact('form', 'workflowTemplates', 'users', 'worldCupTeams'));
    }

    /**
     * PUT /admin/forms/{form}
     */
    public function update(Request $request, FormTemplate $form): RedirectResponse
    {
        $data             = $this->validateFormRequest($request);
        $data['settings'] = array_merge(FormTemplate::defaultSettings(), $data['settings'] ?? []);
        $data             = $this->applyWorldCupContest($data);
        $before           = $form->only(array_keys($data));
        $form->update($data);

        ActivityLog::create([
            'model_type' => FormTemplate::class,
            'model_id'   => $form->id,
            'action'     => 'form_template_updated',
            'changes'    => ['old' => $before, 'new' => $form->getChanges()],
            'user_id'    => Auth::id(),
        ]);

        return back()->with('success', 'Form saved.');
    }

    /**
     * DELETE /admin/forms/{form}
     */
    public function destroy(FormTemplate $form): RedirectResponse
    {
        if ($form->submissions()->exists()) {
            return back()->withErrors(['form' => 'Cannot delete a form that has submissions. Archive it instead.']);
        }

        $snapshot = ['name' => $form->name, 'slug' => $form->slug, 'type' => $form->type];
        $id       = $form->id;
        $form->delete();

        ActivityLog::create([
            'model_type' => FormTemplate::class,
            'model_id'   => $id,
            'action'     => 'form_template_deleted',
            'changes'    => $snapshot,
            'user_id'    => Auth::id(),
        ]);

        return redirect()->route('admin.forms.index')
            ->with('success', 'Form "' . $snapshot['name'] . '" deleted.');
    }

    /**
     * GET /admin/forms/{form}/submissions
     */
    public function submissions(Request $request, FormTemplate $form)
    {
        $query = $form->submissions()->with('submittedBy');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $submissions = $query->paginate(50)->withQueryString();

        return view('admin.forms.submissions', compact('form', 'submissions'));
    }

    /**
     * GET /admin/forms/{form}/submissions/{submission}
     */
    public function showSubmission(FormTemplate $form, FormSubmission $submission)
    {
        return view('admin.forms.submission_show', compact('form', 'submission'));
    }

    /**
     * PATCH /admin/forms/{form}/submissions/{submission}
     */
    public function reviewSubmission(Request $request, FormTemplate $form, FormSubmission $submission): RedirectResponse
    {
        $data = $request->validate([
            'status'         => 'required|in:new,reviewed,actioned,closed',
            'reviewer_notes' => 'nullable|string|max:2000',
        ]);

        $submission->update([
            'status'         => $data['status'],
            'reviewer_notes' => $data['reviewer_notes'] ?? null,
            'reviewed_by'    => Auth::id(),
            'reviewed_at'    => now(),
        ]);

        return back()->with('success', 'Submission updated.');
    }

    /**
     * GET /admin/forms/{form}/submissions/export
     */
    public function exportSubmissions(FormTemplate $form)
    {
        $submissions = $form->submissions()->with('submittedBy')->get();
        $fieldNames  = collect($form->schema)->pluck('name')->toArray();

        ActivityLog::create([
            'model_type' => FormTemplate::class,
            'model_id'   => $form->id,
            'action'     => 'submissions_exported',
            'changes'    => [
                'form_slug' => $form->slug,
                'count'     => $submissions->count(),
            ],
            'user_id' => Auth::id(),
        ]);

        $headers = ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="form_' . $form->slug . '_submissions.csv"'];

        $callback = function () use ($submissions, $fieldNames) {
            $fh = fopen('php://output', 'w');
            fputcsv($fh, array_merge(['ID', 'Submitted By', 'Email', 'IP', 'Status', 'Submitted At'], $fieldNames));
            foreach ($submissions as $s) {
                $row = [
                    $s->id,
                    $s->submittedBy?->name ?? 'Anonymous',
                    $s->submitter_email ?? '—',
                    $s->ip_address,
                    $s->status,
                    $s->created_at?->toDateTimeString(),
                ];
                foreach ($fieldNames as $name) {
                    $val = $s->data[$name] ?? '';
                    $row[] = is_array($val) ? implode(', ', $val) : $val;
                }
                fputcsv($fh, $row);
            }
            fclose($fh);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * POST /admin/forms/{form}/tokens
     */
    public function generateToken(Request $request, FormTemplate $form): JsonResponse
    {
        $data = $request->validate([
            'label'      => 'nullable|string|max:100',
            'email'      => 'nullable|email|max:150',
            'uses_limit' => 'nullable|integer|min:1',
            'expires_at' => 'nullable|date',
        ]);

        $token = FormToken::generate($form->id, $data);
        $url   = route('forms.show', ['slug' => $form->slug, 'token' => $token->token]);

        return response()->json([
            'token' => $token->token,
            'url'   => $url,
            'label' => $token->label,
        ]);
    }

    // ─── Private ──────────────────────────────────────────────────────

    private function validateFormRequest(Request $request): array
    {
        $validated = $request->validate([
            'name'                   => 'required|string|max:150',
            'description'            => 'nullable|string',
            'type'                   => 'required|in:feedback,survey,request,intake',
            'visibility'             => 'required|in:public,private,token_only',
            'is_active'              => 'boolean',
            'expires_at'             => 'nullable|date',
            'schema'                 => 'required|json',
            'settings'               => 'nullable|array',
            'settings.confirmation_message' => 'nullable|string|max:500',
            'settings.redirect_url'  => 'nullable|url',
            'settings.allow_anonymous' => 'nullable|boolean',
            'settings.collect_email' => 'nullable|boolean',
            'settings.one_per_token' => 'nullable|boolean',
            'settings.max_submissions' => 'nullable|integer|min:1',
            'settings.notify_user_ids' => 'nullable|array',
            'settings.submit_label'  => 'nullable|string|max:80',
            'settings.theme'            => 'nullable|string|max:40',
            'settings.worldcup'         => 'nullable|array',
            'settings.worldcup.enabled' => 'nullable|boolean',
            'settings.worldcup.home'    => 'nullable|string|max:10',
            'settings.worldcup.away'    => 'nullable|string|max:10',
            'settings.worldcup.kickoff' => 'nullable|string|max:40',
            'workflow_template_id'   => 'nullable|exists:workflow_templates,id',
            'workflow_payload_map'   => 'nullable|json',
        ]);

        // Decode JSON-string fields so Eloquent's array cast stores them correctly.
        // Without this, the cast would double-encode the JSON string on save and
        // return a string (not an array) on retrieval.
        if (isset($validated['schema'])) {
            $validated['schema'] = json_decode($validated['schema'], true) ?? [];
        }
        if (isset($validated['workflow_payload_map'])) {
            $validated['workflow_payload_map'] = json_decode($validated['workflow_payload_map'], true) ?? [];
        }

        return $validated;
    }

    /**
     * When the "World Cup contest" box is ticked, theme the form, resolve the two
     * selected teams (code → code+name) and inject the two score fields into the
     * schema so the generic validator / submission storage / CSV export handle them
     * with no special-casing. When unticked, clear the contest theme.
     */
    private function applyWorldCupContest(array $data): array
    {
        $settings = $data['settings'] ?? [];
        $wc       = $settings['worldcup'] ?? null;
        $enabled  = is_array($wc) && filter_var($wc['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $enabled) {
            unset($settings['theme']);
            $settings['worldcup'] = ['enabled' => false];
            $data['settings']     = $settings;

            return $data;
        }

        $teams = collect(config('worldcup.teams', []))->keyBy('code');
        $home  = $teams->get($wc['home'] ?? null);
        $away  = $teams->get($wc['away'] ?? null);

        $settings['theme']    = 'worldcup';
        $settings['worldcup'] = [
            'enabled' => true,
            'home'    => $home ? ['code' => $home['code'], 'name' => $home['name']] : null,
            'away'    => $away ? ['code' => $away['code'], 'name' => $away['name']] : null,
            'kickoff' => $wc['kickoff'] ?? null,
        ];
        $data['settings'] = $settings;

        // Sync the two score fields with the selected teams.
        $homeLabel = ($home['name'] ?? 'Home').' — goals';
        $awayLabel = ($away['name'] ?? 'Away').' — goals';

        $schema = $data['schema'] ?? [];
        $found  = ['home_score' => false, 'away_score' => false];

        foreach ($schema as &$field) {
            $name = $field['name'] ?? null;
            if ($name === 'home_score') {
                $field         = $this->scoreField('home_score', $homeLabel);
                $found['home_score'] = true;
            } elseif ($name === 'away_score') {
                $field         = $this->scoreField('away_score', $awayLabel);
                $found['away_score'] = true;
            }
        }
        unset($field);

        if (! $found['home_score']) {
            $schema[] = $this->scoreField('home_score', $homeLabel);
        }
        if (! $found['away_score']) {
            $schema[] = $this->scoreField('away_score', $awayLabel);
        }

        $data['schema'] = $schema;

        return $data;
    }

    private function scoreField(string $name, string $label): array
    {
        return [
            'id'          => $name,
            'type'        => 'number',
            'name'        => $name,
            'label'       => $label,
            'required'    => true,
            'width'       => 'half',
            'min'         => 0,
            'max'         => 20,
            'help_text'   => '',
            'conditional' => null,
        ];
    }
}
