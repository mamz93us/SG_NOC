<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Contact;
use App\Models\Branch;

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
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $branches = Branch::orderBy('id')->get();
        return view('admin.contacts.create', compact('branches'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'nullable|string|max:255',
            'phone'      => 'required|string|max:50',
            'email'      => 'nullable|email',
            'branch_id'  => 'required|exists:branches,id',
        ]);

        Contact::create($request->only([
            'first_name', 'last_name', 'phone', 'email', 'branch_id'
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
            'phone'      => 'required|string|max:50',
            'email'      => 'nullable|email',
            'branch_id'  => 'required|exists:branches,id',
        ]);

        $contact->update($request->only([
            'first_name', 'last_name', 'phone', 'email', 'branch_id'
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
