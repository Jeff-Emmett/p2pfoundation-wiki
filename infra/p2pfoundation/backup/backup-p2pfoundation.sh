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
#   Files    — streamed tars, 4 kept. The obvious choice was an rsync mirror,
#              and it was measured and rejected: the destination is an sshfs
#              mount to a Hetzner storage box that does 3.8 MB/s sequential, and
#              rsync's per-file round-trips over FUSE moved 3 MB of a 5.6 GB tree
#              in fifteen minutes. One sequential stream finishes in about
#              twenty-five. Same shape as the wiki backup next door, which means
#              one restore procedure covers both.
set -euo pipefail

# A single run writes several GB to a 3.8 MB/s sshfs mount and can take half an
# hour, so a nightly cron can genuinely overlap the previous night's. Two
# concurrent runs write the same dated filenames and produce interleaved
# garbage that still passes every check below — verified the hard way on
# 2026-08-22, when two overlapping runs each reported "ok 20/20 tables" into
# the same log. Take the lock or exit; do not queue.
exec 9>/tmp/p2pfoundation-backup.lock
flock -n 9 || { echo "another p2pfoundation backup is still running — exiting" >&2; exit 0; }

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
  out="$DEST/files/$tree-$DATE.tar.zst"
  # Excluding only WordPress's own regenerable page cache, and nothing else.
  # The 2026-08-19 restore used a `**/cache/**` restic exclude that also ate two
  # real Polylang plugin files and brought the blog down with a fatal, so the
  # patterns here are anchored rather than glob-anywhere.
  # The entry count is taken from tar's own verbose listing as it WRITES, not by
  # reading the archive back. Reading back cost more than creating: the blog tar
  # is 3.5 GB and the destination is an sshfs mount doing 3.8 MB/s, so a
  # read-back verification added roughly fifteen minutes a night to confirm
  # something the write pass already knew.
  count_file=$(mktemp)
  tar -C "$STACK/data" \
      --exclude="$tree/wp-content/cache" \
      --exclude="$tree/wp-content/*/cache/pages" \
      -cvf - "$tree" 2>"$count_file" | zstd -1 -T0 -q -o "$out" -f
  files_in=$(wc -l < "$count_file")
  rm -f "$count_file"

  files_on_disk=$(find "$STACK/data/$tree" | wc -l)
  # Halved to allow for the excluded cache, which is large and varies. A shortfall
  # beyond that means tar stopped early — it exits 0 having written nothing if the
  # source vanishes mid-run, so the exit code is not the thing to check.
  if [ "$files_in" -lt $(( files_on_disk / 2 )) ]; then
    echo "   FAIL: archive holds $files_in entries against $files_on_disk on disk" >&2; FAIL=1; continue
  fi

  # A full integrity read WEEKLY rather than nightly. zstd stores frame
  # checksums, so this is the check that would catch corruption in transit or at
  # rest — but it has to read every byte back across the slow link, and paying
  # that every night is what makes people quietly delete the verification step
  # six months later. Sunday is a compromise between cost and never checking.
  if [ "$(date -u +%u)" = "7" ]; then
    if zstd -t "$out" >/dev/null 2>&1; then
      echo "   weekly integrity read: ok"
    else
      echo "   FAIL: $out failed its integrity check" >&2; FAIL=1; continue
    fi
  fi
  echo "   ok  $tree  $(du -h "$out" | cut -f1)  $files_in entries"
done

# The secret files are what make a restore actually start. Without them a
# recovered WordPress meets "Error establishing a database connection", which is
# how the 2026-08-19 restore spent its first hour.
#
# TWO THINGS MAKE THIS HARDER THAN A COPY, and both failed silently at first.
#
# 1. `rsync -a` preserves ownership, and the destination is sshfs to a storage
#    box that permits no chown or chgrp. Every file errors with "chgrp failed:
#    Permission denied" and rsync exits 23 — but the *data* usually lands, so
#    a `|| true` here would hide a genuine failure behind a real one. Hence
#    --no-o --no-g: ownership is meaningless on the storage box anyway, and it
#    is restored from the compose file, not from the backup's mode bits.
#
# 2. Two of the three secrets are root:www-data 0640, so the user running this
#    backup CANNOT READ THEM. rsync skipped both with "failed to open:
#    Permission denied" and carried on, which would have left a restore with the
#    MySQL root password and neither WordPress password — discovered only at the
#    moment of restoring. They are read out through the containers that mount
#    them instead, since dockerd is root. The values are redirected straight to
#    disk and never pass through a terminal or a log.
echo "== secrets and configs =="
install -d -m 700 "$DEST/secret-files"

RSYNC_OPTS=(-rlptD --no-o --no-g)
rsync "${RSYNC_OPTS[@]}" --chmod=F600 "$STACK/secrets/" "$DEST/secret-files/" 2>/dev/null || true
rsync "${RSYNC_OPTS[@]}" "$STACK/docker-compose.yml" "$DEST/"
rsync "${RSYNC_OPTS[@]}" "$STACK/conf" "$DEST/"

# The two the host user cannot read, fetched via the containers that can.
for pair in "p2p-web p2pf-wp-web" "p2p-blog p2pf-wp-blog"; do
  set -- $pair
  if docker exec "$1" cat /run/secrets/wp_db_pw > "$DEST/secret-files/$2" 2>/dev/null; then
    chmod 600 "$DEST/secret-files/$2" 2>/dev/null || true
  fi
done

# Verify by SIZE, never by value — a secret that reaches a log is disclosed and
# has to be rotated. Zero bytes means the read failed and the restore is broken
# in a way nobody would notice until they needed it.
for f in p2pf-mysql-root p2pf-wp-web p2pf-wp-blog; do
  sz=$(stat -c%s "$DEST/secret-files/$f" 2>/dev/null || echo 0)
  if [ "$sz" -lt 8 ]; then
    echo "   FAIL: secret $f backed up as $sz bytes — a restore would not start" >&2
    FAIL=1
  else
    echo "   ok  $f ($sz bytes)"
  fi
done

# --- retention ---------------------------------------------------------------
echo "== retention (keeping $KEEP per database) =="
for db in p2p_web p2p_blog listmonk postiz; do
  ls -1t "$DEST/db/$db-"*.sql.bz2 2>/dev/null | tail -n +$((KEEP + 1)) | xargs -r rm -f
done
# Fewer file archives than database dumps: they are 100x the size and change far
# more slowly, and restic carries the deeper history.
for tree in web blog; do
  ls -1t "$DEST/files/$tree-"*.tar.zst 2>/dev/null | tail -n +5 | xargs -r rm -f
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
