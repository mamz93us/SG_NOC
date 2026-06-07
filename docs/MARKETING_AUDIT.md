# Email-marketing audit log

Every authenticated action on the email-marketing models is written to the shared
`activity_logs` table and shows up in **NOC → Activity Logs** (`/admin/activity-logs`,
`view-activity-logs` permission), filterable by **model type**:
`EmailCampaign`, `EmailList`, `EmailSubscriber`, `EmailTemplate`, `EmailSegment`,
`EmailTag`, `EmailSenderIdentity`, `EmailSuppression`.

## What's captured

- **create / update / delete** by a signed-in user — campaign edits, the full
  send → submit-for-approval → approve / reject → recall lifecycle (via the `status`
  before/after diff), list / template / segment / tag changes, and sender-allowlist +
  suppression changes.
- Explicit non-model actions: **`test_sent`** (campaign test email — who, to whom),
  **`subscribers_imported`** (one summary row per import), **`subscribers_exported`**
  (who exported which list — data-exfiltration accountability).
- Each row records **user, IP, user-agent**, and a **field-level before/after diff**.

## What's deliberately NOT captured

- The **system send pipeline** (status flips to `sending`/`sent`, counter bumps) and
  **public** open / click / unsubscribe / SNS events run without a signed-in user, so
  they never clutter the audit — it stays about who *did* what.
- **Bulk CSV imports** write a single summary row, not one per subscriber.
- Large template bodies (`rendered_html` / `design_json`) are recorded as `"[changed]"`
  rather than dumping the whole blob.
- Reads / page views are not logged (mutations only).

## Implementation

`App\Observers\EmailMarketingActivityObserver` — registered on the marketing models in
`AppServiceProvider::boot()`. It only writes when `Auth::check()` is true, scrubs noisy
counter/timestamp fields, and exposes `silently(fn () => …)` to suppress per-row writes
during bulk operations. Entries reuse the existing `ActivityLog` model, so no new viewer
is needed — open Activity Logs and filter by the `Email*` model types.
