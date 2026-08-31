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

The API takes bare numeric IDs and publishes **no endpoint to list them**, so
the ID → label map is maintained by hand. Without it the form has nothing to
offer and says so.

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

- Sub-categories are nested under their category, and the form validates the
  pairing server-side — a sub-category from a different category is rejected.
- `channel_id` is sent on every ticket; it defaults to `1` if omitted.
- `extra` is merged verbatim into the `data` payload. It is the escape hatch for
  fields the API adds later — no code change needed.
- Saving validates the JSON and shows the parsed result underneath, so a typo
  surfaces immediately instead of emptying the form's dropdowns.

The IDs must come from the ticketing system's own tables. Ask its owners for the
category/sub-category/type/priority lists; the example above only reproduces the
values seen in a working Postman call (category 8, sub-category 40, type 2,
priority 4, channel 1).

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
