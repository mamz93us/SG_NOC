<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        ]);
    }

    /**
     * POST /admin/forms
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateFormRequest($request);

        $data['slug']       = FormTemplate::generateSlug($data['name']);
        $data['created_by'] = Auth::id();
        $data['settings']   = array_merge(FormTemplate::defaultSettings(), $data['settings'] ?? []);

        $form = FormTemplate::create($data);

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
        return view('admin.forms.builder', compact('form', 'workflowTemplates', 'users'));
    }

    /**
     * PUT /admin/forms/{form}
     */
    public function update(Request $request, FormTemplate $form): RedirectResponse
    {
        $data = $this->validateFormRequest($request);
        $form->update($data);

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

        $form->delete();

        return redirect()->route('admin.forms.index')
            ->with('success', 'Form "' . $form->name . '" deleted.');
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
        return $request->validate([
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
            'workflow_template_id'   => 'nullable|exists:workflow_templates,id',
            'workflow_payload_map'   => 'nullable|json',
        ]);
    }
}
