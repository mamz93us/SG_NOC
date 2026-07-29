<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmtpRelayMessage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SMTP Relay Log — every message the NOC Postfix smarthost relayed to Amazon
 * SES (legacy Ricoh scan-to-email). Reads smtp_relay_messages, populated by the
 * `smtp-relay:ingest-log` scheduled command. Gated by `view-smtp-relay`.
 */
class SmtpRelayController extends Controller
{
    public function index(Request $request)
    {
        $now = CarbonImmutable::now();

        return view('admin.smtp-relay.index', [
            'cards' => [
                'today' => $this->window($now->startOfDay(), $now),
                '7d' => $this->window($now->subDays(7), $now),
                '30d' => $this->window($now->subDays(30), $now),
            ],
            'messages' => $this->filteredBase($request)
                ->with('attachments')
                ->latest('queued_at')
                ->paginate(30)
                ->withQueryString(),
            'filters' => $this->filters($request),
            'statuses' => SmtpRelayMessage::STATUSES,
            'rewrittenSender' => config('smtp_relay.rewritten_sender'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filteredBase($request)->with('attachments')->latest('queued_at')->limit(50000)->get();
        $filename = 'smtp_relay_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Queued At', 'Client IP', 'From', 'Recipients', 'Subject',
                'Size (bytes)', 'Attachments', 'Status', 'SES Message ID', 'Error',
            ]);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->queued_at?->format('Y-m-d H:i:s'),
                    $r->client_ip,
                    $r->mail_from,
                    $r->recipients,
                    $r->subject,
                    $r->size_bytes,
                    $r->attachments->pluck('filename')->implode(' | '),
                    $r->status,
                    $r->ses_message_id,
                    $r->error,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return array{count:int, sent:int, failed:int} */
    private function window(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $q = SmtpRelayMessage::query();
        if ($from) {
            $q->where('queued_at', '>=', $from);
        }
        if ($to) {
            $q->where('queued_at', '<=', $to);
        }

        return [
            'count' => (clone $q)->count(),
            'sent' => (clone $q)->where('status', SmtpRelayMessage::STATUS_SENT)->count(),
            'failed' => (clone $q)->whereIn('status', [
                SmtpRelayMessage::STATUS_BOUNCED,
                SmtpRelayMessage::STATUS_DEFERRED,
                SmtpRelayMessage::STATUS_REJECTED,
            ])->count(),
        ];
    }

    private function filteredBase(Request $request)
    {
        $f = $this->filters($request);
        $q = SmtpRelayMessage::query();

        if ($f['from']) {
            $q->where('queued_at', '>=', CarbonImmutable::parse($f['from'])->startOfDay());
        }
        if ($f['to']) {
            $q->where('queued_at', '<=', CarbonImmutable::parse($f['to'])->endOfDay());
        }
        if ($f['status']) {
            $q->where('status', $f['status']);
        }
        if ($f['q']) {
            $s = '%'.$f['q'].'%';
            $q->where(function ($w) use ($s) {
                $w->where('mail_from', 'like', $s)
                    ->orWhere('recipients', 'like', $s)
                    ->orWhere('subject', 'like', $s)
                    ->orWhere('client_ip', 'like', $s)
                    ->orWhere('ses_message_id', 'like', $s);
            });
        }

        return $q;
    }

    /** @return array{from:?string,to:?string,status:?string,q:?string} */
    private function filters(Request $request): array
    {
        $status = $request->query('status');

        return [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'status' => in_array($status, SmtpRelayMessage::STATUSES, true) ? $status : null,
            'q' => $request->query('q'),
        ];
    }
}
