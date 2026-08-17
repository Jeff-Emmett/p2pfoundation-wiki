#!/usr/bin/env bash
# Load a MediaWiki XML dump into the standby.
#
# Source of truth is the weekly current-revisions dump that dump-wiki.sh writes
# on Netcup and publishes at https://wiki.p2pfoundation.net/dumps/. This script
# takes a local copy of that file, so it works while Netcup is unreachable —
# which is the whole point.
#
#   ./import-dump.sh                      # uses ./p2pwiki-dump.xml.bz2
#   ./import-dump.sh /path/to/other.xml.bz2
#
# Long-running: ~41k pages. Run it under nohup/tmux, not in a shell you will
# close.
set -euo pipefail

cd "$(dirname "$0")"

SRC="${1:-./p2pwiki-dump.xml.bz2}"
[ -f "$SRC" ] || { echo "no dump at $SRC" >&2; exit 2; }

mkdir -p ./dumps
PLAIN="./dumps/import.xml"

# Decompressed on the host rather than streamed through `docker exec` stdin:
# the mediawiki image has no bzip2, and importDump.php's bz2 stream wrapper
# needs a PHP extension that image does not ship either. Disk is cheap here.
if [ ! -s "$PLAIN" ]; then
  echo "== decompressing $SRC =="
  bzcat "$SRC" > "$PLAIN"
fi
echo "xml: $(du -h "$PLAIN" | cut -f1)"

echo "== importing (this is the slow part) =="
# --no-updates skips link/category table maintenance during import; doing it
# per-revision would multiply the runtime. rebuildall.php below does it once at
# the end instead, which is the documented way round.
docker exec -i p2pwiki-standby \
  php /var/www/html/maintenance/importDump.php --no-updates --quiet /dumps/import.xml

echo "== site stats =="
docker exec -i p2pwiki-standby \
  php /var/www/html/maintenance/initSiteStats.php --update

echo
echo "== imported =="
docker exec -i p2pwiki-standby \
  php /var/www/html/maintenance/showSiteStats.php || true

cat <<'NOTE'

NEXT, and it is optional: link tables are still empty because of --no-updates,
so category listings and "what links here" are blank and internal links do not
know which targets exist. Page text renders correctly regardless. Fix with:

    docker exec -d p2pwiki-standby \
      php /var/www/html/maintenance/rebuildall.php

That takes hours on this corpus. It is a quality improvement to a fallback, not
a prerequisite for one — run it when the box is otherwise idle.
NOTE
