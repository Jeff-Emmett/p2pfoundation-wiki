#!/usr/bin/env bash
# Deploy the edge fallback Worker using an API token held on disk.
#
# WHY A WRAPPER. The token must never appear in a command line an agent
# constructs, in shell history, or in a transcript — a secret that reaches a
# model's context is disclosed and has to be rotated. This script reads the file
# itself and hands the value to wrangler through the environment, so the caller
# only ever types `./deploy.sh`. Same shape as `secretctl run --ref`.
#
# Get a token: Cloudflare dashboard -> My Profile -> API Tokens -> Create Custom
# Token, with Account:Workers Scripts:Edit, Zone:Workers Routes:Edit,
# Account:Account Settings:Read, Zone:Zone:Read. Scope it to p2pfoundation.net.
#
# Store it:
#   install -m 600 /dev/null ~/.secrets/private/cloudflare_wrangler_token
#   read -rs T && printf '%s' "$T" > ~/.secrets/private/cloudflare_wrangler_token && unset T
#
# Move it into Infisical once a reachable instance exists — it was on Netcup,
# which is the host this whole fallback exists to survive.
set -euo pipefail

cd "$(dirname "$0")"

TOKEN_FILE="${CF_TOKEN_FILE:-$HOME/.secrets/private/cloudflare_wrangler_token}"

if [ -n "${CLOUDFLARE_API_TOKEN:-}" ]; then
  echo "using CLOUDFLARE_API_TOKEN from the environment"
elif [ -r "$TOKEN_FILE" ]; then
  CLOUDFLARE_API_TOKEN="$(tr -d '\r\n' < "$TOKEN_FILE")"
  export CLOUDFLARE_API_TOKEN
  echo "using token from $TOKEN_FILE ($(wc -c < "$TOKEN_FILE") bytes)"
else
  cat >&2 <<EOF
No credential found.

  Looked for \$CLOUDFLARE_API_TOKEN, then $TOKEN_FILE

Either store an API token at that path (see the header of this script), or run
\`wrangler login\` — but run it in a plain terminal and wait for
"Successfully logged in.", because it blocks on an OAuth callback and a
cancelled run leaves no token behind while the browser still says success.
EOF
  exit 2
fi

echo "== tests =="
npm test

echo
echo "== deploy =="
npx wrangler deploy "$@"

cat <<'EOF'

== verify ==
  curl -sS -D- -o /dev/null https://wiki.p2pfoundation.net/Peer_to_Peer | grep -iE '^HTTP|x-p2pwiki-fallback'

Netcup down  -> x-p2pwiki-fallback: standby
Netcup up    -> header ABSENT (the Worker is staying out of the way)
EOF
