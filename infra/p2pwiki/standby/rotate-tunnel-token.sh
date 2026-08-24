#!/usr/bin/env bash
# Rotate the p2pwiki-standby Cloudflare tunnel token, end to end.
#
#   secretctl run --ref CF=isec://claude-ops/prod/CLOUDFLARE_DNS_TUNNEL_TOKEN -- \
#     ./rotate-tunnel-token.sh
#
# WHY THIS SCRIPT EXISTS RATHER THAN A FEW CURL COMMANDS
# A connector token is a bearer credential for a tunnel: anyone holding it can
# register a connector and serve every hostname routed through it. So the whole
# point is that the new value never becomes visible — not in a terminal, not in
# argv (/proc is world-readable), not in shell history, and above all not in an
# agent transcript, which is permanent. This script therefore only ever moves
# the token between file descriptors, and prints sha256 prefixes so a human can
# still verify it changed.
#
# WHAT IT DOES NOT DO
# It does not delete and recreate the tunnel. PATCHing tunnel_secret rotates the
# credential while KEEPING THE TUNNEL ID, which matters because
# wiki-standby.p2pfoundation.net is a CNAME to <id>.cfargotunnel.com and the
# edge Worker's STANDBY_ORIGIN points at that hostname. Recreating the tunnel
# would mean a new id, a DNS change and a Worker redeploy for no benefit.
#
# BLAST RADIUS: between the PATCH and the container recreate, the old token is
# already dead and the standby tunnel is down — about fifteen seconds. That is
# safe precisely because this is rung 2: the wiki serves from Netcup, and the
# Worker only reaches for the standby when the origin fails.
set -euo pipefail
cd "$(dirname "$0")"

: "${CF:?run me under: secretctl run --ref CF=isec://claude-ops/prod/CLOUDFLARE_DNS_TUNNEL_TOKEN -- $0}"
ACC="${CF_ACCOUNT:-0e7b3338d5278ed1b148e6456b940913}"
TUN="${TUNNEL_ID:-0c75deea-4c44-4b7e-bb04-a91ae9b79c90}"
STANDBY_SSH="${STANDBY_SSH:-gx10}"
STANDBY_DIR="${STANDBY_DIR:-p2pwiki-standby}"
INFISICAL_REF="${INFISICAL_REF:-isec://claude-ops/prod/P2PWIKI_STANDBY_TUNNEL_TOKEN}"
API="https://api.cloudflare.com/client/v4/accounts/$ACC/cfd_tunnel/$TUN"

WORK=$(mktemp -d); chmod 700 "$WORK"
cleanup() { find "$WORK" -type f -exec shred -u {} \; 2>/dev/null || true; rm -rf "$WORK"; }
trap cleanup EXIT

