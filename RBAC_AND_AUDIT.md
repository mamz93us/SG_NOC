# Role-Based Access Control & Audit Trail

How access is decided in the NOC, and how every change is recorded.

Pages:

| Page | Permission | What it is |
|---|---|---|
| `/admin/roles` | `manage-roles` | Create / edit / delete roles, choose which app surfaces each reaches |
| `/admin/permissions` | `manage-permissions` | The role × permission matrix |
| `/admin/users` | `manage-users` | Assign a role to a person |
| `/admin/users/{user}/permissions` | `manage-permissions` | Per-person exceptions on top of their role |
| `/admin/activity-logs` | `view-activity-logs` | The audit trail |

---

## How a permission is decided

```
is the role flagged is_super?  ──yes──►  ALLOWED (every permission, including future ones)
          │ no
          ▼
is there a per-user DENY row?  ──yes──►  REFUSED
          │ no
          ▼
is there a per-user GRANT row? ──yes──►  ALLOWED
          │ no
          ▼
does role_permissions have it? ──yes──►  ALLOWED
          │ no
          ▼
        REFUSED  (and the denial is written to activity_logs)
```

In short: **effective = role ∪ grants − denies**, and a superuser short-circuits the lot.

One implementation of that rule, in `User::hasPermission()`. `EnsurePermission`
(the `permission:` route middleware), `Gate::before` + `Gate::define` (what
`@can` in a Blade view uses), and `User::effectivePermissions()` (what the
per-user screen displays) all route through it, so the screen and the gate can
never disagree.

### Per-user exceptions

Three states per permission, on `/admin/users/{user}/permissions`:

- **Inherit** — no row; follows the role, so a later change to the role reaches
  this person too.
- **Grant** — allowed even though the role does not say so.
- **Deny** — refused even though the role does say so. Deny always wins.

A redundant row (granting what the role already gives, denying what it never
gave) is dropped on save. That is deliberate: a redundant row would freeze this
person's access against future role changes.

> **History.** Until 2026-09 *any* row put the user in an allow-list mode where
> their role was ignored entirely — so granting one extra permission silently
> revoked every other one they had. The `effect` column already existed for
> grant/deny; nothing read it. The conversion migration wrote an explicit `deny`
> row for every role permission such a user did *not* hold, so nobody's effective
> access changed on deploy.

---

## Roles

Roles are rows in `roles`, not hardcoded strings. `users.role` and
`role_permissions.role` hold the **slug**, not an id — that is what let this
change land without rewriting ~40 queries, two portals and 251 route gates.

| Column | Meaning |
|---|---|
| `slug` | What `users.role` stores. Immutable for a system role: jobs, the notification router and the approval chain match on this string. |
| `name`, `description` | Display only. Editable for every role. |
| `surfaces` | JSON array — which app surfaces this role may reach. |
| `landing` | Which of those surfaces sign-in sends them to. |
| `is_super` | Implicit grant of every permission. Not editable in the UI: a screen that can mint a second superuser is an escalation path. |
| `is_system` | Shipped with the app — can be renamed and re-permissioned, never re-slugged or deleted. |

### Surfaces

A role can hold **several** surfaces and lands on one of them.

| Surface | Where |
|---|---|
| `noc_admin` | `/admin` — the full admin area |
| `noc_portal` | `/portal` — request forms and self-service |
| `hr_portal` | the `hr` subdomain |
| `marketing_portal` | the `em` subdomain |
| `browser_portal` | remote browser sessions only |

Two things fall out of the surfaces rather than being configured separately:

- **Portal-only** — a role without `noc_admin` never sees the admin chrome
  (`Role::usesPortal()`). This replaced a hardcoded `browser_user|hr|marketing`
  list, so a custom role now gets the same treatment.
- **2FA bypass** — a role that reaches `browser_portal` and nothing beyond the
  portal hub skips the app's 2FA (`Role::onlyBrowserAccess()`). Deliberately
  narrow: any role that also reaches the admin area, the HR workspace or the
  marketing portal keeps the second factor. This replaced a check against the
  literal `browser_user` slug, which would have held a second browser-only role
  at 2FA enrolment for a session that can only open a web browser.

The landing surface must be one of the granted surfaces — otherwise sign-in sends
someone to a host whose isolation middleware then 404s them on their own home
page. The form enforces it client-side and `RoleController` re-checks it.

### Guard rails

- Only a superuser can assign a superuser role.
- The last remaining superuser cannot be demoted or deleted — `manage-users` and
  `manage-permissions` are held by no other role by default, so that would strand
  the only screens able to undo it.
- A role assigned to anyone cannot be deleted; move its users first. An orphaned
  `users.role` reads as portal-only, which would quietly remove the admin area
  from those people.
- A system role cannot be deleted or re-slugged.

---

## The permission registry

`RolePermission::allPermissions()` is the single source of truth: it is what the
matrix renders, what `Gate::define` registers, and what a matrix save is allowed
to rewrite.

**A route gate needs a registry entry.** A `permission:some-slug` gate with no
entry means:

- no checkbox anywhere, so no role can be given it;
- no `Gate::define`, so `@can('some-slug')` is false for everyone but a superuser
  — which hides the nav item even from someone whose role holds the grant.

`RolePermission::unregisteredSlugs()` reports grants in the database for slugs
missing from the registry, and both `/admin/roles` and `/admin/permissions`
display that count in a warning banner. Check it after adding a subsystem.

