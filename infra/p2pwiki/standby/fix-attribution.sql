-- Give 39,325 edits back to the people who made them.
--
-- THE DEFECT. Every page on this wiki was re-imported on 2026-08-17, and
-- importDump.php credits an edit to a LOCAL account of the same name by default
-- — but that import ran against an empty user table, because dumpBackup.php
-- exports pages and never accounts. With no local user to match, every
-- contributor fell through to MediaWiki's interwiki form and became a foreign
-- actor named `imported>Mbauwens`, `imported>Stacco Troncoso`, and so on.
--
-- The visible consequence is that Special:Contributions is EMPTY for all 2,283
-- restored editors, and page histories credit strangers. Twenty years of
-- authorship, invisible, on a wiki whose whole subject is attribution.
--
-- WHY IT IS FIXABLE NOW AND WAS NOT THEN. The accounts came back on 2026-08-20
-- (restore-accounts.sql). 602 of the 603 interwiki actors now match a real local
-- user by name, covering 39,325 of 45,355 affected revisions. The remaining ~6k
-- belong to contributors with no account in the 2026-06-29 dump. Those stay
-- foreign, correctly: inventing an account to attach an edit to would be a
-- worse error than leaving it unattributed.
--
-- SAFETY. Nothing is dropped and nothing is deleted. The old interwiki actor
-- rows are left in place even once nothing references them — they cost nothing,
-- and keeping them is what makes this reversible from actor_remap alone.
-- Pre-change state is preserved in p2pwiki_attrib_backup, whose tables are
-- created IF NOT EXISTS so that a second run cannot overwrite the true original
-- with an already-migrated copy. That discipline is why the account restore was
-- recoverable when it failed halfway through.

-- ---------------------------------------------------------------------------
-- 1. An actor row for every real user that needs one.
-- ---------------------------------------------------------------------------
-- Only 11 of the 2,283 restored accounts had edited since the rebuild, so
-- nearly every target actor row has to be created here. INSERT IGNORE rather
-- than INSERT because actor_name is UNIQUE and a handful already exist.
INSERT IGNORE INTO p2pwiki.actor (actor_user, actor_name)
SELECT u.user_id, u.user_name
FROM p2pwiki.user u
WHERE EXISTS (
  SELECT 1 FROM p2pwiki.actor a
  WHERE a.actor_name = CONCAT('imported>', u.user_name)
);

-- ---------------------------------------------------------------------------
-- 2. The mapping: old interwiki actor -> real actor.
-- ---------------------------------------------------------------------------
-- Materialised as a table rather than joined inline, for three reasons: it is
-- the audit record, it is what makes the change reversible, and MariaDB will
-- not UPDATE a table while a subquery selects from it.
CREATE TABLE IF NOT EXISTS p2pwiki.actor_remap (
  old_actor  BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  new_actor  BIGINT UNSIGNED NOT NULL,
  actor_name VARBINARY(255) NOT NULL,
  KEY (new_actor)
) ENGINE=InnoDB;

-- Emptied rather than dropped, so a re-run rebuilds the map without ever
-- putting the schema in a state where the map does not exist.
DELETE FROM p2pwiki.actor_remap;

INSERT INTO p2pwiki.actor_remap (old_actor, new_actor, actor_name)
SELECT old.actor_id, new.actor_id, new.actor_name
FROM p2pwiki.actor old
JOIN p2pwiki.user  u   ON u.user_name   = SUBSTRING(old.actor_name, 10)
JOIN p2pwiki.actor new ON new.actor_name = u.user_name
WHERE old.actor_name LIKE 'imported>%'
  AND old.actor_id <> new.actor_id;

-- ---------------------------------------------------------------------------
-- 3. Preserve exactly what is about to change.
-- ---------------------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS p2pwiki_attrib_backup;

CREATE TABLE IF NOT EXISTS p2pwiki_attrib_backup.revision_actor AS
  SELECT rev_id, rev_actor FROM p2pwiki.revision
  WHERE rev_actor IN (SELECT old_actor FROM p2pwiki.actor_remap);
CREATE TABLE IF NOT EXISTS p2pwiki_attrib_backup.archive_actor AS
  SELECT ar_id, ar_actor FROM p2pwiki.archive
  WHERE ar_actor IN (SELECT old_actor FROM p2pwiki.actor_remap);
CREATE TABLE IF NOT EXISTS p2pwiki_attrib_backup.logging_actor AS
  SELECT log_id, log_actor FROM p2pwiki.logging
  WHERE log_actor IN (SELECT old_actor FROM p2pwiki.actor_remap);
CREATE TABLE IF NOT EXISTS p2pwiki_attrib_backup.recentchanges_actor AS
  SELECT rc_id, rc_actor FROM p2pwiki.recentchanges
  WHERE rc_actor IN (SELECT old_actor FROM p2pwiki.actor_remap);
CREATE TABLE IF NOT EXISTS p2pwiki_attrib_backup.actor_remap_snapshot AS
  SELECT * FROM p2pwiki.actor_remap;

-- ---------------------------------------------------------------------------
-- 4. Repoint every column in the schema that holds an actor id.
-- ---------------------------------------------------------------------------
-- The list was enumerated from information_schema, not from memory. Missing one
-- leaves a table silently crediting the wrong person, and `image`/`oldimage`
-- are both easy to forget and exactly where Michel's PDF uploads live.
-- ipb_by_actor is included so that blocks keep naming the admin who set them.
UPDATE p2pwiki.revision      r JOIN p2pwiki.actor_remap m ON m.old_actor = r.rev_actor    SET r.rev_actor    = m.new_actor;
UPDATE p2pwiki.archive       a JOIN p2pwiki.actor_remap m ON m.old_actor = a.ar_actor     SET a.ar_actor     = m.new_actor;
UPDATE p2pwiki.logging       l JOIN p2pwiki.actor_remap m ON m.old_actor = l.log_actor    SET l.log_actor    = m.new_actor;
UPDATE p2pwiki.recentchanges c JOIN p2pwiki.actor_remap m ON m.old_actor = c.rc_actor     SET c.rc_actor     = m.new_actor;
UPDATE p2pwiki.image         i JOIN p2pwiki.actor_remap m ON m.old_actor = i.img_actor    SET i.img_actor    = m.new_actor;
UPDATE p2pwiki.oldimage      o JOIN p2pwiki.actor_remap m ON m.old_actor = o.oi_actor     SET o.oi_actor     = m.new_actor;
UPDATE p2pwiki.filearchive   f JOIN p2pwiki.actor_remap m ON m.old_actor = f.fa_actor     SET f.fa_actor     = m.new_actor;
UPDATE p2pwiki.ipblocks      b JOIN p2pwiki.actor_remap m ON m.old_actor = b.ipb_by_actor SET b.ipb_by_actor = m.new_actor;

-- ---------------------------------------------------------------------------
-- 5. What it did.
-- ---------------------------------------------------------------------------
SELECT
  (SELECT COUNT(*) FROM p2pwiki.actor_remap)                     AS contributors_reunited,
  (SELECT COUNT(*) FROM p2pwiki_attrib_backup.revision_actor)    AS revisions_moved,
  (SELECT COUNT(*) FROM p2pwiki.revision r
     JOIN p2pwiki.actor a ON a.actor_id = r.rev_actor
     WHERE a.actor_name LIKE 'imported>%')                       AS revisions_still_foreign;
