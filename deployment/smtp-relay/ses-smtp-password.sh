#!/usr/bin/env bash
# =============================================================================
# Derive an Amazon SES *SMTP* password from an existing IAM secret access key.
#
# SES SMTP auth does NOT use the raw AWS secret key. The SMTP password is the
# secret key run through AWS SigV4 with a fixed message ("SendRawEmail"), a
# version byte (0x04) prepended, then base64-encoded. This is AWS's documented
# algorithm, so we can reuse the credentials already configured for the app's
# SES API access instead of creating a new SMTP credential.
#
# Usage:
#   ./ses-smtp-password.sh <AWS_SECRET_ACCESS_KEY> [region]
#   region defaults to us-east-1 (must match the SES region / verified identity)
#
# Prints the SMTP password to stdout. The SMTP *username* is the AWS_ACCESS_KEY_ID
# (unchanged) — this script only derives the password.
# =============================================================================
set -euo pipefail

SECRET="${1:?usage: ses-smtp-password.sh <AWS_SECRET_ACCESS_KEY> [region]}"
REGION="${2:-us-east-1}"

DATE="11111111"
SERVICE="ses"
MESSAGE="SendRawEmail"
TERMINAL="aws4_request"

# Prefer python3 (exact, dependency-free); fall back to openssl if unavailable.
if command -v python3 >/dev/null 2>&1; then
    python3 - "$SECRET" "$REGION" "$DATE" "$SERVICE" "$MESSAGE" "$TERMINAL" <<'PY'
import base64, hashlib, hmac, sys
secret, region, date, service, message, terminal = sys.argv[1:7]

def sign(key, msg):
    return hmac.new(key, msg.encode("utf-8"), hashlib.sha256).digest()

sig = sign(("AWS4" + secret).encode("utf-8"), date)
sig = sign(sig, region)
sig = sign(sig, service)
sig = sign(sig, terminal)
sig = sign(sig, message)
print(base64.b64encode(bytes([0x04]) + sig).decode("utf-8"))
PY
    exit 0
fi

# ---- openssl fallback (no python3) ------------------------------------------
hmac_hex() {  # $1 = hex key, $2 = message -> hex signature on stdout
    printf '%s' "$2" \
        | openssl dgst -sha256 -mac HMAC -macopt "hexkey:$1" -binary \
        | od -An -v -tx1 | tr -d ' \n'
}

key=$(printf 'AWS4%s' "$SECRET" | od -An -v -tx1 | tr -d ' \n')
for msg in "$DATE" "$REGION" "$SERVICE" "$TERMINAL" "$MESSAGE"; do
    key=$(hmac_hex "$key" "$msg")
done

# Prepend version byte 0x04, convert hex -> binary, base64-encode.
printf '%b' "$(printf '04%s' "$key" | sed -e 's/\(..\)/\\x\1/g')" | openssl base64 -A
echo
