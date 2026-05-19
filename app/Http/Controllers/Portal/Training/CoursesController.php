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

        return view('portal.email-marketing.courses.show', compact('course', 'certificates'));
    }

    public function edit(Course $course): View
    {
        return view('portal.email-marketing.courses.create', [
            'course'    => $course,
            'templates' => EmailTemplate::orderBy('name')->get(['id', 'name']),
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
            'default_from_email'  => ['nullable', 'email', 'max:191'],
            'default_from_name'   => ['nullable', 'string', 'max:191'],
        ]);
    }
}
