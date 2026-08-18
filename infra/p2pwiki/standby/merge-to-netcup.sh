#!/usr/bin/env bash
# Replay standby edits into the Netcup wiki, once Netcup is reachable again.
#
#   ./merge-to-netcup.sh --check        report SAFE and CONFLICT pages, change nothing
#   ./merge-to-netcup.sh --apply-safe   import only the SAFE pages
#
# There is no --apply-all, and that is deliberate. A conflict here is not a
# merge-tool inconvenience, it is "the live text of an article silently reverts
# by up to two weeks", and no script should decide that on a human's behalf.
#
# WHAT A CONFLICT IS
#
# This standby was built from the 2026-08-04 XML dump. Netcup kept being edited
# until it dropped at 2026-08-17 06:21Z. So for any page:
#
#   edited on Netcup after the base watermark?   AND   edited on the standby?
#     -> CONFLICT. Our text descends from an older version. Importing it makes
#        that older lineage current and the Netcup edit stops being visible.
#        It survives in history and is recoverable, but the article regresses.
#
#   edited only on the standby?
#     -> SAFE. Nothing on Netcup to lose. Import freely.
#
#   created on the standby (does not exist on Netcup)?
#     -> SAFE, and cannot conflict by construction.
#
# Expect few conflicts: the wiki averages ~9.4 edits/active-day, so the 13-day
# gap covers roughly 60-100 pages out of 40,647. But the pages people edit are
# the pages people edit, so the overlap is not random — check, do not assume.
set -euo pipefail
cd "$(dirname "$0")"

MODE="${1:---check}"
NETCUP_SSH="${NETCUP_SSH:-netcup}"
NETCUP_CONTAINER="${NETCUP_CONTAINER:-p2pwiki}"

[ -f merge/export.xml ]        || { echo "run ./export-standby-edits.sh first" >&2; exit 2; }
[ -f merge/base-watermark.txt ] || { echo "missing merge/base-watermark.txt" >&2; exit 2; }
BASE=$(cat merge/base-watermark.txt)

echo "== reachability =="
if ! timeout 20 ssh -o ConnectTimeout=10 -o BatchMode=yes "$NETCUP_SSH" true 2>/dev/null; then
  echo "Netcup is not reachable over ssh ($NETCUP_SSH). Nothing to do yet." >&2
  exit 3
fi
echo "   ok"

echo
echo "== which of our pages did Netcup also change after $BASE ? =="
# Asked of Netcup's own database rather than inferred from anything local.
sed 's/^/ /' merge/pagelist.txt > /dev/null   # ensure readable
NETCUP_CHANGED=$(
  awk '{gsub(/'"'"'/,"'"'"''"'"'"); printf "'"'"'%s'"'"',", $0}' merge/pagelist.txt |
  sed 's/,$//' |
  xargs -0 -I{} ssh "$NETCUP_SSH" "docker exec -i $NETCUP_CONTAINER php -r '
     \$t = explode(chr(10), stream_get_contents(STDIN));
  '" 2>/dev/null || true
)

# Simpler and more reliable than shipping a SQL IN() list: ask Netcup for every
# page it changed after the watermark, then intersect locally.
ssh "$NETCUP_SSH" "docker exec -i $NETCUP_CONTAINER php /var/www/html/maintenance/sql.php --query=\
\"SELECT CONCAT(page_namespace,'|',page_title) FROM page JOIN revision ON rev_page=page_id \
WHERE rev_timestamp > '$BASE' GROUP BY page_id\" --format=plain" 2>/dev/null \
  | sed 's/^ *//' > merge/netcup-changed.txt || {
      echo "could not query Netcup; is the container name '$NETCUP_CONTAINER' right?" >&2; exit 4; }

python3 - <<'PY'
import re,os
def norm(s): return s.strip().replace('_',' ')
ours=set()
for l in open('merge/pagelist.txt',encoding='utf-8'):
    if l.strip(): ours.add(norm(l))
theirs=set()
for l in open('merge/netcup-changed.txt',encoding='utf-8'):
    l=l.strip()
    if not l or '|' not in l: continue
    ns,title=l.split('|',1)
    ns=ns.strip()
    pref={'0':'','1':'Talk:','2':'User:','3':'User talk:','4':'Project:','6':'File:',
          '8':'MediaWiki:','10':'Template:','12':'Help:','14':'Category:'}.get(ns,'')
    theirs.add(norm(pref+title))
conflict=sorted(ours & theirs)
safe=sorted(ours - theirs)
open('merge/safe.txt','w',encoding='utf-8').write("\n".join(safe)+("\n" if safe else ""))
open('merge/conflict.txt','w',encoding='utf-8').write("\n".join(conflict)+("\n" if conflict else ""))
print(f"  standby-edited pages : {len(ours)}")
print(f"  SAFE                 : {len(safe)}")
print(f"  CONFLICT             : {len(conflict)}")
if conflict:
    print("\n  pages changed on BOTH sides — these need a human:")
    for c in conflict[:40]: print("    -",c)
PY

if [ "$MODE" = "--check" ]; then
  cat <<'EOF'

Nothing was changed. Review merge/conflict.txt.

For each conflicting page, compare before deciding:
  standby : https://wiki.p2pfoundation.net/index.php?title=<PAGE>&action=history
  netcup  : the same page's history once DNS points back at it
Resolve by hand — usually by re-applying the standby edit on top of Netcup's
current text, which keeps both changes.

When ready:  ./merge-to-netcup.sh --apply-safe
EOF
  exit 0
fi

[ "$MODE" = "--apply-safe" ] || { echo "unknown mode: $MODE" >&2; exit 2; }

echo
echo "== re-exporting ONLY the safe pages =="
docker cp merge/safe.txt p2pwiki-standby:/tmp/safe.txt
docker exec p2pwiki-standby php /var/www/html/maintenance/dumpBackup.php \
  --full --quiet --pagelist=/tmp/safe.txt > merge/export-safe.xml
echo "   $(grep -c '<page>' merge/export-safe.xml || echo 0) pages, $(wc -c < merge/export-safe.xml) bytes"

echo
echo "== importing into Netcup =="
# --no-updates keeps the import fast; link tables are rebuilt straight after for
# the affected pages only.
scp -q merge/export-safe.xml "$NETCUP_SSH":/tmp/standby-merge.xml
ssh "$NETCUP_SSH" "docker cp /tmp/standby-merge.xml $NETCUP_CONTAINER:/tmp/ && \
  docker exec -i $NETCUP_CONTAINER php /var/www/html/maintenance/importDump.php \
    --no-updates /tmp/standby-merge.xml && \
  docker exec -i $NETCUP_CONTAINER php /var/www/html/maintenance/rebuildrecentchanges.php && \
  docker exec -i $NETCUP_CONTAINER php /var/www/html/maintenance/initSiteStats.php --update"

echo
echo "== done =="
echo "Imported the safe set. Conflicts in merge/conflict.txt are still outstanding."
echo "Re-run ./export-standby-edits.sh before any further merge so the boundary"
echo "reflects what has already been replayed."
