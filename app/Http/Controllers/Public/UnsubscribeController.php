<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\EmailMarketing\EmailCampaign;
use App\Models\EmailMarketing\EmailList;
use App\Models\EmailMarketing\EmailSubscriber;
use App\Services\EmailMarketing\MergeTagRenderer;
use App\Services\EmailMarketing\SuppressionManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UnsubscribeController extends Controller
{
    public function show(Request $request, string $token)
    {
        if (! $request->hasValidSignature()) {
            return response()->view('unsubscribe.invalid', [], 403);
        }

        [$subscriberId, $listId] = MergeTagRenderer::decodeToken($token);
        $subscriber = $subscriberId ? EmailSubscriber::find($subscriberId) : null;
        $list = $listId ? EmailList::find($listId) : null;

        if (! $subscriber) {
            return response()->view('unsubscribe.invalid', [], 403);
        }

        return view('unsubscribe.confirm', [
            'token' => $token,
            'subscriber' => $subscriber,
            'list' => $list,
        ]);
    }

    public function confirm(Request $request, string $token, SuppressionManager $suppressions)
    {
        // POSTs from List-Unsubscribe one-click also use the signed URL.
        if (! $request->hasValidSignature() && ! $request->session()->has('_token')) {
            // Best-effort: allow one-click POSTs without signature so the
            // RFC 8058 flow works (the URL itself was signed when it was
            // sent). hasValidSignature returns true for POST as long as
            // the query parameters carry the signature.
            return response()->view('unsubscribe.invalid', [], 403);
        }

        [$subscriberId, $listId] = MergeTagRenderer::decodeToken($token);
        $subscriber = $subscriberId ? EmailSubscriber::find($subscriberId) : null;
        $list = $listId ? EmailList::find($listId) : null;
        if (! $subscriber) {
            return response()->view('unsubscribe.invalid', [], 403);
        }

        DB::transaction(function () use ($subscriber, $list, $suppressions) {
            if ($list) {
                $subscriber->lists()->updateExistingPivot($list->id, [
                    'unsubscribed_at' => now(),
                ]);

                // Bump per-campaign unsubscribes for recent campaigns on this list
                EmailCampaign::query()
                    ->where('email_list_id', $list->id)
                    ->where('status', 'sent')
                    ->where('sent_at', '>=', now()->subDays(30))
                    ->update(['total_unsubscribes' => DB::raw('total_unsubscribes + 1')]);
            } else {
                // Global unsubscribe — mark all pivots
                $subscriber->lists()->wherePivotNull('unsubscribed_at')
                    ->update(['email_list_subscriber.unsubscribed_at' => now()]);
            }

            // If subscriber has no active list memberships left, flip global status.
            $activeMemberships = $subscriber->lists()->wherePivotNull('unsubscribed_at')->count();
            if ($activeMemberships === 0 && $subscriber->status === 'subscribed') {
                $subscriber->status = 'unsubscribed';
                $subscriber->unsubscribed_at = now();
                $subscriber->save();
            }

            $suppressions->add($subscriber->email, 'manual', 'user-unsubscribe', null,
                $list ? "Unsubscribed from list #{$list->id}" : 'Global unsubscribe');
        });

        return view('unsubscribe.confirmed', [
            'subscriber' => $subscriber,
            'list' => $list,
        ]);
    }
}
