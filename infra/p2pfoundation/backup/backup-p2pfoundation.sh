#!/usr/bin/env bash
# Back up the P2P Foundation WordPress estate from GX10 to the Hetzner storage box.
#
# WHY THIS EXISTS. On 2026-08-19 the whole estate was rebuilt on GX10 and the
# WordPress database came back with a SEVEN WEEK hole in it, because on Netcup:
#
#   1. the nightly mysqldump read its password from an env var, and an Infisical
#      `_FILE` migration moved that password into a file. The dump did not fail —
#      it logged `SKIP` and exited 0, every night, for seven weeks; and
#   2. the raw MariaDB volume was EXCLUDED from restic, *because* the logical
#      dump was believed to be covering it.
#
# One silent failure plus one reasonable-looking optimisation equals total loss.
# The estate was re-homed on 2026-08-19 and, until this script, NOTHING backed up
# p2p_web, p2p_blog, listmonk or postiz on the new host. The same hole, dug again.
#
# So this script's contract is: it is LOUD. Every dump is size-checked and
# table-counted against the live database, and any shortfall is a non-zero exit,
# not a log line. A backup that quietly does nothing is worse than no backup,
# because it stops anyone from looking.
#
# Writes to /mnt/hetzner-media, never local disk — a copy on the same disk as the
# original defends against exactly one failure mode, and not the likely one.
#
# Two shapes, because the two halves fail differently:
#   DB dumps — dated, 14 kept. This is the part that was lost and the part that
#              changes every day.
#   Files    — an rsync mirror, not dated tars. 5.6 GB of WordPress tree; dated
#              copies would be 78 GB of near-identical data. restic already
#              carries file history; what was missing here is currency.
set -euo pipefail

STACK="${STACK:-$HOME/apps/p2pfoundation-refugee}"
DEST="${DEST:-/mnt/hetzner-media/backups/p2pfoundation-gx10}"
DATE=$(date -u +%F)
KEEP=14
FAIL=0

mountpoint -q /mnt/hetzner-media || {
  echo "FATAL: /mnt/hetzner-media is not mounted — refusing to write a backup to local disk" >&2
  exit 2
}
mkdir -p "$DEST/db" "$DEST/files"

# A tripwire for "this file is an error message, not a database" — nothing more.
# It is deliberately far below any real WordPress dump, because a floor set to
# what you *expect* the data to weigh is a false alarm generator. The real check
# is the table count below.
MIN_BYTES=20000

# --- MariaDB (WordPress) -----------------------------------------------------
# Credentials are read INSIDE the container from $MYSQL_ROOT_PASSWORD_FILE and
# passed via MYSQL_PWD, so the value never appears in an argument list, in this
# host's environment, or in anyone's shell history.
mysql_q() {
  docker exec p2p-db sh -c \
    'MYSQL_PWD=$(cat "$MYSQL_ROOT_PASSWORD_FILE") mariadb -uroot -N -e "$1"' _ "$1"
}

