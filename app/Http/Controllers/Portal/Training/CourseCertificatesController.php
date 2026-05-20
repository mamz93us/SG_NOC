<?php

namespace App\Http\Controllers\Portal\Training;

use App\Http\Controllers\Controller;
use App\Models\EmailMarketing\EmailCampaign;
use App\Models\EmailMarketing\EmailTemplate;
use App\Models\Employee;
use App\Models\Training\Course;
use App\Models\Training\CourseCertificate;
use App\Services\Training\CertificateUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class CourseCertificatesController extends Controller
{
    public function uploadForm(Course $course): View
    {
        return view('portal.email-marketing.courses.upload', [
            'course' => $course,
            'report' => null,
        ]);
    }

    public function uploadStore(Request $request, Course $course, CertificateUploadService $service)
    {
        // PHP's post_max_size will reject larger payloads at the SAPI layer; here
        // we cap per file at 10 MB and accept up to 500 files per submission.
        $request->validate([
            'files'   => ['required', 'array', 'min:1', 'max:500'],
            'files.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $report = $service->handleUpload(
            $course,
            $request->file('files', []),
            $request->user()->id,
        );

        return view('portal.email-marketing.courses.upload', [
            'course' => $course->fresh(),
            'report' => $report,
        ])->with('status', sprintf(
            '%d imported, %d replaced, %d orphaned, %d rejected.',
            $report['imported'], $report['replaced'], $report['orphaned'], $report['rejected'],
        ));
    }

    /**
     * Manually link an orphaned certificate (the upload didn't find a matching
     * employee). Admin picks the right employee from a dropdown.
     */
    public function relink(Request $request, Course $course, CourseCertificate $certificate)
    {
        abort_unless($certificate->course_id === $course->id, 404);

        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
        ]);

        $certificate->update(['employee_id' => $data['employee_id']]);

        return back()->with('status', 'Certificate linked to employee.');
    }

    public function destroy(Course $course, CourseCertificate $certificate)
    {
        abort_unless($certificate->course_id === $course->id, 404);

        try {
            Storage::disk(CourseCertificate::DISK)->delete($certificate->file_path);
        } catch (\Throwable) {
            // Best-effort; the row still goes regardless of blob deletion outcome.
        }

        $certificate->delete();

        return back()->with('status', 'Certificate deleted.');
    }

    public function sendForm(Course $course): View
    {
        $eligibleCount = $course->certificates()->whereNotNull('employee_id')->count();
        $alreadySent = $course->certificates()->whereNotNull('sent_at')->count();

        $senders = \App\Models\EmailMarketing\EmailSenderIdentity::active()
            ->orderByDesc('is_default')
            ->orderBy('email')
            ->get(['id', 'email', 'name', 'reply_to', 'is_default']);

        return view('portal.email-marketing.courses.send', [
            'course'        => $course,
            'templates'     => EmailTemplate::orderBy('name')->get(['id', 'name']),
            'eligibleCount' => $eligibleCount,
            'alreadySent'   => $alreadySent,
            'senders'       => $senders,
        ]);
    }

    /**
     * Build and schedule a campaign that delivers this course's certificates.
     * The actual send is driven by the existing campaign dispatcher (every-
     * minute scheduler), so SES throttling / analytics / bounce handling all
     * apply for free.
     */
    public function sendStore(Request $request, Course $course)
    {
        $data = $request->validate([
            'name'              => ['required', 'string', 'max:255'],
            'subject'           => ['required', 'string', 'max:255'],
            'preview_text'      => ['nullable', 'string', 'max:255'],
            'email_template_id' => ['required', 'integer', 'exists:email_templates,id'],
            // from_email must be one of the admin-managed allowed senders
            'from_email'        => ['required', 'email', 'max:191',
                \Illuminate\Validation\Rule::in(
                    \App\Models\EmailMarketing\EmailSenderIdentity::active()->pluck('email')->all()
                ),
            ],
            'from_name'         => ['required', 'string', 'max:191'],
            'reply_to'          => ['nullable', 'email', 'max:191'],
            'scheduled_at'      => ['nullable', 'date'],
        ]);

        if ($course->certificates()->whereNotNull('employee_id')->count() === 0) {
            return back()->with('error', 'No certificates with a linked employee — upload first or link the orphans.');
        }

        $campaign = EmailCampaign::create([
            'name'              => $data['name'],
            'subject'           => $data['subject'],
            'preview_text'      => $data['preview_text'] ?? null,
            'from_email'        => $data['from_email'],
            'from_name'         => $data['from_name'],
            'reply_to'          => $data['reply_to'] ?? null,
            'email_template_id' => $data['email_template_id'],
            'course_id'         => $course->id,
            'status'            => 'scheduled',
            'scheduled_at'      => $data['scheduled_at'] ?? now(),
            'created_by'        => $request->user()->id,
        ]);

        return redirect()
            ->route('portal.marketing.campaigns.analytics', $campaign)
            ->with('status', 'Campaign scheduled. The dispatcher will pick it up within a minute.');
    }

    /**
     * Search employees for the relink dropdown. JSON, capped at 25 hits.
     */
    public function employeeSearch(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $query = Employee::query()->where('status', 'active');
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('email', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%");
            });
        }

        return response()->json(
            $query->orderBy('name')->limit(25)->get(['id', 'name', 'email'])
        );
    }
}
