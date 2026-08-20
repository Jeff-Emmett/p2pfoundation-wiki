#!/usr/bin/env bash
# Bring the tailnet back: restore Headscale from R2 and start it on GX10.
#
# Run this ON GX10. It needs no arguments and no secrets typed anywhere — the
# restic credentials are already at ~/.r2_backup_credentials on this host.
#
# What it restores, from snapshot d1abae65 (2026-08-17 03:03Z, ~3h before Netcup
# went dark):
#   config/config.yaml   server_url, ACL policy path, sqlite + WAL settings
#   config/acl.json      the access policy
#   data/db.sqlite(+wal) every node registration
#   data/noise_private.key   the control plane's identity — restore this and
#                            every existing device stays enrolled; lose it and
#                            all of them have to be re-added by hand
#
# Safe to re-run. If ~/apps/headscale/data already exists it is left alone
# rather than overwritten, because the running server will have written to it.
set -euo pipefail

DEST="${DEST:-$HOME/apps/headscale}"
SNAP="${SNAP:-d1abae65}"
SRC="/opt/apps/headscale-deploy"

command -v restic >/dev/null || { echo "restic not on PATH" >&2; exit 2; }
[ -r "$HOME/.r2_backup_credentials" ] || { echo "no ~/.r2_backup_credentials" >&2; exit 2; }
docker network inspect traefik-public >/dev/null 2>&1 || { echo "traefik-public network missing" >&2; exit 2; }

mkdir -p "$DEST"

if [ -d "$DEST/data" ]; then
  echo "== $DEST/data already present — leaving it untouched =="
else
  echo "== 1/4 restore config + data from R2 (snapshot $SNAP) =="
  # Subshell so the credentials never leak into the rest of this script's env.
  (
    set -euo pipefail
    # shellcheck disable=SC1091
    source "$HOME/.r2_backup_credentials"
    restic restore "$SNAP" --target /tmp/hs-restore \
      --include "$SRC/config" --include "$SRC/data" 2>&1 | tail -2
  )
  cp -a "/tmp/hs-restore$SRC/config" "$DEST/config"
  cp -a "/tmp/hs-restore$SRC/data"   "$DEST/data"
fi

# The noise key is the control plane's private identity; nothing but the
# container should be able to read it.
chmod 600 "$DEST/data/noise_private.key"
ls -la "$DEST/data" | sed -e 's/\(noise_private.key\)/\1   <-- control-plane identity/'

echo "== 2/4 sanity-check what came back =="
# A restored db.sqlite that has forgotten the nodes is worse than no restore at
# all, because it looks like success. Count them before starting anything.
NODES=$(sqlite3 "$DEST/data/db.sqlite" \
        "SELECT COUNT(*) FROM nodes;" 2>/dev/null \
        || docker run --rm -v "$DEST/data":/d:ro keinos/sqlite3 \
             sqlite3 /d/db.sqlite "SELECT COUNT(*) FROM nodes;" 2>/dev/null || echo "?")
echo "   node registrations in the restored database: $NODES"
grep -q "server_url: https://vpn.jeffemmett.com" "$DEST/config/config.yaml" \
  || { echo "config.yaml does not point at vpn.jeffemmett.com" >&2; exit 3; }

echo "== 3/4 start headscale =="
cp -n "$(dirname "$0")/docker-compose.yml" "$DEST/docker-compose.yml" 2>/dev/null || true
cd "$DEST"
docker compose up -d
sleep 8
docker ps --format '{{.Names}}\t{{.Status}}' | grep -E '^headscale' || true

echo "== 4/4 prove it answers, locally and then through Cloudflare =="
# Locally first: if this fails the container is wrong. If only the public check
# fails, the problem is DNS or the tunnel, which is a different fix.
docker exec headscale headscale nodes list 2>&1 | head -20 || true
echo
echo "-- through the tunnel --"
curl -sS -o /dev/null -w "   https://vpn.jeffemmett.com/health -> %{http_code}\n" \
  -m 30 https://vpn.jeffemmett.com/health || true

cat <<'EOF'

If the public check is not 200 yet, DNS may still be pointing at the dead Netcup
address. It should already be a proxied CNAME to
e74b150c-fbf0-4849-971a-35679be7d729.cfargotunnel.com (the gx10-ingress tunnel,
whose catch-all forwards to this host's traefik).

Then, on any device that has fallen out of the tailnet:

    sudo tailscale up --login-server https://vpn.jeffemmett.com --accept-routes

Existing nodes should reconnect on their own within a few minutes, because the
restored noise_private.key means the control plane still has the identity they
were enrolled against.
EOF
