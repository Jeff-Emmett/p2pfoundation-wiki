#!/usr/bin/env bash
# Bring the Headscale admin UI back. Run ON GX10.
#
# Mints a headscale API key and a cookie secret and writes both straight into
# headplane.env. Neither value is ever echoed, so they do not reach a terminal,
# a shell history, a log, or an agent's context — the only copy is the 0600 file
# the container reads.
#
# Safe to re-run: an existing headplane.env is left alone rather than rotated,
# because rotating the key would log out a working session for no reason.
set -euo pipefail
cd "$(dirname "$0")"

ENV_FILE="./headplane.env"

docker ps --format '{{.Names}}' | grep -qx headscale || {
  echo "headscale is not running — start it first (../deploy-on-gx10.sh)" >&2; exit 2; }

if [ -f "$ENV_FILE" ]; then
  echo "== $ENV_FILE already exists — keeping the existing key =="
else
  echo "== minting a headscale API key and a cookie secret =="
  umask 077
  : > "$ENV_FILE"

  # 90 days rather than "never": an admin credential with no expiry is one
  # nobody ever rotates. Long enough not to be a nuisance, short enough that an
  # expiry is a real event.
  {
    printf 'HEADPLANE__HEADSCALE__API_KEY='
    docker exec headscale headscale apikeys create --expiration 90d 2>/dev/null | tail -1 | tr -d '\r\n'
    printf '\n'
    printf 'HEADPLANE__SERVER__COOKIE_SECRET='
    openssl rand -hex 16 | tr -d '\r\n'
    printf '\n'
    # The UI is served over plain HTTP on the LAN and the tailnet, so a
    # Secure-flagged cookie would never be sent back and login would fail in a
    # way that looks like a wrong password.
    printf 'HEADPLANE__SERVER__COOKIE_SECURE=false\n'
  } >> "$ENV_FILE"
  chmod 600 "$ENV_FILE"

  # Verify by SHAPE, never by value. An api key that failed to mint leaves an
  # empty assignment, and headplane would start and then fail to talk to
  # headscale in a way that looks like a headscale problem.
  for k in HEADPLANE__HEADSCALE__API_KEY HEADPLANE__SERVER__COOKIE_SECRET; do
    v_len=$(grep "^$k=" "$ENV_FILE" | cut -d= -f2- | wc -c)
    if [ "$v_len" -lt 16 ]; then
      echo "FAIL: $k came out $((v_len - 1)) chars long — refusing to start with a broken credential" >&2
      rm -f "$ENV_FILE"; exit 3
    fi
    echo "   $k: $((v_len - 1)) chars, ok"
  done
fi

mkdir -p ./data

echo
echo "== render the config with the real cookie secret =="
# Headplane validates its config file before applying environment overrides, so
# the secret cannot live only in the environment. Rendering also means nobody
# has to wonder whether the value in the committed template is live.
SECRET=$(grep '^HEADPLANE__SERVER__COOKIE_SECRET=' "$ENV_FILE" | cut -d= -f2-)
[ "${#SECRET}" -eq 32 ] || { echo "cookie secret is ${#SECRET} chars, headplane requires exactly 32" >&2; exit 4; }
umask 077
# awk rather than sed: the secret is arbitrary hex, and a sed replacement string
# would interpret any & or / it happened to contain.
awk -v s="$SECRET" '{ sub(/PLACEHOLDER_REPLACED_AT_DEPLOY__/, s); print }' \
  config.yaml > config.rendered.yaml
chmod 600 config.rendered.yaml
grep -q 'PLACEHOLDER_REPLACED_AT_DEPLOY__' config.rendered.yaml \
  && { echo "FAIL: the placeholder survived rendering" >&2; exit 5; }
echo "   config.rendered.yaml written (0600), placeholder substituted"

echo
echo "== start =="
docker compose up -d
sleep 8
docker ps --format '{{.Names}}\t{{.Status}}' | grep -E '^headplane' || true

echo
echo "== does it answer, and can it actually see headscale? =="
# Two separate questions. A UI that loads but cannot reach the control plane is
# the failure this is most likely to have, and it looks like success from the
# outside.
# /admin, not /: headplane serves under a base path and / is a genuine 404.
code=$(curl -s -o /dev/null -w '%{http_code}' -m 10 http://127.0.0.1:3200/admin || echo 000)
echo "   http://127.0.0.1:3200/admin -> $code"
echo -n "   headscale sees "
docker exec headscale headscale nodes list 2>/dev/null | tail -n +2 | grep -c . || echo "?"
echo "   nodes"

LAN_IP=$(ip -4 -o addr show scope global | awk '{print $4}' | cut -d/ -f1 | grep -v '^100\.' | head -1)
cat <<EOF

Reach it at, in order of preference:

    http://${LAN_IP:-192.168.0.13}:3200/admin     over the LAN — works even when the tailnet does not
    http://100.64.0.5:3200/admin                  over the tailnet

Not published through Traefik or Cloudflare, and GX10 has no public IP, so it is
not reachable from the internet. That is deliberate: an admin UI for the tailnet
that is only reachable over the tailnet is useless in exactly the outage it
exists to fix.
EOF
