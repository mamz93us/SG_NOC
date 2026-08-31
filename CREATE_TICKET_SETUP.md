# Create Ticket (NOC → IT Ticketing System)

Raises a ticket in the Samir ticketing system from inside the NOC, so IT does not
have to switch apps to log what they are already looking at.

- **Page:** `/admin/tickets/create` (top-level **Create Ticket** in the navbar)
- **History:** `/admin/tickets` (Admin → **Ticket Submissions**)
- **Settings:** Admin → Settings → **Create Ticket API** (`#noc-ticketing`)

## The endpoint

```
POST {base}/SamirTicketingAPIs/ticketing/api/addTicketingRequestForNOC
Header: X-API-Key: <key>
Body:   multipart/form-data
```

| Part | Type | Contents |
|---|---|---|
| `data` | string | JSON object, see below |
| `email` | string | Requester's email |
| `azureUserId` | string | Requester's Azure AD object id |
| `file` | file | Optional attachment |

The `data` object:

```json
{
  "ticketTitle": "title200",
  "ticketDescription": "desc200",
  "ticketCategory": 8,
  "ticketSubCategory": 40,
  "ticketType": 2,
  "ticketPriotity": 4,
  "ticketChannelId": 1
}
```

> **`ticketPriotity` is spelled that way on purpose.** The misspelling is the
> API's own — it comes back in its response body too. "Correcting" it here means
> the priority silently arrives unset.

A success is **HTTP 201** with the created ticket echoed back, including
`ticketId`.

## Configuration

Everything lives in Admin → Settings, not `.env`, so the endpoint can be pointed
at test or production without a deploy.

| Field | Notes |
|---|---|
| API Endpoint URL | Full URL including the path. Swap the host for test vs prod. |
| X-API-Key | Encrypted at rest. **Leave blank to reuse the onboarding Ticketing API key** — same ticketing system, normally the same key. |
| Enable | Off means the form refuses to submit and makes no outbound call. |
| Ticket catalog (JSON) | The ID → label map, see below. |

This is **separate from the "Ticketing API (New Employee Tickets)" card above
it**. That one calls `provisionNewEmployee` and is driven by the onboarding
workflow; this one calls `addTicketingRequestForNOC`. They point at different
paths, and often different hosts, so they cannot share a URL field.

## The ticket catalog

The API takes bare numeric IDs (`ticketCategory: 8`, `ticketSubCategory: 40`,
…). Where those ids come from is split in two:

| Part | Source |
|---|---|
| Categories + sub-categories | The API's own lookup endpoints, cached |
| Type + priority **labels** | The JSON in Settings |
| `channel_id`, `extra` | The JSON in Settings |

### Categories come from the API

```
GET {base}/getCategories                  → categories, subCategories nested
GET {base}/getSubCategories?categoryId=N  → the flat list for one category
Header: X-API-Key: <same key as the submit>
```

`{base}` is **derived from the submit URL** by dropping its last path segment
— the three endpoints are siblings under `.../ticketing/api`, so one setting
keeps everything pointed at test or production. There is no second URL field.

`getCategories` returns the sub-categories nested, so one call is normally
enough; `getSubCategories` is only called for a category whose payload omits the
nested key entirely.

```json
[{
  "categoryId": 1,
  "categoryName": "User Accounts & Access",
  "categoryNameAr": "حسابات المستخدمين والصلاحيات",
  "departmentId": 1,
  "subCategories": [{
    "subCategoryId": 1, "subCategoryName": "Password reset",
    "categoryId": 1, "typeId": 1, "priorityId": 3, "departmentId": 1
  }]
}]
```

Every sub-category carries its **own `typeId` and `priorityId`** — the
ticketing system's opinion about what that kind of request is. The form
pre-selects both when a sub-category is chosen; they stay editable, because the
pairing is a default and not a rule.

The tree is cached for **6 hours** (`TicketCatalogApi`, keyed on the derived
base URL so a test↔production swap cannot serve one environment's ids against
the other's endpoint). **Refresh from API** in the Settings card re-pulls it
immediately and reports the error on screen if the endpoint is down; saving a
new URL or key drops the cache on its own.

If the lookup call fails the form falls back to the `categories` in the JSON
below, says so in a banner, and keeps working.

### The JSON in Settings

Still needed for **type and priority names** — the API stamps sub-categories
with bare `typeId` / `priorityId` and publishes no list to turn those into
words. An id with no entry here is offered as "Type 2" / "Priority 3" rather
than being dropped, so an incomplete map never blocks a submit.

```json
{
  "categories": [
    {
      "id": 8,
      "name": "IT Support",
      "subcategories": [
        { "id": 40, "name": "Hardware" },
        { "id": 41, "name": "Software" }
      ]
    }
  ],
  "types":      [{ "id": 1, "name": "Service Request" }, { "id": 2, "name": "Incident" }],
  "priorities": [{ "id": 4, "name": "Low" }],
  "channel_id": 1,
  "extra":      { }
}
```

- `types` and `priorities` are the part that matters; `categories` is only the
  fallback for a failed lookup call.
- Sub-categories are nested under their category, and the form validates the
  pairing server-side — a sub-category from a different category is rejected,
  whichever source the tree came from.
- `channel_id` is sent on every ticket; it defaults to `1` if omitted.
- `extra` is merged verbatim into the `data` payload. It is the escape hatch for
  fields the API adds later — no code change needed.
- Saving validates the JSON and shows the parsed result underneath (with the
  type/priority each sub-category implies), so a typo surfaces immediately
  instead of emptying the form's dropdowns.

## Requester identity

The API identifies the requester by **Azure object id**, not by email, so the
requester must exist in `identity_users` (populated by `SyncIdentity`). The
lookup matches on both `mail` and `user_principal_name`, because plenty of staff
sign in as one and receive mail as the other.

If no identity is found the form refuses and says why, rather than posting a
blank `azureUserId`.

## Permissions

| Slug | Grants |
|---|---|
| `create-tickets` | Use the form, for yourself |
| `create-tickets-for-others` | Choose a different requester, and see everyone's submissions |
| `view-tickets` | See the submission history |

`viewer` and `hr` get `create-tickets` + `view-tickets` by default so ordinary
staff can raise their own tickets without being granted anything else.
`admin`/`super_admin` get all three.

## What is stored locally

`noc_tickets` holds one row per **attempt** — successes and failures both. The
ticketing system remains the system of record for a ticket's live state (status,
engineer, close date), so nothing here is updated after the submit. The row
exists so a rejected submit leaves evidence: the HTTP status, the error body and
the exact payload context are on `/admin/tickets/{id}`.

Attachments are streamed straight through to the ticketing system and are **not**
kept on the NOC — only the filename and size are recorded.

## Deploy

```bash
git pull && php artisan migrate && php artisan view:clear
```

Three migrations: settings columns, the `noc_tickets` table, and the permission
rows.
