#!/usr/bin/env bash
# Move the 21,324-subscriber audience from the CosmoLocal Foundation listmonk
# into the P2P Foundation one. Run ON GX10.
#
# THE SITUATION. newsletter.p2pfoundation.net is served by `p2p-listmonk`
# (~/apps/p2p-newsletter), which holds TWO subscribers — it is a fresh install
# that never received any data. The real audience, 21,324 people, sits in a
# different listmonk entirely (`listmonk` / ~/apps/netcup-failover) on a list
# named "CosmoLocal Foundation". This moves them to where the P2P Foundation
# newsletter actually sends from.
#
# WHY THIS IS PURE SQL AND NOT listmonk's OWN IMPORT. It is the difference
# between a data migration and mailing 21,324 people by accident. listmonk's
# import path can dispatch opt-in confirmation messages, and its API can too.
# Writing to Postgres directly cannot send anything: nothing in listmonk queues
# mail on an INSERT. Mail is only ever dispatched by starting a campaign or by
# an explicit opt-in call, and this script does neither. That property is the
# reason for the whole approach — do not "simplify" this later into a CSV upload
# through the UI.
#
# The destination has one enabled SMTP block and a single draft campaign. Drafts
# do not fire. Nothing here changes either.
#
# WHAT IS COPIED, AND WHAT IS DELIBERATELY NOT.
#   copied: email, name, attribs, subscriber status, per-list subscription
#           status, original uuid and original created_at
#   not:    the source rows. Nothing is deleted from the CLF listmonk. It keeps
#           working exactly as it does now, and clearing it is a separate,
#           explicit decision — 21,324 records is not something to remove as a
#           side effect of a copy.
#
# The uuid is preserved on purpose: it is what unsubscribe and preference links
# are keyed on, so a person who kept an old email still resolves to the same
# record rather than becoming a stranger.
#
# Safe to re-run. Subscribers already present are matched on email and left
# alone; only the list membership is added.
set -euo pipefail

SRC_DB="${SRC_DB:-listmonk-db}"
DST_DB="${DST_DB:-p2p-listmonk-db}"
SRC_LIST="${SRC_LIST:-CosmoLocal Foundation}"
DST_LIST="${DST_LIST:-P2P Foundation}"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
WORK="$HOME/p2pf-listmonk-migration"
BACKUP="$WORK/backups"

mkdir -p "$BACKUP"
chmod 700 "$WORK"

sql_src() { docker exec -i "$SRC_DB" psql -U listmonk -d listmonk -tAc "$1"; }
sql_dst() { docker exec -i "$DST_DB" psql -U listmonk -d listmonk -tAc "$1"; }

echo "== 1/7 both databases dumped before anything is touched =="
# 21,324 records of other people's personal data. A dump first is not optional.
for pair in "$SRC_DB clf" "$DST_DB p2pf"; do
  set -- $pair
  docker exec "$1" pg_dump -U listmonk -d listmonk | gzip > "$BACKUP/$2-$STAMP.sql.gz"
  sz=$(stat -c%s "$BACKUP/$2-$STAMP.sql.gz")
  [ "$sz" -gt 10000 ] || { echo "   FAIL: $2 dump is only $sz bytes" >&2; exit 2; }
  echo "   $2: $(du -h "$BACKUP/$2-$STAMP.sql.gz" | cut -f1)"
