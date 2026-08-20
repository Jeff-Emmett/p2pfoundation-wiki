#!/usr/bin/env bash
# Import the fortnight of edits the rebuild left behind.
#
# WHAT WENT MISSING. The standby was rebuilt on 2026-08-17 from
# p2pwiki-2026-08-02-current.xml.bz2 — even though the dumps directory also held
# 08-09 and 08-16, and p2pwiki-latest-current.xml.bz2 already pointed at 08-16.
# Netcup ran until 2026-08-17 06:21Z, so the wiki went live missing everything
# written between 2026-08-02 04:01 and the outage. Measured against the live
# site: 163 pages affected — 98 that do not exist online at all and 65 serving
# an older revision. 155 of them are Michel's; the rest are Strypey, FNahrada,
# RobertS and Patrick-T-Anderson.
#
# Michel edited on 29 of July's 31 days and then, on the live wiki, stops dead on
# 07-31 and never edits again. That shape is what a stale dump looks like; it is
# not what a fortnight off looks like.
#
# ATTRIBUTION COMES BACK TOO. importDump.php assigns imported revisions to local
# accounts of the same name unless --no-local-users is passed — the flag is NOT
# passed here. The reason the existing 45k pages are credited to `imported>Name`
# is that the 08-17 import ran against an empty user table, so every contributor
# fell through to the interwiki form. The accounts were restored first
# (restore-accounts.sql), so these 163 pages land credited to the people who
# wrote them.
#
# Safe to re-run: importDump skips revisions the wiki already has.
set -euo pipefail

CONTAINER="${CONTAINER:-p2pwiki-standby}"
GAP_XML="${GAP_XML:-$(dirname "$0")/gap-2026-08-02-to-08-16.xml}"
API="https://wiki.p2pfoundation.net/api.php"

[ -r "$GAP_XML" ] || { echo "no delta XML at $GAP_XML" >&2; exit 2; }
docker inspect "$CONTAINER" >/dev/null 2>&1 || { echo "$CONTAINER is not running" >&2; exit 2; }

echo "== 0/5 snapshot the database first =="
# A dump before an import that cannot be undone page-by-page. Cheap; the last
# one of these is what made the account restore safe to attempt.
OUT=~/p2pwiki-standby/dumps/pre-gap-import
mkdir -p "$OUT"
docker exec "${CONTAINER}-db" sh -c \
  'exec mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" --single-transaction --quick p2pwiki' \
  2>/dev/null | gzip > "$OUT/p2pwiki-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
ls -la "$OUT"

echo "== 1/5 copy the delta into the container =="
docker cp "$GAP_XML" "$CONTAINER:/tmp/gap.xml"

echo "== 2/5 dry run =="
docker exec "$CONTAINER" php /var/www/html/maintenance/importDump.php \
  --dry-run --report=25 /tmp/gap.xml

echo "== 3/5 import for real =="
# No --no-local-users: that flag would force every edit to `imported>Name`,
# which is the state being repaired, not the goal.
docker exec "$CONTAINER" php /var/www/html/maintenance/importDump.php \
  --report=25 /tmp/gap.xml

echo "== 4/5 rebuild the derived tables =="
# importDump updates the link tables as it goes, but neither of these follows
# from that: RecentChanges is not populated by an import, and site_stats is a
# stored counter that no import touches — it still reads 6 users after 2,281
# accounts were restored, which is what Special:Statistics shows the public.
docker exec "$CONTAINER" php /var/www/html/maintenance/rebuildrecentchanges.php || true
docker exec "$CONTAINER" php /var/www/html/maintenance/initSiteStats.php --update

echo "== 5/5 verify through the PUBLIC url, not the container =="
# What matters is what a reader gets, so check the same path a reader takes.
fail=0
for t in "AI is Nature" "Bioregional Economics" "Company-State" "EU Sovereignty Package" "Digital Commons Movement"; do
  enc=$(python3 -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1]))' "$t")
  got=$(curl -sS -m 40 "$API?action=query&prop=revisions&rvprop=timestamp|user&titles=$enc&format=json" \
        | python3 -c '
import sys,json
p=list(json.load(sys.stdin)["query"]["pages"].values())[0]
if "missing" in p: print("MISSING")
else:
    r=p["revisions"][0]; print(r["timestamp"], r.get("user","?"))')
  printf "  %-34s %s\n" "$t" "$got"
  case "$got" in MISSING*) fail=1 ;; esac
done

[ "$fail" -eq 0 ] || { echo "FAILED: at least one gap page is still missing" >&2; exit 3; }
echo
echo "gap import complete and verified through wiki.p2pfoundation.net"
