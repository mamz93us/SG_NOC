<?php

namespace App\Observers;

use App\Models\Contact;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class ContactObserver
{
    /**
     * Handle the Contact "created" event.
     */
    public function created(Contact $contact): void
    {
        if (Auth::check()) {
            ActivityLog::create([
                'model_type' => 'Contact',
                'model_id' => $contact->id,
                'action' => 'created',
                'changes' => $contact->toArray(),
                'user_id' => Auth::id(),
            ]);
        }
    }

    /**
     * Handle the Contact "updated" event.
     */
    public function updated(Contact $contact): void
    {
        if (Auth::check()) {
            ActivityLog::create([
                'model_type' => 'Contact',
                'model_id' => $contact->id,
                'action' => 'updated',
                'changes' => [
                    'old' => $contact->getOriginal(),
                    'new' => $contact->getChanges(),
                ],
                'user_id' => Auth::id(),
            ]);
        }
    }

    /**
     * Handle the Contact "deleted" event.
     */
    public function deleted(Contact $contact): void
    {
        if (Auth::check()) {
            ActivityLog::create([
                'model_type' => 'Contact',
                'model_id' => $contact->id,
                'action' => 'deleted',
                'changes' => $contact->toArray(),
                'user_id' => Auth::id(),
            ]);
        }
    }
}