done
chmod 600 "$BACKUP"/*.gz

echo
echo "== 2/7 before =="
SRC_N=$(sql_src "SELECT COUNT(*) FROM subscriber_lists sl JOIN lists l ON l.id=sl.list_id WHERE l.name='$SRC_LIST';")
DST_N=$(sql_dst "SELECT COUNT(*) FROM subscribers;")
echo "   source list '$SRC_LIST': $SRC_N subscribers"
echo "   destination total:       $DST_N subscribers"
[ "$SRC_N" -gt 0 ] || { echo "   nothing to migrate" >&2; exit 3; }

echo
echo "== 3/7 the destination list =="
# Created to match the source's type and opt-in mode. A double opt-in list would
# leave every imported member unconfirmed and unmailable, which looks like the
# migration failed; the source is single opt-in, so this is too.
sql_dst "
INSERT INTO lists (uuid, name, type, optin, tags, created_at, updated_at)
SELECT gen_random_uuid(), '$DST_LIST', 'private', 'single', '{}', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM lists WHERE name = '$DST_LIST');" >/dev/null
DST_LIST_ID=$(sql_dst "SELECT id FROM lists WHERE name='$DST_LIST';")
echo "   '$DST_LIST' is list id $DST_LIST_ID"

echo
echo "== 4/7 export =="
# 0600 in a 700 directory, and deleted at the end: this file is 21,324 people's
# email addresses in plain text.
EXPORT="$WORK/clf-export-$STAMP.csv"
umask 077
docker exec "$SRC_DB" psql -U listmonk -d listmonk -c "\copy (
  SELECT s.uuid, s.email, s.name, s.attribs, s.status, s.created_at, sl.status
  FROM subscribers s
  JOIN subscriber_lists sl ON sl.subscriber_id = s.id
  JOIN lists l ON l.id = sl.list_id
  WHERE l.name = '$SRC_LIST'
) TO STDOUT WITH (FORMAT csv)" > "$EXPORT"
chmod 600 "$EXPORT"
LINES=$(wc -l < "$EXPORT")
echo "   exported $LINES rows"
[ "$LINES" -eq "$SRC_N" ] || { echo "   FAIL: exported $LINES but the list holds $SRC_N" >&2; exit 4; }

echo
echo "== 5/7 stage and merge =="
sql_dst "
CREATE TABLE IF NOT EXISTS migration_stage (
  uuid UUID, email TEXT, name TEXT, attribs JSONB,
  status TEXT, created_at TIMESTAMPTZ, sub_status TEXT
);
TRUNCATE migration_stage;" >/dev/null

docker exec -i "$DST_DB" psql -U listmonk -d listmonk \
  -c "\copy migration_stage FROM STDIN WITH (FORMAT csv)" < "$EXPORT"
STAGED=$(sql_dst "SELECT COUNT(*) FROM migration_stage;")
echo "   staged $STAGED rows"
[ "$STAGED" -eq "$SRC_N" ] || { echo "   FAIL: staged $STAGED of $SRC_N" >&2; exit 5; }

# A uuid collision would abort the whole INSERT, because the ON CONFLICT target
# is email and a uuid clash is therefore an unhandled constraint violation
# rather than a skip. With random v4 uuids and two rows in the destination this
# is vanishingly unlikely — but "vanishingly unlikely" and "silently corrupts
# half a migration" are a bad pair, and checking costs one query.
UUID_CLASH=$(sql_dst "
SELECT COUNT(*) FROM migration_stage m
JOIN subscribers s ON s.uuid = m.uuid
WHERE s.email IS DISTINCT FROM m.email;")
if [ "$UUID_CLASH" -ne 0 ]; then
  echo "   FAIL: $UUID_CLASH source uuids already belong to a different address here." >&2
  echo "         Re-run with uuids regenerated rather than carried across." >&2
  exit 7
fi

# Insert the people. ON CONFLICT on email so a re-run, or an address the
# destination already knows, updates nothing and breaks nothing. The uuid is
# carried across, which is why the conflict target must be email rather than
# uuid: two different people can never share an email, but a uuid collision
# would silently drop a real subscriber.
sql_dst "
INSERT INTO subscribers (uuid, email, name, attribs, status, created_at, updated_at)
SELECT uuid, email, name, COALESCE(attribs, '{}'::jsonb),
       status::subscriber_status, created_at, NOW()
FROM migration_stage
ON CONFLICT (email) DO NOTHING;" >/dev/null

# And their membership of the new list, with the subscription status they had.
sql_dst "
INSERT INTO subscriber_lists (subscriber_id, list_id, status, created_at, updated_at)
SELECT s.id, $DST_LIST_ID, m.sub_status::subscription_status, m.created_at, NOW()
FROM migration_stage m
JOIN subscribers s ON s.email = m.email
ON CONFLICT (subscriber_id, list_id) DO NOTHING;" >/dev/null

echo
echo "== 6/7 verify =="
FINAL=$(sql_dst "SELECT COUNT(*) FROM subscriber_lists WHERE list_id=$DST_LIST_ID;")
TOTAL=$(sql_dst "SELECT COUNT(*) FROM subscribers;")
MISSING=$(sql_dst "
SELECT COUNT(*) FROM migration_stage m
LEFT JOIN subscribers s ON s.email = m.email WHERE s.id IS NULL;")
echo "   on '$DST_LIST':          $FINAL   (source had $SRC_N)"
echo "   destination total:       $TOTAL   (was $DST_N)"
echo "   source rows unaccounted: $MISSING"

# Duplicate emails in the source collapse into one destination row, so the two
# counts can legitimately differ. Report it as arithmetic rather than as a fault.
DUPES=$(sql_dst "SELECT COUNT(*) - COUNT(DISTINCT email) FROM migration_stage;")
[ "$DUPES" -gt 0 ] && echo "   (source contained $DUPES duplicate addresses, collapsed into one record each)"

if [ "$MISSING" -ne 0 ]; then
  echo "   FAIL: $MISSING source addresses did not land" >&2
  exit 6
fi

echo
echo "== 7/7 clean up the plaintext export =="
sql_dst "DROP TABLE IF EXISTS migration_stage;" >/dev/null
shred -u "$EXPORT" 2>/dev/null || rm -f "$EXPORT"
echo "   $EXPORT removed"

cat <<EOF

Done. No mail was sent and none can have been: every write above was a SQL
INSERT, and listmonk dispatches only on a campaign start or an explicit opt-in
call.

NOT DONE, deliberately — the source list still holds all $SRC_N records. The
CosmoLocal Foundation listmonk is untouched and still works. Removing them is a
separate decision and a separate command.

Backups, if any of this needs undoing:
  $BACKUP/clf-$STAMP.sql.gz
  $BACKUP/p2pf-$STAMP.sql.gz
EOF
