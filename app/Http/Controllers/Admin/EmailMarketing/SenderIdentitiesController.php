<?php

namespace App\Http\Controllers\Admin\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Models\EmailMarketing\EmailSenderIdentity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin-managed allowlist of "from email" addresses that marketing users
 * are allowed to send campaigns from. The campaign builder presents these
 * as a dropdown — free-text from-emails are no longer accepted.
 *
 * Only one identity is the default. Setting a new default clears the
 * previous flag atomically.
 */
class SenderIdentitiesController extends Controller
{
    public function index(): View
    {
        $identities = EmailSenderIdentity::query()
            ->orderByDesc('is_default')
            ->orderByDesc('is_active')
            ->orderBy('email')
            ->get();

        return view('admin.email-marketing.senders.index', compact('identities'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;

        $identity = EmailSenderIdentity::create($data);
        if (! empty($data['is_default'])) {
            $this->markAsDefault($identity);
        }

        return back()->with('status', "Added {$identity->email} to the sender allowlist.");
    }

    public function update(Request $request, EmailSenderIdentity $identity)
    {
        $identity->update($this->validated($request, $identity->id));
        if ($request->boolean('is_default')) {
            $this->markAsDefault($identity);
        }

        return back()->with('status', "Updated {$identity->email}.");
    }

    public function destroy(EmailSenderIdentity $identity)
    {
        $identity->delete();

        return back()->with('status', "Removed {$identity->email} from the sender allowlist.");
    }

    public function setDefault(EmailSenderIdentity $identity)
    {
        $this->markAsDefault($identity);

        return back()->with('status', "Default sender set to {$identity->email}.");
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'email'      => ['required', 'email', 'max:191', Rule::unique('email_sender_identities', 'email')->ignore($ignoreId)],
            'name'       => ['required', 'string', 'max:191'],
            'reply_to'   => ['nullable', 'email', 'max:191'],
            'is_default' => ['nullable', 'boolean'],
            'is_active'  => ['nullable', 'boolean'],
            'notes'      => ['nullable', 'string', 'max:500'],
        ]) + [
            'is_default' => $request->boolean('is_default'),
            'is_active'  => $request->boolean('is_active', true),
        ];
    }

    private function markAsDefault(EmailSenderIdentity $identity): void
    {
        EmailSenderIdentity::where('id', '!=', $identity->id)->update(['is_default' => false]);
        $identity->update(['is_default' => true]);
    }
}
