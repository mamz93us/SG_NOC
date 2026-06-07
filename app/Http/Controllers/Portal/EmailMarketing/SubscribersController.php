<?php

namespace App\Http\Controllers\Portal\EmailMarketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailMarketing\ImportSubscribersRequest;
use App\Http\Requests\EmailMarketing\StoreSubscriberRequest;
use App\Mail\EmailMarketing\DoubleOptInMail;
use App\Models\EmailMarketing\EmailList;
use App\Models\EmailMarketing\EmailSubscriber;
use App\Models\EmailMarketing\EmailTag;
use App\Services\EmailMarketing\CsvSubscriberImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SubscribersController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $listId = (int) $request->query('list_id', 0) ?: null;

        $query = EmailSubscriber::query()->with('tags')->latest();
        if ($q !== '') {
            $query->where(function ($q2) use ($q) {
                $q2->where('email', 'like', "%{$q}%")
                    ->orWhere('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%");
            });
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($listId) {
            $query->whereHas('lists', fn ($l) => $l->where('email_lists.id', $listId));
        }

        $subscribers = $query->paginate(50)->withQueryString();
        $lists = EmailList::orderBy('name')->get(['id', 'name']);

        return view('portal.email-marketing.subscribers.index', compact('subscribers', 'lists', 'q', 'status', 'listId'));
    }

    public function create(): View
    {
        return view('portal.email-marketing.subscribers.create', [
            'subscriber' => new EmailSubscriber,
            'lists' => EmailList::orderBy('name')->get(),
            'tags' => EmailTag::orderBy('name')->get(),
        ]);
    }

    public function store(StoreSubscriberRequest $request)
    {
        $data = $request->validated();
        $data['email'] = strtolower(trim($data['email']));
        $data['source'] = 'manual';
        $data['status'] = $data['status'] ?? 'subscribed';
        if ($data['status'] === 'subscribed' && empty($data['confirmed_at'])) {
            $data['confirmed_at'] = now();
        }

        $subscriber = EmailSubscriber::updateOrCreate(['email' => $data['email']], $data);
        $this->syncRelations($subscriber, $request);

        return redirect()->route('portal.marketing.subscribers.edit', $subscriber)
            ->with('status', 'Subscriber saved.');
    }

    public function edit(EmailSubscriber $subscriber): View
    {
        $subscriber->load('lists', 'tags');

        // Per-campaign history for this subscriber: every send + aggregated
        // open/click counts + last activity per send. Single query so the
        // page renders cheaply even for very active subscribers.
        $history = \DB::table('email_campaign_sends as s')
            ->join('email_campaigns as c', 'c.id', '=', 's.email_campaign_id')
            ->leftJoin(\DB::raw("(
                SELECT email_campaign_send_id,
                       SUM(event_type = 'Open')  AS opens,
                       SUM(event_type = 'Click') AS clicks,
                       MAX(created_at) AS last_activity
                FROM email_events GROUP BY email_campaign_send_id
            ) AS agg"), 'agg.email_campaign_send_id', '=', 's.id')
            ->where('s.email_subscriber_id', $subscriber->id)
            ->select(
                's.id as send_id',
                's.status as send_status',
                's.sent_at',
                's.delivered_at',
                's.error_message',
                's.ses_message_id',
                'c.id as campaign_id',
                'c.name as campaign_name',
                'c.subject as campaign_subject',
                'c.from_email',
                'c.sent_at as campaign_sent_at',
                'c.archived_at as campaign_archived_at',
                \DB::raw('COALESCE(agg.opens, 0)  as opens'),
                \DB::raw('COALESCE(agg.clicks, 0) as clicks'),
                'agg.last_activity',
            )
            ->orderByDesc(\DB::raw('COALESCE(s.sent_at, s.created_at)'))
            ->get();

        // Recent event timeline across ALL campaigns for this subscriber.
        $recentEvents = \App\Models\EmailMarketing\EmailEvent::query()
            ->join('email_campaign_sends as s', 's.id', '=', 'email_events.email_campaign_send_id')
            ->join('email_campaigns as c', 'c.id', '=', 's.email_campaign_id')
            ->where('email_events.email_subscriber_id', $subscriber->id)
            ->select(
                'email_events.id',
                'email_events.event_type',
                'email_events.url',
                'email_events.ip_address',
                'email_events.country_code',
                'email_events.country_name',
                'email_events.user_agent',
                'email_events.bounce_type',
                'email_events.bounce_subtype',
                'email_events.complaint_type',
                'email_events.raw_payload',
                'email_events.created_at',
                'c.id as campaign_id',
                'c.name as campaign_name',
                's.id as send_id',
            )
            ->orderByDesc('email_events.created_at')
            ->limit(100)
            ->get();

        return view('portal.email-marketing.subscribers.create', [
            'subscriber' => $subscriber,
            'lists' => EmailList::orderBy('name')->get(),
            'tags' => EmailTag::orderBy('name')->get(),
            'history' => $history,
            'recentEvents' => $recentEvents,
        ]);
    }

    public function update(StoreSubscriberRequest $request, EmailSubscriber $subscriber)
    {
        $data = $request->validated();
        $data['email'] = strtolower(trim($data['email']));
        $subscriber->update($data);
        $this->syncRelations($subscriber, $request);

        return redirect()->route('portal.marketing.subscribers.edit', $subscriber)
            ->with('status', 'Subscriber updated.');
    }

    public function destroy(EmailSubscriber $subscriber)
    {
        $subscriber->delete();

        return redirect()->route('portal.marketing.subscribers.index')
            ->with('status', 'Subscriber deleted.');
    }

    // ── Import flow ─────────────────────────────────────────────

    public function importForm(): View
    {
        return view('portal.email-marketing.subscribers.import', [
            'lists' => EmailList::orderBy('name')->get(),
        ]);
    }

    /**
     * Generates a starter CSV with the expected columns + a few example
     * rows so marketing users know exactly what shape to bring back.
     * No filesystem write — streamed inline.
     */
    public function importTemplate(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            // BOM so Excel opens UTF-8 cleanly
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['email', 'first_name', 'last_name']);
            fputcsv($out, ['ahmed.example@samirgroup.com', 'Ahmed', 'Saleh']);
            fputcsv($out, ['sara.example@samirgroup.com',  'Sara',  'Hassan']);
            fputcsv($out, ['leila.example@samirgroup.com', 'Leila', 'Mansour']);
            fclose($out);
        }, 'subscribers-import-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Content-Disposition' => 'attachment; filename="subscribers-import-template.csv"',
        ]);
    }

    public function importMap(ImportSubscribersRequest $request, CsvSubscriberImporter $importer)
    {
        // Store the file temporarily and return mapping screen.
        // Use the Storage facade to resolve the absolute path — Laravel 12's
        // default `local` disk roots at storage/app/private/, not storage/app/,
        // so hand-building the path from storage_path() breaks the read.
        $path = $request->file('file')->storeAs(
            'email-imports',
            uniqid('imp_', true).'.'.$request->file('file')->getClientOriginalExtension(),
            'local'
        );
        $absolute = \Illuminate\Support\Facades\Storage::disk('local')->path($path);
        $headers = $importer->previewHeaders($absolute);

        return view('portal.email-marketing.subscribers.import', [
            'lists' => EmailList::orderBy('name')->get(),
            'storedPath' => $path,
            'absolutePath' => $absolute,
            'headers' => $headers,
            'email_list_id' => (int) $request->input('email_list_id'),
        ]);
    }

    public function importStore(Request $request, CsvSubscriberImporter $importer)
    {
        $data = $request->validate([
            'email_list_id' => ['required', 'integer', 'exists:email_lists,id'],
            'stored_path' => ['required', 'string'],
            'email_col' => ['required', 'integer', 'min:0'],
            'first_name_col' => ['nullable', 'integer', 'min:0'],
            'last_name_col' => ['nullable', 'integer', 'min:0'],
            'skip_header' => ['nullable', 'boolean'],
        ]);

        $list = EmailList::findOrFail($data['email_list_id']);
        $abs = \Illuminate\Support\Facades\Storage::disk('local')->path($data['stored_path']);

        $mapping = ['email' => (int) $data['email_col']];
        if (isset($data['first_name_col']) && $data['first_name_col'] !== null && $data['first_name_col'] !== '') {
            $mapping['first_name'] = (int) $data['first_name_col'];
        }
        if (isset($data['last_name_col']) && $data['last_name_col'] !== null && $data['last_name_col'] !== '') {
            $mapping['last_name'] = (int) $data['last_name_col'];
        }

        // Silence per-row audit rows during the bulk import; record one summary instead.
        $stats = \App\Observers\EmailMarketingActivityObserver::silently(fn () => $importer->import(
            $list,
            $abs,
            $mapping,
            [],
            $request->boolean('skip_header', true),
            $request->user()->id,
        ));

        \App\Models\ActivityLog::create([
            'model_type' => 'EmailList',
            'model_id' => $list->id,
            'action' => 'subscribers_imported',
            'changes' => [
                'list' => $list->name,
                'imported' => $stats['imported'] ?? null,
                'skipped_invalid' => $stats['skipped_invalid'] ?? null,
                'skipped_suppressed' => $stats['skipped_suppressed'] ?? null,
            ],
            'user_id' => $request->user()->id,
        ]);

        // For double-opt-in lists, send confirmations to newly pending subscribers.
        if ($list->double_opt_in) {
            $this->sendOptInsForList($list);
        }

        // Clean up uploaded file
        @unlink($abs);

        return redirect()->route('portal.marketing.lists.show', $list)
            ->with('status', sprintf(
                'Imported %d, skipped %d invalid, %d suppressed.',
                $stats['imported'],
                $stats['skipped_invalid'],
                $stats['skipped_suppressed'],
            ));
    }

    private function syncRelations(EmailSubscriber $subscriber, Request $request): void
    {
        $listIds = (array) $request->input('list_ids', []);
        $tagIds = (array) $request->input('tag_ids', []);

        if (! empty($listIds)) {
            $payload = [];
            foreach ($listIds as $listId) {
                $payload[$listId] = [
                    'subscribed_at' => now(),
                ];
            }
            $subscriber->lists()->syncWithoutDetaching($payload);
        }

        $subscriber->tags()->sync($tagIds);
    }

    private function sendOptInsForList(EmailList $list): void
    {
        $pivots = \DB::table('email_list_subscriber')
            ->where('email_list_id', $list->id)
            ->whereNull('subscribed_at')
            ->whereNull('opt_in_sent_at')
            ->get();

        foreach ($pivots as $pivot) {
            $subscriber = EmailSubscriber::find($pivot->email_subscriber_id);
            if (! $subscriber) {
                continue;
            }
            $token = $pivot->opt_in_token ?: Str::random(40);
            \DB::table('email_list_subscriber')->where('id', $pivot->id)->update([
                'opt_in_token' => $token,
                'opt_in_sent_at' => now(),
            ]);

            $url = route('email.opt-in.confirm', ['token' => $token]);
            try {
                Mail::to($subscriber->email)->send(new DoubleOptInMail($subscriber, $list, $url));
            } catch (\Throwable $e) {
                \Log::warning("Opt-in email failed for {$subscriber->email}: ".$e->getMessage());
            }
        }
    }
}
