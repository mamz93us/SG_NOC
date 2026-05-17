<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\EmailMarketing\EmailList;
use App\Models\EmailMarketing\EmailSubscriber;
use Illuminate\Http\Request;

class OptInConfirmController extends Controller
{
    public function confirm(Request $request, string $token)
    {
        // Tokens for opt-in are random strings stored on the pivot's
        // opt_in_token column. They don't expire by URL signing — we
        // enforce age via opt_in_sent_at + config('email_marketing.opt_in_token_ttl_days').
        $pivot = \DB::table('email_list_subscriber')
            ->where('opt_in_token', $token)
            ->first();

        if (! $pivot) {
            return response()->view('unsubscribe.invalid', [], 403);
        }

        $ttlDays = (int) config('email_marketing.opt_in_token_ttl_days', 30);
        if ($pivot->opt_in_sent_at && now()->diffInDays($pivot->opt_in_sent_at) > $ttlDays) {
            return response()->view('unsubscribe.invalid', [], 403);
        }

        $subscriber = EmailSubscriber::find($pivot->email_subscriber_id);
        $list = EmailList::find($pivot->email_list_id);
        if (! $subscriber || ! $list) {
            return response()->view('unsubscribe.invalid', [], 403);
        }

        // Promote pending → subscribed; record on subscriber and pivot.
        if ($subscriber->status === 'pending') {
            $subscriber->status = 'subscribed';
            $subscriber->confirmed_at = now();
            $subscriber->save();
        }

        \DB::table('email_list_subscriber')
            ->where('id', $pivot->id)
            ->update([
                'subscribed_at' => $pivot->subscribed_at ?: now(),
                'unsubscribed_at' => null,
                'opt_in_token' => null, // single-use
            ]);

        return view('unsubscribe.opt-in-confirmed', [
            'subscriber' => $subscriber,
            'list' => $list,
        ]);
    }
}
