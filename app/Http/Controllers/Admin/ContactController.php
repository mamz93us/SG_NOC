<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Contact;
use App\Models\Branch;
use App\Exports\ContactsExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Validation\Rule;

class ContactController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Contact::with('branch')->orderBy('first_name');

        // Search
        if ($request->has('search') && $request->search !== '') {
            $query->where(function ($q) use ($request) {
                $q->where('first_name', 'like', "%" . $request->search . "%")
                  ->orWhere('last_name', 'like', "%" . $request->search . "%")
                  ->orWhere('phone', 'like', "%" . $request->search . "%")
                  ->orWhere('email', 'like', "%" . $request->search . "%");
            });
        }

        $contacts = $query->paginate(10);

        return view('admin.contacts.index', compact('contacts'));
    }

    /**
     * Export contacts to Excel
     */
    public function export(Request $request)
    {
        $query = Contact::with('branch')->orderBy('first_name');

        // Apply same search filter if present
        if ($request->has('search') && $request->search !== '') {
            $query->where(function ($q) use ($request) {
                $q->where('first_name', 'like', "%" . $request->search . "%")
                  ->orWhere('last_name', 'like', "%" . $request->search . "%")
                  ->orWhere('phone', 'like', "%" . $request->search . "%")
                  ->orWhere('email', 'like', "%" . $request->search . "%");
            });
        }

        $contacts = $query->get();

        $filename = 'contacts_' . date('Y-m-d_His') . '.xlsx';

        return Excel::download(new ContactsExport($contacts), $filename);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $branches = Branch::orderBy('id')->get();
        return view('admin.contacts.create', compact('branches'));
    }

    /**
     * Check for duplicate email (AJAX)
     */
    public function checkDuplicate(Request $request)
    {
        $email = $request->email;
        $excludeId = $request->exclude_id; // For edit mode

        if (empty($email)) {
            return response()->json(['exists' => false]);
        }

        $query = Contact::where('email', $email);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $exists = $query->exists();

        if ($exists) {
            $duplicate = $query->first();
            return response()->json([
                'exists' => true,
                'contact' => [
                    'name' => $duplicate->first_name . ' ' . $duplicate->last_name,
                    'phone' => $duplicate->phone,
                    'branch' => $duplicate->branch->name ?? '',
                ]
            ]);
        }

        return response()->json(['exists' => false]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'nullable|string|max:255',
            'job_title'  => 'nullable|string|max:255',  // ADD THIS
            'phone'      => 'required|string|max:50',
            'email'      => [
                'nullable',
                'email',
                Rule::unique('contacts', 'email')->whereNotNull('email'),
            ],
            'branch_id'  => 'required|exists:branches,id',
        ], [
            'email.unique' => 'A contact with this email already exists.',
        ]);

        Contact::create($request->only([
            'first_name', 'last_name', 'job_title', 'phone', 'email', 'branch_id'  // ADD 'job_title' HERE
        ]));

        return redirect()
            ->route('admin.contacts.index')
            ->with('success', 'Contact created successfully.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Contact $contact)
    {
        $branches = Branch::orderBy('id')->get();
        return view('admin.contacts.edit', compact('contact', 'branches'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Contact $contact)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'nullable|string|max:255',
            'job_title'  => 'nullable|string|max:255',  // ADD THIS
            'phone'      => 'required|string|max:50',
            'email'      => [
                'nullable',
                'email',
                Rule::unique('contacts', 'email')
                    ->ignore($contact->id)
                    ->whereNotNull('email'),
            ],
            'branch_id'  => 'required|exists:branches,id',
        ], [
            'email.unique' => 'A contact with this email already exists.',
        ]);

        $contact->update($request->only([
            'first_name', 'last_name', 'job_title', 'phone', 'email', 'branch_id'  // ADD 'job_title' HERE
        ]));

        return redirect()
            ->route('admin.contacts.index')
            ->with('success', 'Contact updated successfully.');
    }

    /**
     * Remove the specified resource from storage (REAL DELETE).
     */
    public function destroy(Contact $contact)
    {
        $contact->delete();

        return redirect()
            ->route('admin.contacts.index')
            ->with('success', 'Contact deleted successfully.');
    }
}
