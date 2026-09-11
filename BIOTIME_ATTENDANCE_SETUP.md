# Attendance from ZKTeco BioTime

The NOC copies fingerprint punches from one or more **ZKTeco BioTime** SQL Server
databases, works out each person's check-in and check-out per day, and shows
data errors (such as a missing check-out) so HR can fix them before anything is
sent on to Oracle.

The NOC is **read-only** towards BioTime. It runs exactly one query, on one table:

```sql
SELECT id, emp_code, punch_time, punch_state, terminal_sn, terminal_alias, area_alias
FROM iclock_transaction
```

Pages live under **Attendance** in the admin menu (`/admin/attendance`), behind
the permissions `view-attendance` (read) and `manage-attendance` (sources,
mapping, rebuild).

---

## 1. Install the SQL Server driver on the NOC (once)

PHP needs Microsoft's `pdo_sqlsrv` driver and ODBC Driver 18:

```bash
cd /home/azureuser/phonebook2
sudo bash deployment/biotime/install-sqlsrv.sh
php -m | grep sqlsrv        # expect: pdo_sqlsrv, sqlsrv
```

The script enables the driver for both **php-fpm** (the pages) and the **CLI**
(the scheduler), then restarts php-fpm. Until the driver is installed, the Sources page
shows a red banner and every source reports "pdo_sqlsrv is not installed".

## 2. Network

The BioTime SQL Server is on Azure, on the NOC's network. Allow the NOC
(`172.16.8.11`) to reach it on TCP **1433**:

- **Azure NSG** on the BioTime VM: inbound TCP 1433 from `172.16.8.11/32`.
- **Windows Firewall** on the BioTime VM: the same rule.
- **SQL Server Configuration Manager** → Protocols → **TCP/IP enabled**. BioTime
  often installs a *named instance* (`SQLEXPRESS`) on a **dynamic port**. Set a
  fixed port under *IPAll → TCP Port* (1433 or any other port), clear *TCP Dynamic Ports*,
  restart the SQL Server service, and enter that port on the source.
- SQL Server must allow **SQL Server authentication** (mixed mode), not only
  Windows logins: Server Properties → Security.

Check from the NOC before touching the app:

```bash
nc -zv <biotime-sql-host> 1433
```

## 3. A read-only SQL login

On each BioTime SQL Server, as an admin (replace the database name if yours
is not `biotime`):

```sql
USE [master];
CREATE LOGIN noc_attendance WITH PASSWORD = '<a strong password>', CHECK_POLICY = ON;

USE [biotime];
CREATE USER noc_attendance FOR LOGIN noc_attendance;
GRANT SELECT ON dbo.iclock_transaction TO noc_attendance;
```

This grants nothing beyond that one table.

### Access-control databases (ZKBio `acc_transaction`)

A source can also be a ZKBio access-control database, such as ZKBioSecurity or ZKBio CVSecurity. The NOC reads:

```sql
SELECT id, create_time, dev_alias, dev_id, dev_sn, pin FROM acc_transaction
```

The columns map like this:

| Column | Used as |
|---|---|
| `pin` | The employee code |
| `create_time` | The punch time. Choose `event_time` on the source instead if the table has it (see below). |
| `dev_sn` | The terminal |
| `dev_alias` | The terminal's name, often just "IN" / "Out" |

Grant it the same way:

```sql
GRANT SELECT ON dbo.acc_transaction TO noc_attendance;
```

Things that differ from BioTime:

- **Rows with no `pin` are skipped.** These are door, alarm and other non-person events.
- **The table is read by time, then id.** Its ids are unordered hex strings, so "everything after id X" does not work.
  Rows that share a timestamp are neither lost nor read twice.
- **`create_time` is when the row was written.** For an online terminal that is the punch time. A
  terminal that uploads late gets the upload time. If the table also has `event_time` (the moment of the scan),
  choose it on the source. Test connection tells you which of the two the table has, and the nightly
  reconcile then catches rows that arrive late.
