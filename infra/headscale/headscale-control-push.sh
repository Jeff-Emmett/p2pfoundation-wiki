#!/usr/bin/env bash
# headscale-control-push.sh — tell Uptime Kuma whether the TAILNET actually works.
#
# WHY /health IS THE WRONG THING TO MONITOR, and this is the whole point of the
# script. On 2026-08-20 Headscale was fronted by a Cloudflare tunnel. It looked
# perfect:
#
#     /health   200 {"status":"pass"}
#     /apple    200, renders
#     /ts2021   500 "Internal error"      <- the only one that mattered
#
# Those first two are ordinary GETs, so they survive anything. The control
# protocol is different: it upgrades with POST + `Upgrade:
# tailscale-control-protocol`, which Cloudflare does not forward. Clients fell
# back to the legacy /machine/register endpoint that 0.28 no longer serves and
# reported a bare 500. Every device silently logged out about three days later,
# and because the control plane was the only admin route to GX10 — the host by
# then carrying the entire estate — there was no way in to fix it.
#
# A monitor watching /health would have reported green throughout. It would
# still be reporting green now.
#
# So this probes /ts2021 and treats "did not reach headscale" as DOWN. It is
# deliberately not clever about what a healthy response looks like: headscale
# answers a bare unauthenticated POST with a 4xx, and any 4xx means our request
# reached the real server and was understood well enough to be rejected on its
# merits. 5xx, a connection failure, or a timeout mean it did not.
#
# Install on GX10:
#   cp headscale-control-push.sh ~/bin/ && chmod +x ~/bin/headscale-control-push.sh
#   create a Kuma PUSH monitor named "Headscale Control Protocol", then:
#     echo 'export KUMA_PUSH_TOKEN=<token from that monitor>' > ~/bin/headscale-push.env
#     chmod 600 ~/bin/headscale-push.env
#   crontab -e:
#     */5 * * * * . /home/mycopunk/bin/headscale-push.env && /home/mycopunk/bin/headscale-control-push.sh >/dev/null 2>&1
#
# Same shape as disk-usage-push.sh next door, so one runbook covers both.
set -uo pipefail

HS_URL="${HS_URL:-https://vpn.jeffemmett.com}"
KUMA_HOST="${KUMA_HOST:-status.jeffemmett.com}"
KUMA_URL="${KUMA_URL:-http://127.0.0.1}"   # via Traefik on :80, routed by the Host header
TOKEN="${KUMA_PUSH_TOKEN:-}"
[ -n "$TOKEN" ] || { echo "KUMA_PUSH_TOKEN unset" >&2; exit 1; }

# The real test. --max-time is generous because a cold DERP path can be slow and
# a false alarm here trains people to ignore the alarm.
TS_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 \
  -X POST \
  -H 'Upgrade: tailscale-control-protocol' \
  -H 'Connection: Upgrade' \
  "$HS_URL/ts2021" 2>/dev/null || echo "000")

# Kept only for the message, never for the verdict — so that a green /health
# beside a red /ts2021 appears in the alert text and names the failure mode
# outright, instead of leaving the next person to rediscover it.
HEALTH_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 \
  "$HS_URL/health" 2>/dev/null || echo "000")

case "$TS_CODE" in
  000)
    STATUS="down"
    MSG="control protocol unreachable, no HTTP response from ${HS_URL}/ts2021, health says ${HEALTH_CODE}"
    ;;
  5*)
    STATUS="down"
    MSG="control protocol returned ${TS_CODE} while health returns ${HEALTH_CODE}. This is the Cloudflare signature: POST with Upgrade tailscale-control-protocol is not being forwarded. Devices will keep working on cached state and then log out for good in about three days"
    ;;
  *)
    STATUS="up"
    MSG="control protocol answering ${TS_CODE}, health ${HEALTH_CODE}"
    ;;
esac

# -G + --data-urlencode so spaces and punctuation in the message survive.
curl -fsS --max-time 10 -G \
  -H "Host: ${KUMA_HOST}" \
  --data-urlencode "status=${STATUS}" \
  --data-urlencode "msg=${MSG}" \
  "${KUMA_URL}/api/push/${TOKEN}" >/dev/null
