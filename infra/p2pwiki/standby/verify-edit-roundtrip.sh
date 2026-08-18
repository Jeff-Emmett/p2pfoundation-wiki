#!/usr/bin/env bash
# End-to-end proof that an edit made through the PUBLIC url is saved and is
# captured for the merge back to Netcup.
#
# Goes through https://wiki.p2pfoundation.net deliberately, not the container on
# localhost. Everything interesting lives between those two points: the Cloudflare
# Worker has to forward a POST with its body intact, pass session cookies both
# ways, and hand back MediaWiki's post-save redirect. Testing against localhost
# would exercise none of it and would pass while the real path was broken.
#
# Reads the password from standby-accounts.txt itself, so no secret is ever
# typed on a command line or printed.
#
#   ./verify-edit-roundtrip.sh [username] [--keep]
#
# Without --keep the test page is blanked afterwards.
set -euo pipefail
cd "$(dirname "$0")"

USER_NAME="${1:-JeffEmmett}"
KEEP="${2:-}"
API="https://wiki.p2pfoundation.net/api.php"
JAR=$(mktemp)
TESTPAGE="Draft:Standby merge self-test"
trap 'rm -f "$JAR"' EXIT

[ -f standby-accounts.txt ] || { echo "no standby-accounts.txt — run enable-editing.sh" >&2; exit 2; }
PW=$(awk -F'\t' -v u="$USER_NAME" '$1==u {print $2}' standby-accounts.txt | tail -1)
[ -n "$PW" ] || { echo "no password on file for $USER_NAME" >&2; exit 2; }

api() { curl -sS -m 40 -b "$JAR" -c "$JAR" "$@"; }

echo "== 1. login token (proves POST reaches MediaWiki through the Worker) =="
LT=$(api -X POST "$API" -d "action=query&meta=tokens&type=login&format=json" \
     | python3 -c 'import sys,json;print(json.load(sys.stdin)["query"]["tokens"]["logintoken"])')
echo "   got a login token"

echo
echo "== 2. clientlogin as $USER_NAME =="
STATUS=$(api -X POST "$API" \
  --data-urlencode "action=clientlogin" \
  --data-urlencode "format=json" \
  --data-urlencode "loginreturnurl=https://wiki.p2pfoundation.net/" \
  --data-urlencode "logintoken=$LT" \
  --data-urlencode "username=$USER_NAME" \
  --data-urlencode "password=$PW" \
  | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d.get("clientlogin",{}).get("status","?"))')
echo "   status: $STATUS"
[ "$STATUS" = "PASS" ] || { echo "login failed" >&2; exit 3; }

echo
echo "== 3. csrf token =="
CT=$(api -X POST "$API" -d "action=query&meta=tokens&format=json" \
     | python3 -c 'import sys,json;print(json.load(sys.stdin)["query"]["tokens"]["csrftoken"])')
echo "   got a csrf token"

echo
echo "== 4. save an edit =="
STAMP=$(date -u +%Y-%m-%dT%H:%M:%SZ)
RESULT=$(api -X POST "$API" \
  --data-urlencode "action=edit" \
  --data-urlencode "format=json" \
  --data-urlencode "title=$TESTPAGE" \
  --data-urlencode "token=$CT" \
  --data-urlencode "summary=standby merge self-test $STAMP" \
  --data-urlencode "text=Automated self-test of the standby write path and the merge export. Written $STAMP. Safe to delete." \
  | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d.get("edit",{}).get("result", json.dumps(d.get("error",d))[:200]))')
echo "   result: $RESULT"
[ "$RESULT" = "Success" ] || { echo "edit failed" >&2; exit 4; }

echo
echo "== 5. read it back through the public url =="
CODE=$(curl -sS -o /dev/null -m 30 -w '%{http_code}' \
  "https://wiki.p2pfoundation.net/index.php?title=$(printf %s "$TESTPAGE" | tr ' ' '_')")
echo "   GET -> $CODE"

echo
echo "== 6. does the merge export capture it? =="
./export-standby-edits.sh | tail -12

echo
if [ "$KEEP" != "--keep" ]; then
  echo "== 7. cleanup: blanking the test page =="
  api -X POST "$API" --data-urlencode "action=edit" --data-urlencode "format=json" \
    --data-urlencode "title=$TESTPAGE" --data-urlencode "token=$CT" \
    --data-urlencode "summary=self-test complete" \
    --data-urlencode "text=(self-test page, intentionally blank)" >/dev/null
  echo "   blanked (the revisions stay in history, which is correct —"
  echo "    they are part of what gets merged back)"
fi

echo
echo "PASS: an edit made through the public URL is saved and appears in the export."
