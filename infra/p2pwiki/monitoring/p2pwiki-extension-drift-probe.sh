#!/usr/bin/env bash
# p2pwiki extension drift probe.
#
# WHY THIS EXISTS. wiki.p2pfoundation.net's LocalSettings.php is the one piece of
# this service that is not reproducible from the p2pfoundation-wiki repo: it lives
# on the host, in a separate private repo (gitea jeffemmett/p2pwiki), and it has
# silently lost configuration twice that we know of:
#
#   * Cite. <ref> is bundled with the mediawiki image and was present on disk the
#     whole time, just never wfLoadExtension'd. 192 pages printed the raw tag for
#     long enough that the loss predates every Internet Archive snapshot. Nobody
#     noticed, because nothing was down.
#   * $wgSMTP. Fixed 2026-06-14 (port 587 -> 465, password rotated) and reverted
#     by the 2026-08-24 failback to a pre-June copy of the file.
#
# Both are invisible to availability monitoring by construction: the wiki serves
# 200s throughout. So this probe asserts the configuration directly, two ways --
# the extension set the wiki says it has loaded, AND that <ref> actually renders.
# The second check matters because "loaded" and "working" are different claims.
#
#   exit 0  UP    expected extensions loaded and <ref> renders
#   exit 3  DOWN  drift: an extension is missing/extra, or <ref> stopped rendering
#   exit 2  DOWN  inconclusive -- could not ask the wiki after 3 tries
#
# Inconclusive is deliberately NOT exit 0. A check that cannot tell "nothing
# found" from "nothing looked at" is how the above went unnoticed for months.
# Availability monitoring covers the wiki being down, so a duplicate page here is
# the cheaper mistake.
#
# Mails Jeff on any non-zero exit via OnFailure=unit-failure-notify@%n.service.
# Pushes to Uptime Kuma as well IF P2PWIKI_EXTENSION_DRIFT_PUSH_TOKEN is present
# in /etc/uptime-kuma-push.env; absent, the email path is the whole alert.
#
# Repo copy: p2pfoundation-wiki infra/p2pwiki/p2pwiki-extension-drift-probe.sh
set -u

CONTAINER="${CONTAINER:-p2pwiki}"
WIKI_HOST="${WIKI_HOST:-wiki.p2pfoundation.net}"

# The set as of 2026-10-02, read back from siteinfo. Skins and extensions
# together, because siteinfo reports both and a lost skin is drift too.
# Overridable so the probe's own failure path can be tested without breaking the
# wiki: EXPECTED="Cite Nonexistent" /opt/scripts/p2pwiki-extension-drift-probe.sh
EXPECTED="${EXPECTED:-CategoryTree CirrusSearch Cite ConfirmEdit Elastica HitCounters QuestyCaptcha Vector WikiEditor YouTube}"

api() {
  docker exec "$CONTAINER" curl -sS --max-time 20 -G \
    -H "Host: ${WIKI_HOST}" "$@" "http://localhost/api.php" 2>/dev/null
}

# --- 1. which extensions does the wiki say it has loaded ---------------------
loaded=""
for attempt in 1 2 3; do
  raw=$(api --data-urlencode "action=query" --data-urlencode "meta=siteinfo" \
            --data-urlencode "siprop=extensions" --data-urlencode "format=json" \
            --data-urlencode "formatversion=2")
  loaded=$(printf '%s' "$raw" | python3 -c '
import sys, json
try:
    d = json.load(sys.stdin)
except Exception:
    sys.exit(1)
names = sorted({e.get("name","") for e in d.get("query", {}).get("extensions", []) if e.get("name")})
if not names:
    sys.exit(1)
print(" ".join(names))
' 2>/dev/null) && [ -n "$loaded" ] && break
  loaded=""
  [ "$attempt" -lt 3 ] && sleep 10
done

if [ -z "$loaded" ]; then
  msg="INCONCLUSIVE: siteinfo gave no parsable extension list after 3 tries (wiki down, or api.php blocked). Nothing was verified."
  status=down; rc=2
else
  want=$(printf '%s\n' $EXPECTED | sort | tr '\n' ' ')
  have=$(printf '%s\n' $loaded   | sort | tr '\n' ' ')
  missing=""; extra=""
  for e in $want; do printf '%s' " $have " | grep -q " $e " || missing="${missing}${e} "; done
  for e in $have; do printf '%s' " $want " | grep -q " $e " || extra="${extra}${e} "; done

  # --- 2. does <ref> actually render --------------------------------------
  # Parsed from text=, not from a page, so the answer cannot come out of the
  # parser cache and be stale in either direction.
  rendered=$(api --data-urlencode "action=parse" --data-urlencode "prop=text" \
                 --data-urlencode "contentmodel=wikitext" --data-urlencode "title=Sandbox" \
                 --data-urlencode "format=json" --data-urlencode "formatversion=2" \
                 --data-urlencode 'text=probe<ref>probe source</ref>
<references />')
  # Tolerant of how the quotes come back escaped, so a formatversion change
  # cannot turn a working wiki into a silent "no".
  if printf '%s' "$rendered" | grep -q 'ol class=.\{0,2\}references'; then
    refs_ok=yes
  elif printf '%s' "$rendered" | grep -q '"parse"'; then
    refs_ok=no          # the wiki answered, and the footnote list was not there
  else
    refs_ok=unknown     # no usable answer
  fi

  if [ -n "$missing" ] || [ -n "$extra" ] || [ "$refs_ok" = "no" ]; then
    msg="DRIFT in p2pwiki LocalSettings.php:"
    [ -n "$missing" ] && msg="${msg} MISSING=[${missing% }]"
    [ -n "$extra" ]   && msg="${msg} UNEXPECTED=[${extra% }]"
    [ "$refs_ok" = "no" ] && msg="${msg} <ref> no longer renders a footnote list"
    msg="${msg}. LocalSettings.php is NOT in the p2pfoundation-wiki repo -- it is tracked in gitea jeffemmett/p2pwiki, checked out at /opt/websites/p2pwiki. Compare against that repo before re-adding anything by hand."
    status=down; rc=3
  elif [ "$refs_ok" = "unknown" ]; then
    msg="INCONCLUSIVE: extension set matches (${have% }) but the <ref> render check got no usable answer."
    status=down; rc=2
  else
    msg="OK: ${have% }; <ref> renders a footnote list."
    status=up; rc=0
  fi
fi

ENV_FILE="/etc/uptime-kuma-push.env"
if [ -r "$ENV_FILE" ]; then
  # shellcheck disable=SC1090
  . "$ENV_FILE"
  if [ -n "${P2PWIKI_EXTENSION_DRIFT_PUSH_TOKEN:-}" ]; then
    curl -fsS --max-time 15 -G \
      --data-urlencode "status=${status}" \
      --data-urlencode "msg=${msg}" \
      -H "Host: status.jeffemmett.com" \
      "http://127.0.0.1/api/push/${P2PWIKI_EXTENSION_DRIFT_PUSH_TOKEN}" >/dev/null || \
      echo "kuma push failed (the exit code below is still authoritative)" >&2
  fi
fi

echo "$msg"
exit "$rc"
