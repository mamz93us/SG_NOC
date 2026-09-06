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

**`/admin/itam/reports/subscriptions-by-department` — Cost by Department**
Each seat charged to the department of the person holding it, showing both the
monthly run rate and this month's share of what renews. The unattributable seats
get named buckets — *No department set*, *Devices (no employee)*, *Unassigned
seats*, *Deleted records* — rather than being dropped, so the parts always add
up to the whole. Two CSVs: department summary, and per-seat detail.

The department split of what is due is an **allocation, not a set of payments**.
A licence is one indivisible charge on one card and finance still pays it once,
from the Payments Due report. Because a licence charges `cost × seats`, one
seat's share is exactly the per-seat cost, so the department shares always sum
back to the payment total — which is what makes them safe to recharge against.

### Why two reports and not one

A run rate and a payable are different numbers and adding them double-counts.
An annual £1,200 licence is £100/month of run rate every month, and a £1,200
payable in exactly one of them. Each page states which one it is showing.

### Currencies and the combined total

`EUR` was added to `App\Support\Currency` (Magnific invoices in euros). The AI
basket spans USD, EGP, SAR and EUR.

Both reports have a **Total in** selector. The per-currency subtotals are always
shown and are the fact — those are the amounts actually charged. The combined
figure sits **alongside** them and is an estimate at one rate on one day.

### Where the rate comes from

`exchange_rates`, edited at **/admin/itam/exchange-rates** (`manage-itam` to
save, `view-itam` to read). One row per currency: *units of that currency per 1
USD*, plus the date it applies to, the source, and who entered it. Cross-rates
go through the base, so EGP→SAR is derived from the two USD rates rather than
being a third number that can drift.

**Nothing fetches a live rate, deliberately.** The NOC cannot resolve most
public hosts (split-brain DNS), and a report that returns a different total on
Tuesday than it did on Monday is not something finance can reconcile. The rate
is a stored decision with a date and an owner.

Until a currency is entered, `config/currency.php` supplies an indicative
fallback and **every total built on it is labelled "indicative — not reviewed"**
— on the screen, in the drill-down, and as a *Rate Basis* column in the CSV. SAR
is pegged at 3.75 and safe; EGP and EUR need a human before anything goes to
finance.

A currency with no rate at all is **excluded from the combined total and named
in red**, because a total that quietly dropped a currency would understate what
is owed.

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

## Payment method

IT confirmed the whole AI basket — and Adobe — is paid by **company credit
card**, so the seeder sets `payment_method = credit_card` on all seven. They
land in the payments report's self-charging group: visible to finance for the
month's spend, but needing no payment raised.

**Which** card is not recorded. `payment_account` stays empty until somebody
says, because a wrong card in front of finance is worse than a blank one. Fill
it in per licence at **/admin/itam/licenses** (*Paid From*) — but note that
re-running the seeder rewrites the fields it owns, so treat the seeder as the
source of truth for cost, seats, renewal date and payment method.

Adobe is **not** part of the AI sheet, so the seeder never creates it — its
cost, seats and renewal date are not ours to invent. It only flips an existing
Adobe licence to Credit Card, matched by name (`ALSO_CARD_PAID`). If no Adobe
licence exists yet the seeder says so and moves on; create it at
**/admin/itam/licenses** with its real figures, then re-run the seeder (or just
set the method in the form).

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
- **Exchange rates live in their own table, not in `settings`.** That table is a
  single wide row already at ~64.7 KB of InnoDB's 65,535-byte limit, so it has
  no room — and a rate that produces a number finance acts on needs a date, a
  source and an owner, which a settings column cannot carry.
- **Blanking a rate is a real action**, not a no-op: it deletes the row and
  drops that currency back to the indicative default, which re-flags every total
  using it as unreviewed.
