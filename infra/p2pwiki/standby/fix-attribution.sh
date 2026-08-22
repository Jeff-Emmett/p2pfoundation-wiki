#!/usr/bin/env bash
# Run fix-attribution.sql against the live wiki, with a way back.
#
# Restores authorship for 39,325 edits that the 2026-08-17 re-import credited to
# `imported>Someone` instead of to Someone. Until this runs, Special:Contributions
# is empty for all 2,283 editors and every page history names a stranger.
#
# Run ON GX10. Takes ~2 minutes, most of it the pre-flight dump.
#
# The SQL itself is non-destructive and idempotent; this wrapper adds the two
# things SQL cannot do — a full dump taken before anything is touched, and the
# maintenance passes that rebuild MediaWiki's derived counters afterwards, which
# are what make the change visible in the UI rather than merely true in the
# database.
set -euo pipefail
cd "$(dirname "$0")"

DEST="${DEST:-$HOME/p2pwiki-standby}"
CONTAINER="${CONTAINER:-p2pwiki-standby}"
DBC="${DBC:-p2pwiki-standby-db}"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP_DIR="$DEST/dumps/pre-attribution-fix"

[ -f fix-attribution.sql ] || { echo "fix-attribution.sql is not next to this script" >&2; exit 2; }

DBPW=$(grep -m1 ^DB_ROOT_PASSWORD= "$DEST/.env" | cut -d= -f2-)
[ -n "$DBPW" ] || { echo "no DB_ROOT_PASSWORD in $DEST/.env" >&2; exit 2; }

echo "== 1/5 full dump before touching anything =="
# The account restore needed exactly this on 2026-08-20, when a column-order
# mismatch emptied the user table halfway through. Two minutes of dump is the
# cheapest insurance available.
mkdir -p "$BACKUP_DIR"
docker exec -e MYSQL_PWD="$DBPW" "$DBC" \
  mariadb-dump -uroot --single-transaction --quick --default-character-set=binary p2pwiki \
  | bzip2 > "$BACKUP_DIR/p2pwiki-$STAMP.sql.bz2"
bzip2 -t "$BACKUP_DIR/p2pwiki-$STAMP.sql.bz2"
echo "   $(du -h "$BACKUP_DIR/p2pwiki-$STAMP.sql.bz2" | cut -f1) written and verified"

echo
echo "== 2/5 before =="
docker exec -e MYSQL_PWD="$DBPW" "$DBC" mariadb -uroot -N -B p2pwiki -e "
SELECT CONCAT('   revisions credited to imported>: ', COUNT(*))
FROM revision r JOIN actor a ON a.actor_id=r.rev_actor
WHERE a.actor_name LIKE 'imported>%';"

echo
echo "== 3/5 apply =="
docker exec -i -e MYSQL_PWD="$DBPW" "$DBC" mariadb -uroot p2pwiki < fix-attribution.sql

echo
echo "== 4/5 rebuild the counters MediaWiki derives rather than stores =="
# Without these the repointed rows are correct but the UI keeps showing the old
# story: every user's edit count reads 0, and Special:Statistics disagrees with
# the tables underneath it.
# These are cosmetic relative to the row changes above, so a failure here must
# not abort — but it must not be silent either, which is the exact shape of the
# bug that cost this estate seven weeks of database.
for m in "initEditCount" "initSiteStats --update"; do
  if docker exec "$CONTAINER" php maintenance/run.php $m >/tmp/mw-$$.log 2>&1; then
    echo "   ok   $m"
  else
    echo "   WARN $m failed — row changes stand, but displayed counts may lag:" >&2
    tail -3 /tmp/mw-$$.log >&2
  fi
  rm -f /tmp/mw-$$.log
done

echo
echo "== 5/5 verify through the public API, which is what a reader actually sees =="
# The database being right is not the claim; the claim is that a contributor can
# see their own history again. So ask the wiki the way a browser would.
sleep 3
# Written to a file rather than fed on stdin: `python3 -` takes its PROGRAM from
# stdin, so it cannot also take its DATA from there.
VERIFY_PY=$(mktemp)
trap 'rm -f "$VERIFY_PY"' EXIT
cat > "$VERIFY_PY" <<'PYV'
import sys, json, urllib.parse
name = urllib.parse.unquote(sys.argv[1])
try:
    d = json.load(sys.stdin)
except Exception:
    print("   %-18s ?  could not read the API response" % name); raise SystemExit
if "error" in d:
    print("   %-18s ?  API error: %s" % (name, d["error"].get("code"))); raise SystemExit
c = d.get("query", {}).get("usercontribs", [])
if c:
    print("   %-18s OK - most recent: %s (%s)" % (name, c[0]["title"], c[0]["timestamp"]))
else:
    print("   %-18s STILL EMPTY" % name)
PYV

for u in Mbauwens Stacco%20Troncoso KevinF Elifarley; do
  # cb= defeats any edge cache, so a stale 200 cannot be mistaken for a result.
  curl -sS -m 30 -A "Mozilla/5.0 p2pwiki-verify" \
    "https://wiki.p2pfoundation.net/api.php?action=query&list=usercontribs&ucuser=$u&uclimit=1&ucprop=title%7Ctimestamp&format=json&cb=$STAMP" \
    > /tmp/uc-$$.json 2>/dev/null || echo '{}' > /tmp/uc-$$.json
  python3 "$VERIFY_PY" "$u" < /tmp/uc-$$.json
  rm -f /tmp/uc-$$.json
done

cat <<EOF

THE WAY BACK, should any of this need undoing. Print it every time rather than
only on failure: a rollback path you have to go and look for is one you will not
find at the moment you need it.

  per-table, surgical (actor ids only, nothing else touched):
    UPDATE p2pwiki.revision r JOIN p2pwiki_attrib_backup.revision_actor b
       ON b.rev_id = r.rev_id SET r.rev_actor = b.rev_actor;
    ... and likewise archive_actor / logging_actor / recentchanges_actor

  or wholesale, from the dump taken before anything ran:
    bzcat $BACKUP_DIR/p2pwiki-$STAMP.sql.bz2 \\
      | docker exec -i -e MYSQL_PWD=... $DBC mariadb -uroot p2pwiki
EOF
