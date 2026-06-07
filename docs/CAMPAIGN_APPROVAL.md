# Email campaign approval (IT gate)

Campaigns sent from the marketing portal that reach **external** recipients must be
approved by IT (super_admin) before they send. **Internal-only** campaigns — every
recipient on an internal domain — send immediately, no approval.

## How it works

- On **Send now** / **Schedule**, `CampaignApprovalService` resolves the campaign's
  recipients and checks their domains (read-only — it never creates subscriber rows).
- **All internal** (`samirgroup.com` / `sssegypt.com` by default) → status goes straight
  to `scheduled`; the per-minute dispatcher sends it as normal.
- **Any external recipient** (or the global override is on) → the campaign is parked in
  `pending_approval`. The dispatcher only picks up `scheduled`/`sending`, so it never
  sends. super_admins are emailed (`CampaignAwaitingApproval`).
- A super_admin reviews it at **NOC → Email Marketing → Settings → Approvals**
  (`/admin/email-marketing/approvals`):
  - **Approve** → status becomes `scheduled` (keeps the requested send time) and it sends;
    the creator is emailed (`CampaignApproved`).
  - **Reject** (with a reason) → status returns to `draft`; the creator is emailed the
    reason (`CampaignRejected`) and can edit + resubmit.
- The marketing user can **recall** a pending campaign back to draft themselves.

Defense in depth: even if a campaign's status were set to `scheduled` by another path,
`CampaignDispatcher::tick()` re-checks `requires_approval` + `approved_at` and re-parks it.

## Configuration (NOC → Email Marketing → Settings → Campaign Approval)

- **Internal recipient domains** — comma-separated; defaults to `samirgroup.com, sssegypt.com`.
- **Require approval for all campaigns** — override that forces approval even for
  internal-only campaigns (the "approver can override" option).

## Who approves

super_admin only, enforced by `isSuperAdmin()` checks in `CampaignApprovalsController`.
To change the approver set, swap those checks for a permission gate (e.g. a new
`approve-email-campaigns` permission) and update the route group.

## Notifications

Sent **inline** (not queued) so they arrive even though production runs no dedicated
queue worker, and wrapped in try/catch so a mail hiccup never blocks the submit/decision.

## Deploy

`php artisan migrate` adds the `pending_approval` status, the approval columns on
`email_campaigns`, and the two settings columns. The status-enum widen is MySQL-only
(matches the repo's other `MODIFY COLUMN` migrations).
