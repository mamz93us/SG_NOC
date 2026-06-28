# IT Ticket Portal — tracking proxy (`it.samirgroup.net`)

`it.samirgroup.net` points at this NOC app. Every visit is **logged for analytics**,
then the visitor is **forwarded** to the IT ticketing backend:

```
https://sgprd.samirgroup.com/AssistantApp/faces/LoginPage.jsf
```

The ticketing app (Oracle ADF/JSF) is **not** modified — the NOC only sits in front
of it. The destination URL comes only from `.env`, never the request, so the forward
can't be abused as an open redirect.

## How the request flows

```
employee browser ──▶ it.samirgroup.net (this app)
                       │  1. resolve/mint visitor cookie (tv_sid)
                       │  2. log a ticket_visits row (best-effort, never blocks)
                       │  3. forward
                       ▼
              https://sgprd.samirgroup.com/AssistantApp/...
```

- **Entry routes:** `GET it.samirgroup.net/` (`ticket.forward`) and a host-independent
  `GET /go` (`ticket.go`) — both hit `TicketForwardController`.
- **Logging never blocks the forward.** The visit insert is a single indexed write
  wrapped in try/catch; if it throws for any reason the visitor is still forwarded and
  the error goes to `laravel.log`.
- **Bots / uptime monitors** are forwarded but kept out of the stats (no row written).

## DNS / TLS / proxy wiring (ops)

- **DNS:** point `it.samirgroup.net` (A/CNAME) at the NOC host (or the CF/edge that
  fronts it). The app serves the subdomain via the `Route::domain()` group — no extra
  vhost needed beyond the existing one, as long as the host reaches this app.
- **TLS:** issue a cert for `it.samirgroup.net` (Let's Encrypt, or the existing
  `*.samirgroup.net` wildcard).
- **Behind Cloudflare / a load balancer:** `TrustProxies` is already set to `*` in
  `bootstrap/app.php`, so `X-Forwarded-For` is honoured — `$request->ip()` is the real
  client IP, which is what branch resolution and the IP column use.

## Redirect vs proxy mode

Set in `.env` (`TICKET_FORWARD_MODE`):

| Mode | Behaviour | Notes |
|------|-----------|-------|
| `redirect` (default) | HTTP 302 to the ticketing URL | Simple, instant. The long ADF URL shows in the address bar. |
| `proxy` | Best-effort reverse proxy (Guzzle) so `it.samirgroup.net` stays in the bar | **Experimental.** ADF/JSF is stateful — view-state, window-ids and session cookies are origin-scoped. The login GET proxies, but post-login round-trips need full cookie + form passthrough and base-href rewriting. If proxying fails it falls back to a 302. Prefer `redirect` in production unless/until this is hardened. |

## Branch resolution (CIDR → branch)

Edit the map in [`config/ticket_tracking.php`](config/ticket_tracking.php) under
`branch_cidrs`. Both IPv4 and IPv6 CIDRs are supported; the first match wins, otherwise
the branch is recorded as `unknown`.

```php
'branch_cidrs' => [
    'Jeddah'    => ['10.10.0.0/16'],
    'Riyadh'    => ['10.20.0.0/16'],
    // ... replace placeholders with each office's real WAN/LAN ranges
],
```

`App\Services\Ticketing\BranchResolver` does the matching; it's unit-tested in
`tests/Unit/BranchResolverTest.php`.

## Privacy

- `ANALYTICS_ANONYMIZE_IP=true` masks the last IPv4 octet (`10.1.2.0`) / IPv6 suffix
  (keeps the first 48 bits) **before** the row is written. Branch resolution still runs
  on the real IP first, so anonymisation doesn't lose branch attribution.
- `TICKET_IGNORE_BOTS=true` keeps obvious bots/monitors out of analytics (still
  forwarded). The needle list lives in `config/ticket_tracking.php`.
- GeoIP (`country`/`city`) is **off by default** and pluggable: set
  `TICKET_GEOIP_ENABLED=true` and point `TICKET_GEOIP_RESOLVER` at a class with
  `public function resolve(string $ip): array` returning `['country' => ?, 'city' => ?]`.
  When disabled or missing it's skipped cleanly.

## Performance / async note

`TICKET_ASYNC_LOGGING=false` (default) writes the visit inline — a single indexed
insert, effectively instant. There is a queued path (`LogTicketVisitJob`) behind
`TICKET_ASYNC_LOGGING=true`, **but** production runs no dedicated queue worker
(scheduler-as-worker), so an async visit only persists on the next queue drain. Leave it
off unless you add a worker.

## Reading the dashboard

**Admin → General Settings menu → "Ticket Portal Stats"**
(`/admin/ticket-stats`, gated by `manage-settings`).

- **KPI cards** — total visits + unique visitors for today / 7d / 30d / all-time.
  Unique = distinct `tv_sid` cookie, falling back to IP.
- **Visits per day** — line chart, last 30 days.
- **By branch** — bar chart + table.
- **By browser & device** — doughnut charts.
- **Peak hours** — hour-of-day × day-of-week heatmap (last 30 days).
- **Recent visits** — paginated table with a **From / To / Branch** filter.
- **Export CSV** — exports the *filtered* set.

### JSON API (for other NOC widgets)

```
GET /api/ticket-stats?range=7d&branch=Jeddah     (auth + manage-settings)
range ∈ today | 7d | 30d | all
→ { range, branch, total, unique, byBranch{}, byDevice{} }
```

## Demo data

```sh
php artisan db:seed --class=TicketVisitSeeder   # ~30 days of realistic visits
```

## Files

| Concern | File |
|---|---|
| Migration | `database/migrations/2026_06_27_000001_create_ticket_visits_table.php` |
| Config + CIDR map | `config/ticket_tracking.php` |
| Model | `app/Models/TicketVisit.php` |
| Forward route | `app/Http/Controllers/TicketForwardController.php` |
| Dashboard / API / CSV | `app/Http/Controllers/Admin/TicketStatsController.php` |
| Visit recorder | `app/Services/Ticketing/TicketVisitRecorder.php` |
| Branch resolver | `app/Services/Ticketing/BranchResolver.php` |
| UA parser | `app/Support/UserAgentParser.php` |
| Async job | `app/Jobs/Ticketing/LogTicketVisitJob.php` |
| Dashboard view | `resources/views/admin/ticket-stats/index.blade.php` |
| Factory / seeder | `database/factories/TicketVisitFactory.php`, `database/seeders/TicketVisitSeeder.php` |
| Tests | `tests/Unit/BranchResolverTest.php`, `tests/Feature/TicketForwardTest.php`, `tests/Feature/TicketStatsTest.php` |
