#!/usr/bin/env bash
# Move Headscale to a VPS with a public IP. Run ON THE NEW VPS, as root.
#
# WHY A VPS AND NOT A PORT-FORWARD. Cloudflare strips the `Upgrade:
# tailscale-control-protocol` header, so the control protocol cannot survive a
# proxied path — isolated to one hop on 2026-08-22: direct to the container
# POST /ts2021 returns 400, through Cloudflare it returns 500, and headscale logs
# "No Upgrade header in TS2021 request". There is no Cloudflare setting for this;
# its WebSocket support only covers `Upgrade: websocket` on a GET.
#
# So the record must be GREY-CLOUDED, which needs a real public IP. GX10 has
# none. A home port-forward would work — inbound 80/443 were verified reachable
# on the house connection — but it puts a residential dynamic IP in public DNS,
# needs DDNS, and requires taking 443 off whatever appliance holds it today.
#
# THE NODES DO NOT RE-ENROL. `noise_private.key` IS the control plane's identity
# and clients pin it. Restoring it from restic means all 8 existing devices
# resume against the new host as if nothing happened. Generate a new one instead
# and every device becomes a stranger.
#
# WHAT MAKES THIS DIFFERENT FROM THE GX10 DEPLOY. Headscale terminates its OWN
# TLS here via ACME, instead of sitting behind traefik+Cloudflare. That is the
# entire point: nothing between the client and headscale can strip a header.
set -euo pipefail

DEST="${DEST:-/opt/headscale}"
SNAP="${SNAP:-d1abae65}"
SRC="/opt/apps/headscale-deploy"
FQDN="${FQDN:-vpn.jeffemmett.com}"

[ "$(id -u)" -eq 0 ] || { echo "run as root" >&2; exit 2; }

echo "== 0/6 pre-flight =="
# Port 443 must be free and reachable, because TLS-ALPN-01 answers the ACME
# challenge on it. If something else holds 443, ACME fails in a way that looks
# like a DNS problem.
ss -ltn "sport = :443" 2>/dev/null | grep -q LISTEN \
  && { echo "something already listens on :443 — free it first" >&2; exit 3; }
command -v docker >/dev/null || { echo "install docker first" >&2; exit 3; }
docker compose version >/dev/null 2>&1 || { echo "docker compose plugin missing" >&2; exit 3; }
command -v restic >/dev/null || { echo "install restic first" >&2; exit 3; }
[ -r "$HOME/.r2_backup_credentials" ] || {
  echo "copy ~/.r2_backup_credentials from GX10 first — restic cannot read the backup without it" >&2
  exit 3; }

mkdir -p "$DEST"

echo "== 1/6 restore config + data from R2 =="
if [ -d "$DEST/data" ]; then
  echo "   $DEST/data exists — leaving it alone"
else
  ( set -euo pipefail
    # shellcheck disable=SC1091
    source "$HOME/.r2_backup_credentials"
    restic restore "$SNAP" --target /tmp/hs-restore \
      --include "$SRC/config" --include "$SRC/data" 2>&1 | tail -2 )
  cp -a "/tmp/hs-restore$SRC/config" "$DEST/config"
  cp -a "/tmp/hs-restore$SRC/data"   "$DEST/data"
fi
chmod 600 "$DEST/data/noise_private.key"

echo "== 2/6 confirm the restore is real before trusting it =="
# A database that came back without its nodes is worse than no restore, because
# it looks like success and silently orphans every device.
NODES=$(docker run --rm -v "$DEST/data":/d:ro keinos/sqlite3 \
        sqlite3 /d/db.sqlite "SELECT COUNT(*) FROM nodes;" 2>/dev/null || echo "?")
echo "   node registrations restored: $NODES"
[ "$NODES" != "0" ] || { echo "   refusing to continue with an empty node table" >&2; exit 4; }
[ -s "$DEST/data/noise_private.key" ] || { echo "   noise_private.key is empty" >&2; exit 4; }

