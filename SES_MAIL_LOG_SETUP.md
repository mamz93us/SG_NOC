# SES Mail Delivery Log — capturing *every* sender

The NOC page at **`/admin/mail-delivery`** shows every message AWS SES told us
about. This document is about making SES tell us about all of them.

## The problem

SES publishes events **only for mail sent with a configuration set**. The NOC
applies one (`sg-noc-email-marketing`) on its own sends, so NOC alerts and
marketing campaigns appear in the log. Everything else on the account —
Salesforce, Sophos, the Ricoh scan-to-email relay, any service pointed at SES
SMTP — sets no configuration set, so SES publishes nothing and those messages
are invisible.

Measured on 2026-09-02:

| | 24-hour count |
|---|---|
| Sent by SES (`GetSendQuota.SentLast24Hours`) | **606** |
| Visible in the NOC log | **48** |

About 92% of the account's mail was unlogged. The log page shows this gap itself
rather than implying it is complete.

There is **no way to recover history**. SES has no API that lists messages it
already sent; you only ever get what event publishing captured. Fix this and the
log starts filling from that moment.

## The fix: a default configuration set on every identity

Every verified identity can carry a *default configuration set*, which SES
applies to any message sent from that identity that doesn't name one itself.
That is what catches third-party senders — Salesforce and Sophos need no changes
at all.

Everything below is in the **eu-north-1 (Stockholm)** region. Check the region
selector top-right before you start; SES identities and configuration sets are
per-region and the account's are all in eu-north-1.

---

### Step 0 — copy the existing SNS topic ARN

The NOC already receives SES events on an SNS topic. Reuse it, so nothing on the
NOC side has to change.

1. AWS console → **Amazon SES**.
2. Left nav → **Configuration → Configuration sets**.
3. Click **`sg-noc-email-marketing`**.
4. Open the **Event destinations** tab.
5. Click the existing destination and copy the **SNS topic ARN**
   (`arn:aws:sns:eu-north-1:068799539687:…`). Keep it on the clipboard.

---

### Step 1 — create a second configuration set

**Do not reuse `sg-noc-email-marketing` as the account-wide default.** It
captures Open and Click events, and that is what makes SES inject a tracking
pixel and **rewrite every link** in the message. Right for campaigns; actively
harmful on a Salesforce password-reset or a Sophos alert.

1. Left nav → **Configuration → Configuration sets** → **Create set**.
2. **Configuration set name**: `sg-all-mail`
3. Leave everything else at its default — no sending IP pool, no custom redirect
   domain, no suppression-list override.
4. **Create set**.

> The "Custom redirect domain" field does **not** control tracking. What controls
> it is which event types you select in Step 2.

---

### Step 2 — publish its events to the same SNS topic

1. Open the new **`sg-all-mail`** set → **Event destinations** tab →
   **Add destination**.
2. **Event types** — tick exactly these:
   - ✅ Sends
   - ✅ Deliveries
   - ✅ Hard bounces
   - ✅ Complaints
   - ✅ Rejects
   - ✅ Rendering failures
   - ✅ Delivery delays
   - ❌ **Opens** — leave unticked
   - ❌ **Clicks** — leave unticked

   Leaving Opens and Clicks off is the whole safeguard: with them off, SES does
   not modify the message body, so no tracking pixel is inserted and no links are
   rewritten.
3. **Next** → **Destination type**: **Amazon SNS**.
4. **SNS topic**: pick the topic whose ARN you copied in Step 0.
5. **Name**: `noc-sns`
6. **Next** → **Add destination**.

---

### Step 3 — make it the default on all ten identities

SES matches the **most specific** identity for a send. Setting the default only
on the domain `samirgroup.net` would *not* cover the separate email identity
`crm@samirgroup.net` — which is exactly where Salesforce sends from. So this has
to be done on every identity, one at a time.

For **each** identity below:

1. Left nav → **Configuration → Identities**.
2. Click the identity.
3. Open the **Configuration set** tab.
4. **Edit**.
5. Tick **Assign a default configuration set**.
6. Choose **`sg-all-mail`**.
7. **Save changes**.