> **The bug this replaced.** Seventeen live permissions —
> `view-phone-firmware`, `manage-phones`, `view-server-status`,
> `view-branch-agents`, `view-downloads`, `manage-radius`, `view-voice-mesh`,
> `view-voice-quality`, `view-printer-usage`, `manage-devices`,
> `manage-printer-alerts` and others — were enforced on routes and seeded by
> their own migrations but never added to the registry. The matrix save called
> `RolePermission::truncate()` and re-inserted only registered slugs, so **every
> save silently destroyed those grants**, locking around eleven subsystems to
> super_admin with no UI left to restore them. Saving now goes through
> `RolePermission::syncRoles()`, which deletes only rows for slugs it can also
> display — so a future omission degrades to "not editable yet" instead of
> "destroyed".

The matrix form also posts a `roles_present[]` marker per rendered role. An
unchecked checkbox posts nothing, so without it a role with every box cleared
looked identical to a role that was not on the form — and revoking a role's last
permission was impossible.

---

## Audit trail

Everything lands in `activity_logs`.

### What is recorded

- **Every model write** — create, update, delete, soft-delete (`trashed`) and
  restore, for all ~188 audited models. One `AuditObserver` is attached to every
  class in `app/Models` at boot by discovery, so a new model is audited the day it
  is written rather than the day someone remembers to add an observer.
- **Auth events** — sign-in, sign-out, failed sign-in, lockout.
- **Permission denials** — `permission_denied`, with the required permission, the
  role, and the path.
- **Role and permission changes** — as deltas (added / removed), not a dump of
  130 slugs per role.
- **Semantic events** the four remaining side-effect observers still write:
  `asset_returned`, `auto_escalated_from_noc`, `auto_resolved_by_noc`,
  `termination_cascade`, `api_failed` — facts a column diff cannot express.

Reads are audited only where a read is the event: credentials, AvePoint and
offboarding downloads, and the access gateway each have their own purpose-built
tables. Auditing every page view would write millions of rows a month into the
same MySQL that runs the queue, the cache and the sessions.

### Secrets

Redaction is by **column name**, never by inspecting the value — see
`config('audit.redact')`. A rotated secret logs `[redacted]` on both sides, which
still records *that* it changed. The audit log must never become the one place a
secret is readable in plaintext at `/admin/activity-logs`.

### Unattended writes

The scheduler and the queue do most of the work in this app. Those runs have no
authenticated user, and:

- the 20 per-model observers this replaced were each wrapped in
  `if (Auth::check())`, so they recorded **nothing** — the identity sync, the HR
  import, the Sophos sync and offboarding left no trace at all;
- `ActivityLog::log()` fell back to `User::orderBy('id')->first()`, booking every
  scheduled job to whoever signed up first — worse than no name, because it reads
  as a real person's action.

Now an unattended write is recorded with `user_id = null` and an `actor_label` of
`console: <command>`, so the log says `console: biotime:sync` rather than naming
somebody who was asleep. Filter for them with **User → System / scheduled**.

### Suppressing it

For bulk work where one summary row is the honest record and ten thousand
per-row rows are not:

```php
Auditor::withoutAuditing(fn () => $importer->import($file));
```

Nested calls are counted, so an inner block cannot re-enable the outer one.

### Excluding a model

Add it to `config('audit.exclude')` with a note saying why. The list is telemetry
firehoses (SNMP samples, syslog, tunnel checks, attendance punches), rebuildable
roll-ups, and the purpose-built audit tables that already record their own detail.
Nothing in it is a business record.

`config('audit.ignore')` is the other lever: a change to only those columns is
not an audit event. Without it, every poll that stamps `last_seen_at` on a host
would write a row and bury the real edits.

### Retention

`activity-log:prune` runs nightly at 03:40, deleting in bounded chunks so it
never holds locks long enough to stall the queue.

| | Default | Env |
|---|---|---|
| Ordinary record edits | 730 days | `AUDIT_RETENTION_DAYS` |
| Security events | 1825 days | `AUDIT_SECURITY_RETENTION_DAYS` |

Security events are kept longer because they are what a privilege-escalation
review actually reads, and they are a small fraction of the rows. The security
window is floored at the ordinary one — a shorter one would drop sign-ins before
routine edits, the opposite of the intent. `--dry-run` reports without deleting.
The prune writes its own row, so a gap in the log is explainable.

### Indexes

The add-audit-columns migration added `created_at`, `(user_id, created_at)`,
`(action, created_at)` and `(model_type, created_at)`. Before that the table had
only `(model_type, model_id)`, and every filter on the audit page — plus its
default sort — was a full table scan on the database that also runs the queue,
the cache and the sessions.

---

## Adding a permission

1. Add the slug to `RolePermission::allPermissions()` under the right category.
   **This step is not optional** — see the registry section above.
2. Gate the routes with `permission:your-slug` (comma-separated is OR).
3. Gate the nav item with `@can('your-slug')`.
4. Write a migration granting it to the roles that should have it
   (`RolePermission::firstOrCreate`), matching the pattern in
   `database/migrations/*_add_*_permissions.php`.
5. Load `/admin/roles` and confirm the unregistered-slugs banner is absent.

## Adding a surface

Only if a genuinely new host or app area appears. Add it to `Role::SURFACES`,
`Role::SURFACE_HINTS` and `Role::SURFACE_ROUTES`, then teach the matching
`Enforce*HostIsolation` middleware about it. The role form picks up the new
option automatically.

---

## Tests

`tests/Unit/Rbac/` and `tests/Unit/Audit/` — 48 tests covering permission
resolution, grant/deny precedence, the surface and landing rules, redaction, and
the regression that the matrix save cannot destroy an unregistered grant.

They build their tables directly (`RbacTestSchema`) rather than using
`RefreshDatabase`, because 18 migrations in this repo issue raw MySQL
`ALTER TABLE … MODIFY COLUMN`, which SQLite rejects — so the full migration set
cannot run on the `:memory:` connection `phpunit.xml` configures. That predates
this work; `tests/Feature/Api/HrApiTest.php` fails the same way.
