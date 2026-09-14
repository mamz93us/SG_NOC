# Vacations (Oracle leave balances and leave records)

**Attendance ▸ Vacations** at `/admin/vacations`. Every employee's annual leave balance and every leave record, as
Oracle holds them. The NOC does not calculate leave: it keeps Oracle's figures and shows them against the people
they belong to.

## Where the data comes from

Two sheets exported from Oracle, uploaded at **Vacations ▸ Import from Oracle** (`manage-vacations`). An Oracle API
will replace the upload later. It will call the same importer (`Services\Vacation\VacationImporter`) with the same
row shapes, so everything below holds for it too.

| Sheet | Columns | Becomes |
|---|---|---|
| Balance sheet (`samir_vacation balance.xls`) | `PERSON_ID`, `PERSON_NUMBER`, `CARRYOVER`, `ACCRUALS`, `ABSENCES`, `TOTAL_BALANCE` | one `vacation_balances` row per person per year |
| Details sheet (`samir_vacation details.xls`) | `PERSON_NUMBER`, `ABSENCE_TYPE`, `VAC_START_DATE`, `VAC_END_DATE` | one `vacation_absences` row per leave record |

`VacationSheetReader` tells the sheets apart by their header row, so it does not matter which upload box each is
in. It reads the `.xls` Oracle writes, or the same sheet saved as `.xlsx` or `.csv`. Dates may be Oracle's
`17-AUG-26`, ISO dates, `d/m/Y` or Excel dates. A row that cannot be read is skipped and named in the import's notes;
it never stops the rest.

### The balance columns

| Oracle | Stored as | On screen | Meaning |
|---|---|---|---|
| `CARRYOVER` | `carryover` | Last year | last year's balance brought into this year; can be negative |
| `ACCRUALS` | `accrued` | This year so far | leave earned this year up to the export. It grows every month, so it is only as fresh as the last import |
| `ABSENCES` | `used` | Used | days taken this year. Oracle writes it negative; it is stored positive |
| `TOTAL_BALANCE` | `balance` | Remaining | days left. **Oracle's own figure, never recomputed** |

The upload asks which day the balances are **as of**; the year they count for is that date's year. An older sheet
never overwrites a newer balance, and a balance older than `stale_after_days` is flagged on every page.

What the September 2026 sheet showed, and what the code does about it:

- **Remaining is not always last year + this year − used.** 25 of 577 people have a whole-number gap, all with
  carryover at 10: Oracle's balance holds an adjustment the sheet has no column for. The pages show it as
  *Other adjustments* (`VacationBalance::otherAdjustments()`) instead of hiding it or "correcting" Oracle. A gap
  under 0.02 is Oracle rounding each column to two decimals, and is ignored.
- **8 people have every figure empty**: new joiners with no leave plan yet. They are kept and shown as such.
- **Used days are work days.** No annual leave in the sheet starts on a Friday or Saturday, and used days match a
  count without them. Leave dated in the future is not in `ABSENCES` until it is taken.

### The leave records

The sheet has no duration, so `VacationDays` counts each record's `calendar_days`, and its `work_days` without the
book's weekend. Public holidays are not taken out — the NOC does not have Oracle's holiday calendar — so a record
over Eid can show more days than Oracle deducted. `duration` holds Oracle's own figure when a feed sends one, and
wins over the count.

Business trips (`Internal Business Trip`, `External Business Trip`) arrive in the same export. They are listed, but
kept apart from leave wherever days are added up.

The details sheet lists every record **starting on or after a cutoff** (in September: 120 days back, 17 May). So an
import:

- adds a record the NOC does not hold yet;
- stamps `removed_at` on a held record that **starts inside the sheet's span of start dates** but is **not in the
  sheet**: it was withdrawn or changed in Oracle. It is shown as *No longer in Oracle* and never deleted;
- restores such a record if a later sheet lists it again;
- leaves alone every record starting **before** the span: history the sheet no longer covers.

A record whose dates or type change in Oracle is therefore one withdrawn record and one new one. A feed that sends
only some records must pass `withdrawMissing: false`, or it would withdraw everything it left out.

## Which employee

`vacation_employees` holds one Oracle person number in one **book** and the NOC employee it is. `VacationLinker`
decides:

1. the candidates are employees whose `oracle_emp_no` is the number, linked secondary mailboxes excluded;
2. it keeps the ones in the book's branches, or with no branch. The SSS Egypt and SamirGroup number series collide,
   and a number held only by someone in Cairo belongs to somebody else;
3. exactly one left is linked; several are *Ambiguous*; none is *No match*.

Every import re-decides the rows the rule owns. A link chosen on the person's page is `manual`, and no import
overwrites it. *Not an employee* is a manual link to nobody. On NOC2 in September 2026: 569 of the 577 balance
numbers matched one employee, 5 also matched a Cairo employee and were settled by branch, and 3 matched nobody.

## Config — `config/vacations.php`

- `books`: one per Oracle export. `branches` are branch **names**, as on the Branches page; `weekend` holds Carbon
  day numbers. If a listed branch name stops existing, every import says so in its notes.
- `stale_after_days` (35): a balance older than this is flagged, because this year's leave has grown since.

## Permissions

- `view-vacations`: the Balances and Leave records pages, their CSV exports, and the vacation card on an
  employee's page.
- `manage-vacations`: uploading, and linking Oracle numbers to employees.

Both are seeded to super_admin, admin and hr.

## Auditing

The four vacation models are left out of the automatic audit (`config/audit.php`): one import writes thousands of
rows. Each import is logged by hand as `vacation_import` with its summary, and each link decision as
`vacation_person_linked`, `vacation_person_not_employee` or `vacation_person_reset`. `vacation_imports` keeps every
import's counts and notes, shown on the import page.

## The API, later

The API needs only a controller that maps Oracle's JSON onto the importer's rows:

- `importBalances($book, $asOf, $rows, source: 'api')`, with rows of `person_number`, `person_id`, `carryover`,
  `accrued`, `absences` (Oracle's sign) and `balance`;
- `importAbsences($book, $rows, source: 'api', withdrawMissing: ...)`, with rows of `person_number`, `type`,
  `start`, `end` and optionally `duration`.

Gate it like the attendance API: an HR API key with its own scope (`hr.api_key:vacations`), added to
`HrApiKey::scopeLabel()`.