- **There are no areas.** Map each **terminal** to a branch and time zone on Areas & Terminals.
- **Times may be UTC.** Test connection shows SQL Server's own clock, local and UTC, next to the newest
  row. If the newest row matches the UTC clock, switch on *Times in this database are UTC* and
  choose the zone to convert to. Punches are then stored in local time, like BioTime's.

## 4. Add the sources

**Attendance → BioTime Sources → Add source**, once per BioTime database:

| Field | Notes |
|---|---|
| Host / Port | The SQL Server's address and fixed TCP port. |
| Database | Usually `biotime`. Once a source holds punches its database cannot be changed — add a new source instead. |
| SQL login / Password | The login from step 3. The password is encrypted at rest and never shown again. |
| Import punches from | Where the **first** sync starts. Blank = all history. |
| Trust server certificate | Leave on unless SQL Server has a real certificate. ODBC Driver 18 encrypts by default and rejects SQL Server's self-signed certificate otherwise. |
| Default branch | Optional — see step 6. |

Press **Save & test connection**. A good test shows the newest five rows,
the latest `id`, and the areas seen recently. A failed test shows the driver's
error message. See Troubleshooting below.

After that, `biotime:sync` runs every 5 minutes. Press **Sync now** to start at once.
A large first backfill is read in batches, at most 200,000 rows per scheduled
run and 20,000 per "Sync now", and continues on each run until it catches up.

## 5. Map areas to branches

**Attendance → Areas & Terminals.** Each BioTime `area_alias` gets:

- a **branch**, which is shown on every punch from that area and decides between two people with the same Oracle number
  (step 6). New areas are pre-filled from the Azure branch keywords, so check them.
- a **time zone**: the clock the terminals keep. It is used only to detect punches "in
  the future". Times are always shown exactly as the device recorded them.

## 6. Employee mapping

A BioTime `emp_code` is matched to the NOC employee whose **Oracle number**
(`oracle_emp_no`) is the same, ignoring leading zeros. The SSS Egypt and
SamirGroup Oracle series reuse the same numbers, so:

