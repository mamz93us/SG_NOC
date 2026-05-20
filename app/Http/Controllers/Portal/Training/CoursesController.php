<?php

namespace App\Http\Controllers\Portal\Training;

use App\Http\Controllers\Controller;
use App\Models\EmailMarketing\EmailTemplate;
use App\Models\Training\Course;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CoursesController extends Controller
{
    public function index(): View
    {
        $courses = Course::withCount(['certificates', 'certificates as sent_count' => function ($q) {
            $q->whereNotNull('sent_at');
        }])
            ->orderBy('name')
            ->paginate(25);

        return view('portal.email-marketing.courses.index', compact('courses'));
    }

    public function create(): View
    {
        return view('portal.email-marketing.courses.create', [
            'course'    => new Course,
            'templates' => EmailTemplate::orderBy('name')->get(['id', 'name']),
            'senders'   => $this->loadSenders(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;

        $course = Course::create($data);

        return redirect()->route('portal.marketing.courses.show', $course)
            ->with('status', 'Course created.');
    }

    public function show(Course $course): View
    {
        $course->loadCount(['certificates', 'certificates as sent_count' => function ($q) {
            $q->whereNotNull('sent_at');
        }, 'certificates as orphaned_count' => function ($q) {
            $q->whereNull('employee_id');
        }]);

        $certificates = $course->certificates()
            ->with('employee:id,name,email,status')
            ->orderByRaw('employee_id IS NULL DESC')
            ->orderBy('email')
            ->paginate(50);

        // Campaign-style stats: every campaign this course has fired + lifetime totals.
        $campaigns = \App\Models\EmailMarketing\EmailCampaign::query()
            ->where('course_id', $course->id)
            ->latest('updated_at')
            ->get();

        $totals = [
            'total_sent'          => (int) $campaigns->sum('total_sent'),
            'total_delivered'     => (int) $campaigns->sum('total_delivered'),
            'total_unique_opens'  => (int) $campaigns->sum('total_unique_opens'),
            'total_unique_clicks' => (int) $campaigns->sum('total_unique_clicks'),
            'total_bounces'       => (int) $campaigns->sum('total_bounces'),
            'total_complaints'    => (int) $campaigns->sum('total_complaints'),
        ];
        $totals['open_rate']   = $totals['total_delivered'] > 0 ? round($totals['total_unique_opens']  / $totals['total_delivered'] * 100, 1) : 0;
        $totals['click_rate']  = $totals['total_delivered'] > 0 ? round($totals['total_unique_clicks'] / $totals['total_delivered'] * 100, 1) : 0;
        $totals['bounce_rate'] = $totals['total_sent']      > 0 ? round($totals['total_bounces']      / $totals['total_sent']      * 100, 1) : 0;

        return view('portal.email-marketing.courses.show', compact('course', 'certificates', 'campaigns', 'totals'));
    }

    public function edit(Course $course): View
    {
        return view('portal.email-marketing.courses.create', [
            'course'    => $course,
            'templates' => EmailTemplate::orderBy('name')->get(['id', 'name']),
            'senders'   => $this->loadSenders(),
        ]);
    }

    public function update(Request $request, Course $course)
    {
        $course->update($this->validated($request));

        return redirect()->route('portal.marketing.courses.show', $course)
            ->with('status', 'Course updated.');
    }

    public function destroy(Course $course)
    {
        // The cascadeOnDelete on course_certificates.course_id removes child rows,
        // but the blob files on Azure won't be reaped automatically. Clean them up
        // here to keep storage tidy.
        foreach ($course->certificates as $cert) {
            try {
                \Illuminate\Support\Facades\Storage::disk(\App\Models\Training\CourseCertificate::DISK)
                    ->delete($cert->file_path);
            } catch (\Throwable) {
                // Best-effort — never block the delete on a storage hiccup.
            }
        }

        $course->delete();

        return redirect()->route('portal.marketing.courses.index')
            ->with('status', 'Course and all certificates deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'                => ['required', 'string', 'max:255'],
            'description'         => ['nullable', 'string', 'max:500'],
            'default_template_id' => ['nullable', 'integer', 'exists:email_templates,id'],
            'default_subject'     => ['nullable', 'string', 'max:255'],
            'default_preview_text' => ['nullable', 'string', 'max:255'],
            // default_from_email must come from the admin allowlist (nullable so
            // a course can be drafted before the admin adds any senders).
            'default_from_email'  => ['nullable', 'email', 'max:191',
                \Illuminate\Validation\Rule::in(
                    \App\Models\EmailMarketing\EmailSenderIdentity::active()->pluck('email')->all()
                ),
            ],
            'default_from_name'   => ['nullable', 'string', 'max:191'],
            'default_reply_to'    => ['nullable', 'email', 'max:191'],
        ]);
    }

    /**
     * Active sender identities ordered so the default appears first.
     */
    private function loadSenders()
    {
        return \App\Models\EmailMarketing\EmailSenderIdentity::active()
            ->orderByDesc('is_default')
            ->orderBy('email')
            ->get(['id', 'email', 'name', 'reply_to', 'is_default']);
    }
}
