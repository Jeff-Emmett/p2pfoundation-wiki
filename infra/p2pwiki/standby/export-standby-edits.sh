#!/usr/bin/env bash
# Export everything edited on the standby since it became writable.
#
# Produces three artefacts in ./merge/ :
#   pagelist.txt   titles touched since the boundary
#   export.xml     those pages WITH FULL HISTORY
#   manifest.tsv   title, new revisions, last editor, last timestamp
#
# Full history rather than --current on purpose. An import of current-only
# revisions would land a single revision with no parentage; with full history
# the imported revisions slot into the page's timeline and a human reviewing the
# merge can see what the edit was based on. That is the difference between a
# merge you can audit and one you have to trust.
#
# Safe to run repeatedly — it is a read-only operation against the standby.
set -euo pipefail
cd "$(dirname "$0")"

BOUNDARY_FILE=standby-writable-since.txt
[ -f "$BOUNDARY_FILE" ] || { echo "no $BOUNDARY_FILE — editing was never enabled" >&2; exit 2; }
BOUNDARY=$(cat "$BOUNDARY_FILE")

mkdir -p merge
DBPW=$(grep ^DB_ROOT_PASSWORD= .env | cut -d= -f2)

q() { docker exec -i -e MYSQL_PWD="$DBPW" p2pwiki-standby-db mariadb -uroot -N -B -e "$1" p2pwiki 2>/dev/null; }

# The BASE watermark: the newest revision that came from the imported DUMP —
# the last moment this copy and Netcup agreed. Everything hinges on it, because
# the conflict check asks Netcup "what did you change after BASE".
#
# It is read from import-watermark.txt, recorded at import time from the dump
# itself. It is NOT derived with MAX(rev_timestamp) over the local database, and
# the difference is not academic: install.php writes its own Main_Page revision
# when the wiki is created, so the local maximum was 20260817101902 — the moment
# this standby was built. Netcup died at 06:21Z that morning, so a watermark of
# 10:19Z would ask Netcup for changes made after it was already dead, get none,
# and pronounce every single page SAFE. The conflict detector would have been
# permanently, silently green.
#
# The dump's true newest revision is 2026-07-31T12:46:47Z. Note that its
# FILENAME says 2026-08-04 — a statement about when it was generated, not about
# the data in it. The real gap to the outage is 16 days, not 13.
WATERMARK_FILE=import-watermark.txt
if [ -f "$WATERMARK_FILE" ]; then
  BASE=$(cat "$WATERMARK_FILE")
else
  echo "REFUSING TO GUESS: $WATERMARK_FILE is missing." >&2
  echo "It must hold the newest revision timestamp of the imported dump, e.g." >&2
  echo "  bzcat <dump>.xml.bz2 | grep -o '<timestamp>[^<]*' | sort | tail -1" >&2
  echo "Deriving it from the local database instead would silently classify" >&2
  echo "every page as conflict-free. See the comment above." >&2
  exit 2
fi
case "$BASE" in
  [0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]) ;;
  *) echo "watermark '$BASE' is not a 14-digit MediaWiki timestamp" >&2; exit 2 ;;
esac

echo "boundary (writable since) : $BOUNDARY"
echo "base watermark (shared)   : $BASE"
echo

# Titles must be in prefixed display form, and the prefixes on THIS wiki are not
# the defaults (ns 4 is "P2P Foundation Wiki", ns 118 is a custom "Draft"). An
# earlier version of this script built them with a hardcoded CASE statement; it
# emitted "Project:Foo" and an unprefixed Draft title, dumpBackup matched
# neither, and those pages fell out of the export while the run still printed a
# success and a count. Ask MediaWiki instead — it is the only thing that knows.
docker cp list-edited-pages.php p2pwiki-standby:/var/www/html/maintenance/listEditedPages.php >/dev/null
docker exec p2pwiki-standby php /var/www/html/maintenance/listEditedPages.php \
  --since "$BOUNDARY" > merge/pagelist.txt

COUNT=$(grep -c . merge/pagelist.txt || true)
echo "pages edited on the standby: $COUNT"
if [ "$COUNT" = "0" ]; then
  echo "nothing to export yet."
  exit 0
fi

echo
echo "== manifest =="
q "SELECT CONCAT_WS('\t', REPLACE(p.page_title,'_',' '), COUNT(*),
          MAX(COALESCE(a.actor_name,'?')), MAX(r.rev_timestamp))
   FROM page p JOIN revision r ON r.rev_page = p.page_id
   LEFT JOIN actor a ON a.actor_id = r.rev_actor
   WHERE r.rev_timestamp >= '$BOUNDARY' GROUP BY p.page_id;" > merge/manifest.tsv
column -t -s$'\t' merge/manifest.tsv 2>/dev/null | head -20 || cat merge/manifest.tsv

echo
echo "== export (full history) =="
docker cp merge/pagelist.txt p2pwiki-standby:/tmp/pagelist.txt
docker exec p2pwiki-standby php /var/www/html/maintenance/dumpBackup.php \
  --full --quiet --pagelist=/tmp/pagelist.txt > merge/export.xml
echo "export.xml: $(wc -c < merge/export.xml) bytes, $(grep -c '<page>' merge/export.xml || echo 0) pages"

printf '%s\n' "$BASE" > merge/base-watermark.txt
echo
echo "Next, once Netcup is reachable:  ./merge-to-netcup.sh --check"