1. one employee with that number → linked automatically;
2. two or more → the one in the branch of the area the code punched in (or the
   source's default branch) is linked;
3. otherwise the code waits on **Attendance → Employee Mapping** for HR to pick
   the employee, or to mark it **Not an employee** (a visitor or contractor).

Manual decisions are never overwritten. Codes that are still unmapped are
retried every night, after new employees are imported, and whenever an area's
branch changes. **Re-run auto-match** does the same on demand.

If BioTime codes are *not* Oracle numbers at your company, every code lands on
the mapping page for a one-time manual link, and everything else still works.

## 7. How check-in / check-out is decided

- **Check-in = the earliest punch of the day. Check-out = the latest punch.**
  This is the same for every user. Punches in between are kept and shown on the day's detail page.
- BioTime's `punch_state` (check in / check out / break…) is stored and shown
  but **not used**, because staff rarely press the key.
- Touches within **2 minutes** of each other count as one punch ("Duplicate punches", a warning).
- One punch only → it is the check-in and the day is flagged **Missing check-out**.
- A day is a calendar day in the device's local time, except for an **overnight shift** (step 8),
  whose day runs on past midnight.

Flags:

| Flag | Kind |
|---|---|
| Missing check-out | **Error** |
| Punch in the future (device clock wrong) | **Error** |
| Unmapped employee | **Error** |
| Duplicate punches | Warning |
| Not an employee | Warning |
| Absent (work day, no punches, shift over) | **Error** |
| Too many hours (more than the shift maximum: a forgotten check-out) | **Error** |
| Punch before hire date / after termination | **Error** |
| Late / Early leave / Overtime | Fact, in minutes |
| Worked on day off / Worked on holiday | Fact, counted as overtime |
| Punched at another branch | Warning |
| Corrected by HR / Excused | Information |

Raw punches (`attendance_punches`) are never edited. `attendance_days` is
derived from them and can be rebuilt at any time with **Rebuild days** on the
page, or with `php artisan attendance:process --from=… --to=…`.

## 8. Shifts, holidays and corrections

**Shifts** (Attendance → Shifts). Each shift has these settings:

| Setting | What it does |
|---|---|
| Start / End | An end before the start (22:00–06:00) makes it overnight. |
| Grace in | Minutes after the start before someone counts as late. Past the grace, lateness is counted from the **shift start**, so 09:20 on a 09:00 shift with 10 min grace is 20 min late. |
| Grace out | Minutes before the end someone may leave without it counting as early leave. |
| Max hours | More than this between check-in and check-out is an error: a forgotten check-out. With no shift the limit is 16 h. |
| OT after | Time after the shift end counts as overtime only once it reaches this many minutes. Every worked minute on a day off or a holiday is overtime. |
| Days off | Weekdays with no shift, for example Friday and Saturday. |

Assign a shift to **everyone, a branch, a department or one employee**, from a
date and optionally to a date. The most specific assignment wins: employee, then department, then branch, then
everyone. Among equals, the most recent wins. People with no shift still get check-in
and check-out, but no lateness and no absences.

**Absences** are recorded only for someone who meets all of these:

- they are linked to a BioTime code,
- they were employed that day (hire and termination dates, and a status that is not on leave),
- they have a shift and it is a work day,
- the shift is over.

`attendance:process` records them every hour for today and yesterday. Until the shift ends,
the person may still arrive.

**Overnight shifts.** The day runs from midnight to 6 hours after the shift ends, so
22:00–06:00 is one day. The next day starts where it stopped, and every punch belongs to
exactly one day. One side effect: someone who switches from a night shift straight to the next
morning's day shift has that morning counted on the night shift. Correct it on the day page.

**Holidays** (Attendance → Holidays) apply to all branches or to one. You can add several
days at once, such as Eid. A holiday records no absence, and work on it is overtime.

**Corrections.** Open a day from the check-in/out page. Anyone with `manage-attendance` can:

- *Correct this day*: set the check-in and/or check-out, with a reason. The punches are not edited;
  the day shows **Corrected by HR**.
- *Excuse the day*: annual leave, sick leave, mission, work from home, permission or other.
  Absence, lateness, early leave and a missing check-out stop counting. A future punch or an
  unmapped code still counts, because those are data errors.

A new correction replaces the previous one, which stays in the day's **History**. Any correction can be **revoked**.

Saving a shift, an assignment or a holiday recalculates the last 14 days straight away.
For older days, run:

```bash
php artisan attendance:process --from=2026-08-01 --to=2026-08-31
```

## 9. Commands

| Command | When |
|---|---|
| `biotime:sync` | Every 5 min (scheduler). `--source=ID`, `--since=YYYY-MM-DD` to re-read from a date, `--max-rows=`. |
| `biotime:reconcile --fix` | Nightly 02:30. Compares per-day counts with BioTime for the last 7 days, re-reads any day that is short, reports punches that were deleted in BioTime, and retries auto-matching. |
| `biotime:test [source]` | The page's Test connection, from the shell. |
| `attendance:process` | Hourly with `--days=2` and nightly with `--days=7`. Records absences, which no sync can do because nobody punched, and applies shift and holiday changes to recent days. Use `--from=` / `--to=` for any range. |

A source that fails 3 syncs in a row, and a count mismatch that survives
`--fix`, each raise a **NocEvent** (module `attendance`). The event resolves itself once the problem clears.

## 10. Troubleshooting

| Message | Meaning |
|---|---|
| `pdo_sqlsrv is not installed` | Step 1 not done, or php-fpm not restarted. |
| `Login timeout expired` / `TCP Provider: Error code 0x2749` | Network: NSG, Windows Firewall, wrong port, or TCP/IP disabled (step 2). |
| `SSL Provider: … certificate chain was issued by an authority that is not trusted` | Turn on **Trust server certificate**. |
| `Login failed for user` | Wrong password, or SQL Server is in Windows-only authentication mode. |
| `The SELECT permission was denied on the object 'iclock_transaction'` | The `GRANT` in step 3 is missing, or was run in the wrong database. |
| `Invalid object name 'iclock_transaction'` | Wrong database name. BioTime 8 uses `iclock_transaction`; older ZKTime versions use different tables. |
