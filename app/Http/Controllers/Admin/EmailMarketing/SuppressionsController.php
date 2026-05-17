<?php

namespace App\Http\Controllers\Admin\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Models\EmailMarketing\EmailSuppression;
use App\Services\EmailMarketing\SuppressionManager;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SuppressionsController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $reason = (string) $request->query('reason', '');

        $query = EmailSuppression::query()->latest('updated_at');
        if ($q !== '') {
            $query->where('email', 'like', '%'.$q.'%');
        }
        if ($reason !== '') {
            $query->where('reason', $reason);
        }

        $suppressions = $query->paginate(50)->withQueryString();

        return view('admin.email-marketing.suppressions', [
            'suppressions' => $suppressions,
            'q' => $q,
            'reason' => $reason,
        ]);
    }

    public function store(Request $request, SuppressionManager $manager): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $manager->add($data['email'], 'manual', 'admin', $request->user()?->id, $data['notes'] ?? null);

        return back()->with('status', "Added {$data['email']} to suppression list.");
    }

    public function destroy(EmailSuppression $suppression, SuppressionManager $manager): \Illuminate\Http\RedirectResponse
    {
        $manager->remove($suppression->email);

        return back()->with('status', "Removed {$suppression->email} from suppression list.");
    }

    public function import(Request $request, SuppressionManager $manager): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);
        $added = 0;
        if (($h = fopen($request->file('file')->getRealPath(), 'r')) !== false) {
            while (($row = fgetcsv($h)) !== false) {
                $email = trim((string) ($row[0] ?? ''));
                if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $manager->add($email, 'manual', 'import', $request->user()?->id);
                    $added++;
                }
            }
            fclose($h);
        }

        return back()->with('status', "Imported {$added} addresses into suppression list.");
    }
}