for db in p2p_web p2p_blog; do
  out="$DEST/db/$db-$DATE.sql.bz2"
  live_tables=$(mysql_q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$db';" | tr -d '[:space:]')

  echo "== $db ($live_tables tables live) =="
  if [ "${live_tables:-0}" -lt 1 ]; then
    echo "   FAIL: $db reports $live_tables tables — refusing to record an empty database as a backup" >&2
    FAIL=1; continue
  fi

  docker exec p2p-db sh -c \
    'MYSQL_PWD=$(cat "$MYSQL_ROOT_PASSWORD_FILE") exec mariadb-dump -uroot \
       --single-transaction --quick --default-character-set=binary "$1"' _ "$db" \
    | bzip2 > "$out"

  size=$(stat -c%s "$out")
  if [ "$size" -lt "$MIN_BYTES" ]; then
    echo "   FAIL: $out is only $size bytes — this is the silent-SKIP failure, not a small database" >&2
    FAIL=1; continue
  fi

  # Prove the archive opens AND that it contains every table the live server has.
  # Size alone would not have caught the original fault; a truncated dump is
  # still large. Counting CREATE TABLE statements is what makes this a check.
  bzip2 -t "$out" || { echo "   FAIL: $out is corrupt" >&2; FAIL=1; continue; }
  dumped=$(bzcat "$out" | grep -c '^CREATE TABLE' || true)
  if [ "$dumped" -lt "$live_tables" ]; then
    echo "   FAIL: dump holds $dumped of $live_tables tables — truncated" >&2
    FAIL=1; continue
  fi
  echo "   ok  $(du -h "$out" | cut -f1)  $dumped/$live_tables tables"
done

# --- PostgreSQL (listmonk, postiz) -------------------------------------------
# NOTE ON WHY THIS DOES NOT USE A SIZE FLOOR. The first run flagged listmonk
# (9.5 KB) and postiz (11 KB) as suspiciously small. They are not: the
# p2pfoundation listmonk genuinely holds two subscribers. A byte threshold
# encodes an assumption about how much data a service *ought* to have, and gets
# it wrong in both directions — it cries wolf on a small database and stays
# quiet on a large one that lost half its tables. Counting tables against the
# live server tests the thing that actually matters: did the dump capture the
# schema that is really there.
pg_dump_one() { # container user db
  out="$DEST/db/$3-$DATE.sql.bz2"
  echo "== $3 (postgres in $1) =="
  if ! docker ps --format '{{.Names}}' | grep -qx "$1"; then
    echo "   FAIL: $1 is not running — a backup that silently skips is how the last hole was dug" >&2
    FAIL=1; return
  fi
  live_tables=$(docker exec "$1" psql -U "$2" -d "$3" -tAc \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public';" 2>/dev/null | tr -d '[:space:]')
  if [ "${live_tables:-0}" -lt 1 ]; then
    echo "   FAIL: $3 reports ${live_tables:-0} tables — refusing to record an empty database as a backup" >&2
    FAIL=1; return
  fi
  docker exec "$1" pg_dump -U "$2" -d "$3" | bzip2 > "$out"
  bzip2 -t "$out" || { echo "   FAIL: $out is corrupt" >&2; FAIL=1; return; }
  dumped=$(bzcat "$out" | grep -c '^CREATE TABLE' || true)
  if [ "$dumped" -lt "$live_tables" ]; then
    echo "   FAIL: dump holds $dumped of $live_tables tables — truncated" >&2; FAIL=1; return
  fi
  echo "   ok  $(du -h "$out" | cut -f1)  $dumped/$live_tables tables"
}
pg_dump_one p2p-listmonk-db    listmonk listmonk
pg_dump_one postiz-p2pf-postgres postiz  postiz

# --- WordPress trees ---------------------------------------------------------
# Includes uploads, themes and plugins. Note the plugin directories genuinely
# matter: the 2026-08-19 restore lost two Polylang files to a `**/cache/**`
# exclude and took the blog down with a fatal, so this deliberately excludes
# only WordPress's own regenerable page cache and nothing else.
echo "== files =="
for tree in web blog; do
  [ -d "$STACK/data/$tree" ] || { echo "   FAIL: $STACK/data/$tree missing" >&2; FAIL=1; continue; }
  rsync -a --delete \
    --exclude 'wp-content/cache/' \
    --exclude 'wp-content/*/cache/pages/' \
    "$STACK/data/$tree/" "$DEST/files/$tree/"
  echo "   ok  $tree  $(du -sh "$DEST/files/$tree" | cut -f1)"
done

# The secret files are what make a restore actually start. Without them a
# recovered WordPress meets "Error establishing a database connection", which is
# how the 2026-08-19 restore spent its first hour.
install -d -m 700 "$DEST/secret-files"
rsync -a --chmod=F600 "$STACK/secrets/" "$DEST/secret-files/"
rsync -a "$STACK/docker-compose.yml" "$STACK/conf" "$DEST/" 2>/dev/null || true

# --- retention ---------------------------------------------------------------
echo "== retention (keeping $KEEP per database) =="
for db in p2p_web p2p_blog listmonk postiz; do
  ls -1t "$DEST/db/$db-"*.sql.bz2 2>/dev/null | tail -n +$((KEEP + 1)) | xargs -r rm -f
done

echo
ls -lh "$DEST/db" | tail -8

if [ "$FAIL" -ne 0 ]; then
  echo
  echo "BACKUP INCOMPLETE — see the FAIL lines above. Exiting non-zero so cron mails this." >&2
  exit 1
fi
echo
echo "all p2pfoundation datastores backed up and verified"
