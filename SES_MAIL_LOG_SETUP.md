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
| Sent by SES (`GetSendQuota.SentLast24Hours`) | **610** |
| Visible in the NOC log | **48** |

About 92% of the account's mail was unlogged. The page now shows this gap itself
rather than implying it is complete.

There is **no way to recover history**. SES has no API that lists messages it
already sent; you only ever get what event publishing captured. Fix this and the
log starts filling from that moment.

## The fix: a default configuration set per identity

Every verified identity can carry a *default configuration set*, which SES
applies to any message sent from that identity that doesn't name one itself.
That is what catches third-party senders — they need no changes at all.

### Step 1 — make a dedicated configuration set

**Do not reuse `sg-noc-email-marketing` as the account default.** It has open and
click tracking on, which is right for campaigns and wrong for everything else:
as a default it would inject a tracking pixel into, and rewrite the links of,
Salesforce and Sophos mail. Rewritten links in a password-reset or alert email
are a real problem.

Create a second set with event publishing on and tracking off:

```bash
aws sesv2 create-configuration-set \
  --configuration-set-name sg-all-mail \
  --region eu-north-1 \
  --tracking-options '{"CustomRedirectDomain":""}' \
  --no-cli-pager
```

If the CLI rejects the empty tracking domain, create it in the console instead
and leave **open tracking** and **click tracking** unchecked.

### Step 2 — point it at the same SNS topic the NOC already receives

Reuse the existing topic — the NOC webhook (`/api/sns/email-events`) and its
signature verification already handle it, so nothing on the NOC side changes.

```bash
aws sesv2 create-configuration-set-event-destination \
  --configuration-set-name sg-all-mail \
  --event-destination-name noc-sns \
  --region eu-north-1 \
  --event-destination '{
    "Enabled": true,
    "MatchingEventTypes": ["SEND","DELIVERY","BOUNCE","COMPLAINT","REJECT","RENDERING_FAILURE"],
    "SnsDestination": {"TopicArn": "<the topic sg-noc-email-marketing already publishes to>"}
  }'
```

Find that ARN under the existing set:

```bash
aws sesv2 get-configuration-set-event-destinations \
  --configuration-set-name sg-noc-email-marketing --region eu-north-1
```

`OPEN` and `CLICK` are omitted on purpose — without tracking enabled they never
fire, and you do not want them firing on third-party mail anyway.

### Step 3 — set it as the default on every identity

The account has ten verified identities. **All of them need it**: SES matches the
most specific identity for a send, so setting the default only on the domain
`samirgroup.net` would *not* cover the separate email identity
`crm@samirgroup.net` — which is exactly where Salesforce sends from.

```bash
for identity in \
  samirgroup.com samirgroup.net samirgroup.org samirgroup.info \
  sssegypt.com sssegypt.net \
  marketing@sssegypt.com crm@samirgroup.net \
  donotreply@samirgroup.net mohammad.salameh@samirgroup.net
do
  aws sesv2 put-email-identity-configuration-set-attributes \
    --email-identity "$identity" \
    --configuration-set-name sg-all-mail \
    --region eu-north-1
done
```

Console equivalent: **SES → Identities → *identity* → Configuration set →
Edit → Default configuration set**.

Marketing is unaffected: `SesService` names `sg-noc-email-marketing` explicitly
on every campaign send, and an explicit configuration set always beats the
identity default.

### Step 4 — confirm

Send anything from one of the newly-covered services, then reload
`/admin/mail-delivery`. It should appear within seconds, and the coverage warning
at the top of the page should shrink towards zero over the following 24 hours.

## Required IAM permissions

The NOC's own IAM user (`sg-noc-ses-sender`) is **send-only** — it is denied
`ses:GetAccount` and `ses:ListConfigurationSets`, so it cannot read or make any
of these changes, and the NOC deliberately does not try. Run the steps above as
an admin user. Granting the NOC user more is not required and not recommended.

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