echo "== 1. fingerprint the token in use now =="
curl -sS -H "Authorization: Bearer $CF" "$API/token" -o "$WORK/old.json"
python3 - "$WORK" <<'PY'
import json, sys, hashlib, os
w = sys.argv[1]
d = json.load(open(w + '/old.json'))
assert d.get('success'), d.get('errors')
t = d['result']
fd = os.open(w + '/old.tok', os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
os.write(fd, t.encode()); os.close(fd)
print('   old fingerprint:', hashlib.sha256(t.encode()).hexdigest()[:16], '(%d bytes)' % len(t))
PY

echo
echo "== 2. rotate the tunnel secret (id is preserved, so DNS stays valid) =="
python3 - "$WORK" <<'PY'
import json, os, base64, sys
w = sys.argv[1]
body = json.dumps({'tunnel_secret': base64.b64encode(os.urandom(32)).decode()})
fd = os.open(w + '/patch.json', os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
os.write(fd, body.encode()); os.close(fd)
PY
curl -sS -X PATCH -H "Authorization: Bearer $CF" -H "Content-Type: application/json" \
     --data "@$WORK/patch.json" "$API" -o "$WORK/patched.json"
python3 - "$WORK" <<'PY'
import json, sys
d = json.load(open(sys.argv[1] + '/patched.json'))
if not d.get('success'):
    print('   ROTATION REFUSED:', str(d.get('errors'))[:300]); sys.exit(3)
print('   rotated:', d['result'].get('name'), '| id unchanged:', d['result'].get('id'))
PY

echo
echo "== 3. fetch the new connector token =="
curl -sS -H "Authorization: Bearer $CF" "$API/token" -o "$WORK/new.json"
python3 - "$WORK" <<'PY'
import json, sys, hashlib, os
w = sys.argv[1]
d = json.load(open(w + '/new.json'))
assert d.get('success'), d.get('errors')
t = d['result']
if t == open(w + '/old.tok').read():
    print('   FAILED: token unchanged after rotation'); sys.exit(4)
fd = os.open(w + '/new.tok', os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
os.write(fd, t.encode()); os.close(fd)
print('   new fingerprint:', hashlib.sha256(t.encode()).hexdigest()[:16], '(%d bytes)' % len(t))
PY

echo
echo "== 4. deliver to the standby host over stdin =="
ssh -o ConnectTimeout=20 "$STANDBY_SSH" "umask 077 && cat > ~/$STANDBY_DIR/.tunnel-token.new" < "$WORK/new.tok"

echo
echo "== 5. record it in Infisical, so it does not live only on that box =="
if secretctl put "$INFISICAL_REF" < "$WORK/new.tok" >/dev/null 2>&1; then
  echo "   stored: $INFISICAL_REF"
else
  echo "   NOT stored (secretctl put failed) — the token is on the standby host only"
fi

echo
echo "== 6. swap it in and RECREATE the connector =="
# `docker restart` is not enough: the token is an argv element baked into the
# container at create time, so a restart re-runs the OLD, now-dead token.
ssh -o ConnectTimeout=20 "$STANDBY_SSH" "bash -s" <<'REMOTE'
set -euo pipefail
cd "$HOME/p2pwiki-standby"
[ -s .tunnel-token.new ] || { echo "no .tunnel-token.new" >&2; exit 2; }
cp -a .env ".env.bak-tokenrotate-$(date -u +%Y%m%dT%H%M%SZ)"
python3 - <<'PY'
import os, hashlib
tok = open('.tunnel-token.new').read().strip()
assert tok.startswith('eyJ'), 'that is not a connector token'
lines = open('.env', encoding='utf-8').read().splitlines(keepends=True)
out = [('TUNNEL_TOKEN=%s\n' % tok) if l.startswith('TUNNEL_TOKEN=') else l for l in lines]
if not any(l.startswith('TUNNEL_TOKEN=') for l in out):
    out.append('TUNNEL_TOKEN=%s\n' % tok)
fd = os.open('.env.tmp', os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
os.write(fd, ''.join(out).encode()); os.close(fd)
os.replace('.env.tmp', '.env')
print('   .env TUNNEL_TOKEN fingerprint:', hashlib.sha256(tok.encode()).hexdigest()[:16])
PY
shred -u .tunnel-token.new
docker compose up -d --force-recreate cloudflared >/dev/null 2>&1
sleep 12
docker ps --format '{{.Names}}|{{.Status}}' | grep cloudflared-p2pwiki-standby | sed 's/^/   /'
REMOTE

echo
echo "== 7. verify: every connection must be NEW =="
# A connection with an opened_at from before the rotation would mean a connector
# somewhere is still authenticating — i.e. the rotation did not take.
curl -sS -H "Authorization: Bearer $CF" "$API" -o "$WORK/final.json"
python3 - "$WORK" <<'PY'
import json, sys
r = json.load(open(sys.argv[1] + '/final.json'))['result']
print('   tunnel:', r['name'], '| status:', r['status'])
for c in (r.get('connections') or []):
    print('     conn', c.get('colo_name'), 'opened', c.get('opened_at'))
PY
curl -sS -o /dev/null -w "   wiki-standby.p2pfoundation.net -> %{http_code}\n" --max-time 40 \
     https://wiki-standby.p2pfoundation.net/Peer_to_Peer
echo
echo "== done =="
