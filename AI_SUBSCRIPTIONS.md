# AI Subscriptions & Monthly Finance Reports

Per-person AI tool subscriptions (Claude, ChatGPT, Runway, CapCut, Magnific,
Semrush) tracked as ITAM licences, with two monthly reports finance can act on.

Everything lives on the existing `licenses` table — there is no separate
subscriptions module. A subscription is a licence with `license_type = 'ai'`
and `billing_cycle = 'monthly'`.

---

## What was added

### Schema (`licenses`)

| Column | Purpose |
|---|---|
| `license_type` | Widened from ENUM to `VARCHAR(20)`; gains **`ai`**. New types no longer need a migration. |
| `billing_cycle` | `one_time` (default) / `monthly` / `quarterly` / `annual`. |
| `payment_method` | `credit_card` / `wire_transfer` / `cash` / `other`, nullable. |
| `payment_account` | Free text — which card or bank account pays it (e.g. `Company Visa ••4821`). Shown to finance. |

`cost` keeps its existing meaning with one clarification: it is the price of
**one seat for one billing period**, VAT included. So the amount charged on a
renewal date is `cost × seats`.

`expiry_date` doubles as the **renewal anchor** for a recurring licence. It is
never rolled forward by a job — the reports project it forwards and backwards
from the stored day-of-month. A stored date that a cron has to advance silently
rewrites history the first time that cron misses a month.

### Reports

Both live under **ITAM → Reports**, gated by the existing `view-itam`
permission. Each has a month picker, a type filter, CSV export (UTF-8 with BOM,
so Excel opens Arabic names correctly) and a print layout.

**`/admin/itam/reports/subscriptions` — AI Subscription Usage**
One row per *purchased* seat, so idle seats appear next to used ones — they are
invoiced identically and they are the reason this report gets read. Annual and
quarterly licences are divided down to a monthly figure. This is a **run rate**
for budgeting. Also rolls up monthly cost per employee.

**`/admin/itam/reports/subscription-payments` — Subscription Payments Due**
Only licences whose renewal date lands inside the selected month, at the full
amount charged that day. This is the **payable**. Grouped by how it is paid:

- *Payment method not set* — sorted first; finance cannot action these.
- *Wire Transfer / Cash / Other* — somebody has to raise a payment.
- *Credit Card* — charges itself, listed for visibility only.

### Why two reports and not one

A run rate and a payable are different numbers and adding them double-counts.
An annual £1,200 licence is £100/month of run rate every month, and a £1,200
payable in exactly one of them. Each page states which one it is showing.

### Currencies

`EUR` was added to `App\Support\Currency` (Magnific invoices in euros). The AI
basket spans USD, EGP, SAR and EUR.

**Totals are never combined across currencies.** The NOC holds no FX rates, and
a wrong rate silently applied to a payment run is worse than four honest
subtotals. Finance converts at the rate on the payment date.

---

## Deploying

Standard workflow — commit and push locally, then on the VPS:

```bash
cd /home/azureuser/phonebook2 && git pull && php artisan migrate
```

Then load the September 2026 subscription sheet (safe to re-run — licences are
matched by name and updated in place, assignments are only created if missing):

```bash
php artisan db:seed --class=AiSubscriptionSeeder --force
```

The seeder prints what it did and warns about anything it could not resolve.

---

## Known data issues in the source sheet

The seeder reports these rather than silently picking a side:

- **Runway AI** lists the user as *Maria Metry* but the seat email as
  `farah.nasser@samirgroup.com`. The seat is assigned on the **email** — that is
  the account actually billed — and the mismatch is written into the licence's
  notes. Confirm which is correct with IT.
- **`ai-subscriptions@sssegypt.com`** ("Specialized Seamless Services") is a
  shared mailbox, not a person, so it matches no employee record. Its ChatGPT
  seat is still purchased and still billed, so it shows as an **idle seat** on
  the usage report. That is accurate, not a bug.
- **Hussien Shallaly** appears under `Hussien.Elsayed@sssegypt.com`. Matching is
  case-insensitive on email, so this resolves as long as the employee record
  carries that address.

## Payment methods are deliberately unset after seeding

Nobody specified which card or account pays these, and inventing that would put
a wrong instruction in front of finance. Every seeded subscription therefore has
no payment method, and the payments report flags them in red until someone sets
it at **/admin/itam/licenses** (edit the licence → *Payment Method* +
*Paid From*).

---

## Gotchas

- **The migration is driver-aware.** Widening `license_type` uses
  `ALTER … MODIFY COLUMN` on MySQL and a Blueprint `change()` elsewhere. Do not
  simplify it to a bare `DB::statement` — that is exactly what breaks the SQLite
  test connection (several older migrations in this repo already do, which is
  why a full `migrate` cannot run on SQLite).
- **A licence with no `expiry_date` can never appear on the payments report** —
  no anchor means no month can claim it. The report lists these separately under
  "no renewal date" rather than dropping them.
- **Renewal days clamp in short months.** A 31st anchor is charged on the 28th
  in February and the 30th in November.
- **Seat count is the billed quantity, not the assigned count.** Reducing a
  vendor seat means editing `seats` on the licence, not just unassigning a user.
