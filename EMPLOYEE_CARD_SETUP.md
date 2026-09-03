# Employee Digital Card — Apple Wallet & Samsung Wallet

Every active employee has a permanent digital business card at `/card/{token}`
(canonically on `vcard.samirgroup.net`). The card page is public and shareable;
the two **wallet** actions are not — they require a signed-in session, because
the pass and the Samsung link are personal to the employee.

Both wallets are optional and independent. Each hides its own button everywhere
until its credentials are complete, so a half-configured integration is never
shown to an employee.

| | Apple Wallet | Samsung Wallet |
|---|---|---|
| What is delivered | A signed **`.pkpass` file** the phone downloads | A signed **link** the phone opens |
| Built by | `Services\EmployeeCard\WalletPassService` | `Services\EmployeeCard\SamsungWalletService` |
| Card route | `GET /card/{token}/wallet` (auth) | `GET /card/{token}/samsung` (auth) |
| Home portal route | `GET /my-card/wallet` (auth) + `/my-card/pass/{employee}` (signed) | `GET /my-card/samsung/{employee}` (signed) |
| Credentials | Apple Developer: Team ID, Pass Type ID, P12 | Samsung Partner Portal: Partner ID, Card ID, Certificate ID, private key |
| How hard to obtain | Same afternoon | **A business agreement. Weeks.** |

On the home portal both buttons open a **QR**, not a download. A wallet card
only means anything on the handset, and that page is open on a Windows PC. The
QR encodes a short, **signed, 15-minute** URL on our own host — never the pass
or the Samsung link itself.

---

## Apple Wallet

Admin → Settings → **Employee Cards & Apple Wallet**.

1. In [developer.apple.com](https://developer.apple.com/account/) → Certificates,
   Identifiers & Profiles → Identifiers, register a **Pass Type ID**
   (e.g. `pass.net.samirgroup.employee`).
2. Create a signing certificate for it and export it as a `.p12`.
3. Fill in Team ID, Pass Type ID, upload the `.p12` and its password.
4. The Apple WWDR G4 intermediate is bundled (expires 2030); upload your own
   only if Apple rotates it early.

The pass background colour is settable. Pick a dark colour and the logo is
knocked out to white and the text flips automatically.

**Legacy P12s**: OpenSSL 3 rejects P12 files encrypted with RC2/3DES, which is
what macOS Keychain and older `openssl pkcs12 -export` produce. `WalletPassService`
falls back to the OpenSSL CLI with the legacy provider rather than failing.

---

## Samsung Wallet

Admin → Settings → **Samsung Wallet**.

### Prerequisite, and it is the hard part

Samsung Wallet is **partner-gated**. There is no self-service equivalent of an
Apple developer account: you apply at
[partner.walletsvc.samsung.com](https://partner.walletsvc.samsung.com/), sign a
business agreement, and Samsung onboards you. Budget weeks, not an afternoon.

During onboarding you:

1. Create and **publish a wallet card template** of type **Generic**.
   *Not* "Digital ID" — that type is region-gated and meant for government and
   campus credentials; an application to use it for printing an extension number
   will not be approved.
2. Generate an **RSA key pair** and upload the **public** half. Samsung returns a
   **Certificate ID**.
3. Note your **Partner ID** and the template's **Card ID**.

Generate the key pair with:

```bash
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out samsung-wallet.key
openssl rsa -in samsung-wallet.key -pubout -out samsung-wallet.pub
```

Upload `samsung-wallet.pub` to the Portal; upload `samsung-wallet.key` to
Settings. It must be **unencrypted** PEM — the NOC signs unattended and has
nowhere to type a passphrase. It is validated on save (a key OpenSSL cannot read
is rejected there and then, rather than at 9am by an employee) and encrypted at
rest with the app key, the same treatment the Apple P12 gets.

### Label the template fields in this order

The Partner Portal template owns the **labels**; the NOC only supplies
**values**. Get the order wrong and the card reads "Field 3: 214".

| Payload field | Label it as | Value sent |
|---|---|---|
| `title` | — (the card title) | Employee name |
| `subtitle` | — | Job title |
| `providerName` | — | Company name |
| `text1` | **Department** | Department |
| `text2` | **Office** | Branch, city |
| `text3` | **Extension** | Extension number |
| `text4` | **Email** | Work email |
| `serial1` | — (the QR) | The employee's `/card/{token}` URL |
| `appLinkData` | — | The same card URL |

Empty values are omitted from the payload rather than sent blank, so an employee
with no extension does not get an empty row.

`logoImage` points at the **public** card host, because **Samsung's servers fetch
it**. An intranet-only URL renders a blank logo with no error anywhere.

### How the link is built

```
https://a.swallet.link/atw/v3/{cardId}#Clip?cdata={jws}
```

`#Clip` is a literal fragment with a capital C, and the query sits **after** it.
That is not a typo, and it is not a URL any query-string builder will produce —
`SamsungWalletService` assembles it by hand for that reason.

`cdata` is a JWS signed **RS256** with the private key above. Its header carries
Samsung's own claims, not RFC 7515's:

```json
{"alg":"RS256","cty":"CARD","ver":"3","partnerId":"…","certificateId":"…","utc":1788419985840}
```

`utc` is epoch **milliseconds** and is used for expiry and replay checks — **if
this server's clock drifts more than a few minutes, every link fails** and
Samsung returns nothing useful to explain why. Check `timedatectl` first when
links stop working and nothing has changed.

The payload is signed, not encrypted. Samsung's JWE layer (RSA1_5 + A128GCM
against their public key) applies to card types carrying sensitive data; a
generic staff card is a plain JWS. If Samsung's onboarding requires encryption
for your template, that wraps the payload *before* signing rather than replacing
it.

The finished URL runs to roughly **1,500 characters**. That is why the home
portal QR points at a short signed route on our own host that redirects, rather
than encoding the Samsung link directly — a QR carrying 1.5 KB is dense enough
to fail scanning on an ordinary phone camera across a desk.

### refId

`refId` is `sg-emp-{employee id}`, stable per employee on purpose: Samsung treats
it as the card's identity, so someone re-adding their card **updates** the
existing one instead of leaving a second stale copy in their wallet.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| No wallet buttons anywhere | That vendor's settings are incomplete. Both services require *every* credential before `isConfigured()` returns true. |
| Samsung link opens and immediately errors | Server clock drift (`utc` claim), or the Card ID does not match a **published** template. |
| Samsung card shows a blank logo | `logoImage` is not reachable from the public internet. |
| Samsung card labels read "Field 1/2/3" | The Portal template's labels were never set — see the table above. |
| Apple pass downloads but will not open | P12/WWDR mismatch, or the Pass Type ID in Settings differs from the one the certificate was issued for. |
| QR scans but the page says the link expired | Correct — signed links last 15 minutes. Reload the portal for a fresh one. |