echo "== 3/6 switch headscale to terminating its own TLS =="
cp -a "$DEST/config/config.yaml" "$DEST/config/config.yaml.bak-$(date -u +%Y%m%dT%H%M%SZ)"
python3 - "$DEST/config/config.yaml" "$FQDN" <<'PY'
import re, sys
p, fqdn = sys.argv[1], sys.argv[2]
s = open(p).read()
# listen on 443 directly: no proxy in front means nothing can strip the upgrade.
s = re.sub(r'^listen_addr:.*$', 'listen_addr: 0.0.0.0:443', s, flags=re.M)
s = re.sub(r'^server_url:.*$', f'server_url: https://{fqdn}', s, flags=re.M)
for key, val in (('tls_letsencrypt_hostname', fqdn),
                 ('tls_letsencrypt_challenge_type', 'TLS-ALPN-01'),
                 ('tls_letsencrypt_cache_dir', '/var/lib/headscale/cache')):
    if re.search(rf'^{key}:', s, flags=re.M):
        s = re.sub(rf'^{key}:.*$', f'{key}: {val}', s, flags=re.M)
    else:
        s += f'\n{key}: {val}\n'
open(p, 'w').write(s)
print('   config.yaml rewritten for direct TLS on :443')
PY

echo "== 4/6 start =="
cp -n "$(dirname "$0")/docker-compose.yml" "$DEST/docker-compose.yml" 2>/dev/null || true
cd "$DEST"
docker compose up -d
# Poll rather than sleep: ACME on first run can take longer than any fixed wait,
# and a fixed wait that is too short reports failure for a deploy that worked.
for _ in $(seq 1 30); do
  docker ps --format '{{.Names}}' | grep -qx headscale && break
  sleep 2
done
docker ps --format '{{.Names}}\t{{.Status}}' | grep -E '^headscale' \
  || { echo "headscale did not start:" >&2; docker compose logs --tail 20 >&2; exit 5; }

echo "== 5/6 the check that actually matters =="
# /health is NOT the test. It returned 200 throughout the outage that killed the
# tailnet. A 4xx here means an unauthenticated POST reached the real headscale
# and was rejected on its merits, which proves the control path is intact.
sleep 5
TS=$(curl -s -o /dev/null -w '%{http_code}' -m 20 -X POST \
      -H 'Upgrade: tailscale-control-protocol' -H 'Connection: Upgrade' \
      "https://$FQDN/ts2021" 2>/dev/null || echo 000)
HE=$(curl -s -o /dev/null -w '%{http_code}' -m 15 "https://$FQDN/health" 2>/dev/null || echo 000)
echo "   /health  -> $HE   (informational only — it lies)"
echo "   /ts2021  -> $TS   (4xx = HEALTHY, 5xx = still proxied, 000 = DNS/firewall)"

# EXIT ON THIS. A deploy script that prints a failure and returns 0 is how a
# broken control plane gets signed off as working — which is the whole failure
# pattern this migration exists to escape.
case "$TS" in
  4[0-9][0-9]|1[0-9][0-9]) echo "   control protocol is intact" ;;
  5[0-9][0-9])
    echo "   FAILED: 5xx means something is still proxying and stripping the" >&2
    echo "           Upgrade header. Check the DNS record is GREY-clouded." >&2
    exit 6 ;;
  *)
    echo "   FAILED: no usable response ($TS). DNS may not point here yet, or" >&2
    echo "           :443 is firewalled, or ACME has not issued a cert." >&2
    echo "           Check: docker compose logs --tail 40" >&2
    exit 6 ;;
esac

echo "== 6/6 nodes =="
docker exec headscale headscale nodes list 2>&1 | head -12 || true

cat <<EOF

BEFORE this works, $FQDN must point HERE and must NOT be proxied:

  * change the record to an A record for this VPS's public IP
  * set it to DNS only (GREY cloud). An orange-clouded record puts Cloudflare
    back in the path and reintroduces the exact header-stripping bug this move
    exists to escape.

Then, on GX10, remove the split-horizon override that made it reach headscale
over localhost — it will point at the wrong place once headscale lives here:

    sudo sed -i '/vpn\\.jeffemmett\\.com/d' /etc/hosts

Nodes should reconnect on their own within a few minutes, because the restored
noise_private.key means the control plane still has the identity they enrolled
against. A device that does not:

    sudo tailscale up --login-server https://$FQDN --accept-routes
EOF
