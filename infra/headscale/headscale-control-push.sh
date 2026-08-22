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
# RESOLVE THE NAME OURSELVES, THROUGH A PUBLIC RESOLVER, AND PIN IT.
#
# GX10's /etc/hosts contains `127.0.0.1 vpn.jeffemmett.com` — deliberately, so
# that this host reaches its own headscale directly instead of hairpinning out
# to Cloudflare and back. Excellent for the tailscale client; fatal for a
# monitor. Nothing listens on local :443, so every probe failed instantly with
# "Couldn't connect to server" and reported the control plane down while it was
# perfectly healthy for everyone on the internet.
#
# The point of this check is to test the path CLIENTS use, so it must resolve
# the way clients do. @1.1.1.1 bypasses /etc/hosts and any local resolver, and
# --resolve pins the answer for curl.
PUBLIC_IP=$(dig +short @1.1.1.1 A "${HS_URL#https://}" 2>/dev/null \
            | grep -E '^[0-9]+(\.[0-9]+){3}$' | head -1)
if [ -z "$PUBLIC_IP" ]; then
  # Cannot determine the public address, so the probe has nothing to say.
  # Report DOWN rather than silently skipping — an unrun check that looks like a
  # passing one is the failure this whole script exists to prevent.
  curl -fsS --max-time 10 -G \
    -H "Host: ${KUMA_HOST}" \
    --data-urlencode "status=down" \
    --data-urlencode "msg=probe could not resolve ${HS_URL#https://} via 1.1.1.1 — cannot test the public path" \
    "${KUMA_URL}/api/push/${TOKEN}" >/dev/null
  exit 1
fi
RESOLVE=(--resolve "${HS_URL#https://}:443:${PUBLIC_IP}")

# NOTE THE ASSIGNMENT SHAPE. The obvious `$(curl ... || echo "000")` is wrong
# and dangerously so: curl -w still prints "000" on a connection failure, so the
# `|| echo` APPENDS a second one and the variable becomes "000000". That matches
# neither the 000 case nor the 5* case, falls through to the catch-all, and the
# probe reports UP while it cannot reach the host at all. Observed live on
# 2026-08-22, reporting "control protocol answering 000000" as healthy.
# Assign first, then override only if curl itself failed.
TS_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 \
  "${RESOLVE[@]}" \
  -X POST \
  -H 'Upgrade: tailscale-control-protocol' \
  -H 'Connection: Upgrade' \
  "$HS_URL/ts2021" 2>/dev/null) || TS_CODE="000"
[ -n "$TS_CODE" ] || TS_CODE="000"

# Kept only for the message, never for the verdict — so that a green /health
# beside a red /ts2021 appears in the alert text and names the failure mode
# outright, instead of leaving the next person to rediscover it.
HEALTH_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 \
  "${RESOLVE[@]}" \
  "$HS_URL/health" 2>/dev/null) || HEALTH_CODE="000"
[ -n "$HEALTH_CODE" ] || HEALTH_CODE="000"

# EVERY branch is explicit and the catch-all is DOWN. A probe whose default is
# "healthy" lies in exactly the situations nobody anticipated — which is the
# whole failure class this script exists to catch.
case "$TS_CODE" in
  4[0-9][0-9])
    # The healthy case, and it looks wrong until you know why: an
    # unauthenticated POST reached the real headscale and was rejected on its
    # merits, which proves the path works end to end.
    STATUS="up"
    MSG="control protocol answering ${TS_CODE} via ${PUBLIC_IP}, health ${HEALTH_CODE}"
    ;;
  1[0-9][0-9])
    # The upgrade was allowed to complete outright.
    STATUS="up"
    MSG="control protocol upgraded (${TS_CODE}), health ${HEALTH_CODE}"
    ;;
  5[0-9][0-9])
    STATUS="down"
    MSG="control protocol returned ${TS_CODE} while health returns ${HEALTH_CODE}. This is the Cloudflare signature: POST with Upgrade tailscale-control-protocol is not being forwarded. Devices keep working on cached state and then log out for good in about three days"
    ;;
  000)
    STATUS="down"
    MSG="control protocol unreachable, no HTTP response from ${HS_URL}/ts2021, health says ${HEALTH_CODE}"
    ;;
  2[0-9][0-9]|3[0-9][0-9])
    # Not success here. Headscale answers a bare POST to /ts2021 with a 4xx, so
    # a 200 means something else is on the other end — a proxy error page, a
    # captive portal, a Cloudflare Access login screen.
    STATUS="down"
    MSG="unexpected ${TS_CODE} from the control protocol, expected 4xx — something other than headscale is answering. health ${HEALTH_CODE}"
    ;;
  *)
    STATUS="down"
    MSG="probe produced no usable HTTP status for ${HS_URL}/ts2021 (got '${TS_CODE}'), health says ${HEALTH_CODE}"
    ;;
esac

# -G + --data-urlencode so spaces and punctuation in the message survive.
curl -fsS --max-time 10 -G \
  -H "Host: ${KUMA_HOST}" \
  --data-urlencode "status=${STATUS}" \
  --data-urlencode "msg=${MSG}" \
  "${KUMA_URL}/api/push/${TOKEN}" >/dev/null