The ten identities on this account:

| Identity | Type | Notes |
|---|---|---|
| `samirgroup.com` | domain | |
| `samirgroup.net` | domain | |
| `samirgroup.org` | domain | |
| `samirgroup.info` | domain | |
| `sssegypt.com` | domain | |
| `sssegypt.net` | domain | |
| `crm@samirgroup.net` | email | **Salesforce** — do not skip |
| `donotreply@samirgroup.net` | email | |
| `marketing@sssegypt.com` | email | |
| `mohammad.salameh@samirgroup.net` | email | |

Marketing is unaffected: `SesService` names `sg-noc-email-marketing` explicitly
on every campaign send, and an explicit configuration set always beats the
identity default.

---

### Step 4 — confirm

1. Trigger any mail from a newly-covered service (a Salesforce test email, a
   Sophos alert, a scan-to-email from a Ricoh).
2. Open **`/admin/mail-delivery`** — it should appear within seconds.
3. The orange coverage banner at the top of that page compares SES's own
   24-hour counter against what was logged. It should fall towards 0% missing
   over the following 24 hours as the old, unlogged sends age out of the window.
4. Use the **Sender** filter to confirm each service is arriving:
   `crm@samirgroup.net`, `donotreply@samirgroup.net`, and so on.

If a service still doesn't appear, it is sending from an identity that didn't get
the default set — check the From address on one of its messages against the table
above.

---

## Required IAM permissions

The NOC's own IAM user (`sg-noc-ses-sender`) is **send-only** — it is denied
`ses:GetAccount` and `ses:ListConfigurationSets`, so it cannot read or make any
of these changes, and the NOC deliberately does not try. Do the steps above as an
admin user. Granting the NOC user more is not required and not recommended.

## Appendix — the same thing on the CLI

```bash
REGION=eu-north-1
TOPIC=$(aws sesv2 get-configuration-set-event-destinations \
          --configuration-set-name sg-noc-email-marketing --region $REGION \
          --query 'EventDestinations[0].SnsDestination.TopicArn' --output text)

aws sesv2 create-configuration-set --configuration-set-name sg-all-mail --region $REGION

aws sesv2 create-configuration-set-event-destination \
  --configuration-set-name sg-all-mail --region $REGION \
  --event-destination-name noc-sns \
  --event-destination "{
    \"Enabled\": true,
    \"MatchingEventTypes\": [\"SEND\",\"DELIVERY\",\"BOUNCE\",\"COMPLAINT\",\"REJECT\",\"RENDERING_FAILURE\",\"DELIVERY_DELAY\"],
    \"SnsDestination\": {\"TopicArn\": \"$TOPIC\"}
  }"

for identity in \
  samirgroup.com samirgroup.net samirgroup.org samirgroup.info \
  sssegypt.com sssegypt.net \
  marketing@sssegypt.com crm@samirgroup.net \
  donotreply@samirgroup.net mohammad.salameh@samirgroup.net
do
  aws sesv2 put-email-identity-configuration-set-attributes \
    --email-identity "$identity" --configuration-set-name sg-all-mail --region $REGION
done
```

`OPEN` and `CLICK` are omitted deliberately — including them is what turns on
body rewriting.

## Volume note

At ~610 messages/day, identity-wide event publishing produces roughly 1,200–1,800
`email_events` rows/day (a Send plus a Delivery per message, more on bounces).
`email-marketing:prune-events` already trims that table on the configured
retention window (365 days by default), so the growth is bounded.

## Related

- `/admin/mail-delivery` — the log itself (`Admin\MailDeliveryController`).
- `/admin/notifications/email-log` — different thing: what *this app* handed to
  the mailer, before SES.
- `/admin/smtp-relay` — the Ricoh scan-to-email relay's own log, which already
  records that traffic independently of SES events.
- [SMTP_RELAY_SETUP.md](SMTP_RELAY_SETUP.md)
