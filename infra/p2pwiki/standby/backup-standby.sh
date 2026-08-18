#!/usr/bin/env bash
# Back up the GX10 wiki to the Hetzner storage box.
#
# WHY THIS IS NOW URGENT RATHER THAN TIDY. Netcup is DEACTIVATED, so the copy
# that used to be authoritative is gone and unreachable. This box holds the only
# existing copy of the wiki, including every edit made since 2026-08-18. Until
# this ran, a disk failure on a home machine would have destroyed the P2P
# Foundation Wiki outright.
#
# Writes to /mnt/hetzner-media (a Hetzner storage box, ~6 TB free) — deliberately
# NOT to local disk. A backup on the same disk as the thing it backs up protects
# against exactly one failure mode, and not the one that matters here.
#
# Three artefacts, because they fail differently:
#   XML dump  — portable, human-readable, imports into any MediaWiki. The thing
#               you actually want when restoring somewhere new.
#   SQL dump  — exact, including users, sessions and page ids. Faster and more
#               complete to restore in place, but tied to this schema version.
#   images    — not in either dump; MediaWiki keeps files outside the database.
set -euo pipefail
cd "$(dirname "$0")"

DEST="${DEST:-/mnt/hetzner-media/backups/p2pwiki-gx10}"
DATE=$(date -u +%F)
KEEP=14

mountpoint -q /mnt/hetzner-media || { echo "FATAL: /mnt/hetzner-media is not mounted — refusing to write a backup to local disk" >&2; exit 2; }
mkdir -p "$DEST"

DBPW=$(grep ^DB_ROOT_PASSWORD= .env | cut -d= -f2)

echo "== XML (current revisions, all namespaces) =="
docker exec p2pwiki-standby php /var/www/html/maintenance/dumpBackup.php --current --quiet \
  | bzip2 > "$DEST/p2pwiki-gx10-$DATE-current.xml.bz2"
echo "   $(du -h "$DEST/p2pwiki-gx10-$DATE-current.xml.bz2" | cut -f1)"

echo "== SQL =="
docker exec -e MYSQL_PWD="$DBPW" p2pwiki-standby-db \
  mariadb-dump -uroot --single-transaction --quick --default-character-set=binary p2pwiki \
  | bzip2 > "$DEST/p2pwiki-gx10-$DATE-db.sql.bz2"
echo "   $(du -h "$DEST/p2pwiki-gx10-$DATE-db.sql.bz2" | cut -f1)"

echo "== images =="
# Read-only mount of the named volume into a throwaway container — same shape as
# Netcup's own dump-wiki.sh, so a restore procedure written for one works for both.
docker run --name p2pwiki-img-backup \
  -v p2pwiki-standby_p2pwiki-standby-images:/src:ro \
  -v "$DEST":/dst alpine \
  tar cf "/dst/p2pwiki-gx10-$DATE-images.tar" -C /src . >/dev/null 2>&1 || true
docker container rm p2pwiki-img-backup >/dev/null 2>&1 || true
[ -f "$DEST/p2pwiki-gx10-$DATE-images.tar" ] && echo "   $(du -h "$DEST/p2pwiki-gx10-$DATE-images.tar" | cut -f1)"

echo "== retention (keeping $KEEP of each) =="
for pat in current.xml.bz2 db.sql.bz2 images.tar; do
  ls -1t "$DEST"/p2pwiki-gx10-*-"$pat" 2>/dev/null | tail -n +$((KEEP + 1)) | xargs -r rm -f
done

echo
echo "== verifying the newest XML is not truncated =="
# A backup nobody has opened is a hypothesis. bzip2 -t is cheap and catches the
# common failure (a dump cut short because the container died mid-write).
bzip2 -t "$DEST/p2pwiki-gx10-$DATE-current.xml.bz2" && echo "   archive intact"
echo "   newest revision inside: $(bzcat "$DEST/p2pwiki-gx10-$DATE-current.xml.bz2" | grep -o '<timestamp>[^<]*' | sort | tail -1 | cut -d'>' -f2)"

echo
ls -lh "$DEST" | tail -6
