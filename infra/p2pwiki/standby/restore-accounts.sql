-- Reinstate the p2pwiki accounts on the GX10 standby, with their ORIGINAL passwords.
--
-- WHY THIS IS POSSIBLE AT ALL. The standby was rebuilt from an XML dump, and
-- dumpBackup.php exports pages, never accounts — so the rebuilt wiki had seven
-- users, five of them hand-made placeholders. The real account table survives in
-- exactly one place: /var/backups/db-dumps/p2pwiki-db.sql inside restic snapshot
-- c5790d62 (2026-06-29). That is the last night the nightly MariaDB dump ran
-- before the Infisical MYSQL_ROOT_PASSWORD_FILE migration made it log
-- "SKIP: p2pwiki-db (no root password found)" instead of dumping.
--
-- "Original passcodes" means the original password HASHES, which is the only
-- form a password is ever stored in. Nobody recovers the plaintext, and nobody
-- needs to: MediaWiki 1.40 verifies these hashes directly, so every editor's
-- existing password simply works again. Of 2,281 accounts, 1,889 carry a usable
-- hash (1,553 legacy `:B:` MWSaltedPassword, 336 pbkdf2 — MediaWiki verifies
-- both and silently upgrades the old ones on first successful login). The
-- remaining 392 have an empty user_password: those accounts had no password on
-- the original wiki either, so nothing is lost by them arriving the same way.
--
-- EVERY INSERT NAMES ITS COLUMNS, and that is not style. Both wikis run
-- MediaWiki 1.40 and both tables have the same fifteen column NAMES, but the
-- original wiki dates from 2006 and was upgraded in place for twenty years, so
-- its physical column ORDER drifted: user_newpass_time sits sixth in a fresh
-- 1.40 install and thirteenth here, and ipblocks is shuffled worse. `SELECT *`
-- matches positionally, so the first attempt cheerfully tried to write
-- user_email into user_newpass_time and died on "Data too long". A column-count
-- check passes this; only a name-by-name check catches it.
--
-- Reads p2pwiki_orig.* (loaded from that dump) and writes p2pwiki.*.
-- Re-runnable: the pre-restore snapshot is taken only once, and the account
-- tables are cleared before being refilled.

-- ---------------------------------------------------------------- safety net
-- IF NOT EXISTS, never DROP: a re-run must not overwrite the true pre-restore
-- state with whatever a half-finished earlier run left behind.
CREATE DATABASE IF NOT EXISTS p2pwiki_prerestore;
CREATE TABLE IF NOT EXISTS p2pwiki_prerestore.user               AS SELECT * FROM p2pwiki.user;
CREATE TABLE IF NOT EXISTS p2pwiki_prerestore.user_groups        AS SELECT * FROM p2pwiki.user_groups;
CREATE TABLE IF NOT EXISTS p2pwiki_prerestore.user_properties    AS SELECT * FROM p2pwiki.user_properties;
CREATE TABLE IF NOT EXISTS p2pwiki_prerestore.user_former_groups AS SELECT * FROM p2pwiki.user_former_groups;
CREATE TABLE IF NOT EXISTS p2pwiki_prerestore.user_newtalk       AS SELECT * FROM p2pwiki.user_newtalk;
CREATE TABLE IF NOT EXISTS p2pwiki_prerestore.actor              AS SELECT * FROM p2pwiki.actor;
CREATE TABLE IF NOT EXISTS p2pwiki_prerestore.ipblocks           AS SELECT * FROM p2pwiki.ipblocks;
CREATE TABLE IF NOT EXISTS p2pwiki_prerestore.watchlist          AS SELECT * FROM p2pwiki.watchlist;

-- ------------------------------------------------- clear the original id range
-- Original ids run 2..2946. Only two live accounts are not superseded by the
-- original table: `Admin` (id 1, the rebuild's own administrator — outside the
-- range, so it is left completely alone) and `MediaWiki default`, the installer's
-- system account, which is squatting on id 2. Id 2 belongs to a real person on
-- the original wiki, so the system account moves above the range instead.
UPDATE p2pwiki.user_groups     SET ug_user = 2947 WHERE ug_user = 2;
UPDATE p2pwiki.user_properties SET up_user = 2947 WHERE up_user = 2;
UPDATE p2pwiki.user            SET user_id = 2947 WHERE user_id = 2 AND user_name = 'MediaWiki default';

-- Everything else goes. The five placeholders (Mbauwens, JeffEmmett, Asimong,
-- Strypey, Maintenance script) exist in the original table under their own ids —
-- 9, 2943, 1247, 853 and 2563 — so the stubs are replaced by the real rows.
-- Their edits follow them: revisions are attributed through `actor`, which is
-- relinked by NAME below, not by id.
DELETE FROM p2pwiki.user_groups     WHERE ug_user  NOT IN (1, 2947);
DELETE FROM p2pwiki.user_properties WHERE up_user  NOT IN (1, 2947);
DELETE FROM p2pwiki.user            WHERE user_id  NOT IN (1, 2947);
DELETE FROM p2pwiki.user_former_groups;
DELETE FROM p2pwiki.user_newtalk;

-- --------------------------------------------------------------- the accounts
INSERT INTO p2pwiki.user
  (user_id, user_name, user_real_name, user_password, user_newpassword,
   user_newpass_time, user_email, user_touched, user_token,
   user_email_authenticated, user_email_token, user_email_token_expires,
   user_registration, user_editcount, user_password_expires)
SELECT
   user_id, user_name, user_real_name, user_password, user_newpassword,
   user_newpass_time, user_email, user_touched, user_token,
   user_email_authenticated, user_email_token, user_email_token_expires,
   user_registration, user_editcount, user_password_expires
FROM p2pwiki_orig.user;

ALTER TABLE p2pwiki.user AUTO_INCREMENT = 3000;

-- ------------------------------------------------------------------ the rights
-- 18 sysops, 15 bureaucrats, and the rest, exactly as they stood.
--
-- IGNORE because the original user_groups carries orphan rows for ug_user = 1 —
-- an account that no longer exists in its own user table (original ids start at
-- 2). Id 1 is the rebuild's `Admin` here, so those orphans would collide with
-- Admin's own bureaucrat/sysop/interface-admin rows. Skipping them is correct in
-- both readings: they belong to nobody on the source wiki, and Admin keeps the
-- rights it was given during the rebuild.
INSERT IGNORE INTO p2pwiki.user_groups (ug_user, ug_group, ug_expiry)
  SELECT ug_user, ug_group, ug_expiry FROM p2pwiki_orig.user_groups;
INSERT IGNORE INTO p2pwiki.user_former_groups (ufg_user, ufg_group)
  SELECT ufg_user, ufg_group FROM p2pwiki_orig.user_former_groups;
INSERT IGNORE INTO p2pwiki.user_properties (up_user, up_property, up_value)
  SELECT up_user, up_property, up_value FROM p2pwiki_orig.user_properties;
INSERT IGNORE INTO p2pwiki.user_newtalk (user_id, user_ip, user_last_timestamp)
  SELECT user_id, user_ip, user_last_timestamp FROM p2pwiki_orig.user_newtalk;

-- ------------------------------------------------------------ edit attribution
-- The XML import created 633 actor rows and could link only the seven accounts
-- that existed at the time, leaving 626 authors floating unattached. Relinking by
-- name attaches fifteen years of edit history to the accounts that made it.
--
-- Cleared to NULL first on purpose: actor_user carries a UNIQUE index, and a
-- single UPDATE that shuffles ids around can collide with a row it has not
-- reached yet. Names that match no account (IP addresses) correctly stay NULL.
UPDATE p2pwiki.actor SET actor_user = NULL;
UPDATE p2pwiki.actor a JOIN p2pwiki.user u ON u.user_name = a.actor_name
   SET a.actor_user = u.user_id;

-- ----------------------------------------------------------------- the blocks
-- 211 blocks, 166 of them on named accounts. These MUST come back: without them
-- every account ever blocked for abuse returns as a working login, which would
-- make this restore a net security regression rather than a recovery.
--
-- Two columns cannot be copied literally. ipb_by_actor is an actor id from the
-- old wiki and points at someone else here; ipb_reason_id indexes the `comment`
-- table, which the rebuild renumbered. Both are remapped by content.
DROP TABLE IF EXISTS p2pwiki_orig.actor_map;
DROP TABLE IF EXISTS p2pwiki_orig.comment_map;

-- Blocking admins who never appear in the imported revisions have no local actor
-- row at all, so create those before mapping.
INSERT IGNORE INTO p2pwiki.actor (actor_user, actor_name)
  SELECT MAX(u.user_id), oa.actor_name
    FROM p2pwiki_orig.ipblocks b
    JOIN p2pwiki_orig.actor oa ON oa.actor_id = b.ipb_by_actor
    LEFT JOIN p2pwiki.user u ON u.user_name = oa.actor_name
   GROUP BY oa.actor_name;

CREATE TABLE p2pwiki_orig.actor_map AS
  SELECT oa.actor_id AS old_id, na.actor_id AS new_id
    FROM p2pwiki_orig.actor oa
    JOIN p2pwiki.actor na ON na.actor_name = oa.actor_name;
CREATE INDEX am_old ON p2pwiki_orig.actor_map (old_id);

-- Only the reasons the blocks actually use are carried over, as fresh rows.
INSERT INTO p2pwiki.comment (comment_hash, comment_text, comment_data)
  SELECT c.comment_hash, c.comment_text, c.comment_data
    FROM p2pwiki_orig.comment c
   WHERE c.comment_id IN (SELECT ipb_reason_id FROM p2pwiki_orig.ipblocks);

CREATE TABLE p2pwiki_orig.comment_map AS
  SELECT oc.comment_id AS old_id, MAX(nc.comment_id) AS new_id
    FROM p2pwiki_orig.comment oc
    JOIN p2pwiki.comment nc
      ON nc.comment_hash = oc.comment_hash
     AND nc.comment_text = oc.comment_text
   WHERE oc.comment_id IN (SELECT ipb_reason_id FROM p2pwiki_orig.ipblocks)
   GROUP BY oc.comment_id;
CREATE INDEX cm_old ON p2pwiki_orig.comment_map (old_id);

DELETE FROM p2pwiki.ipblocks;
INSERT INTO p2pwiki.ipblocks
  (ipb_id, ipb_address, ipb_user, ipb_by_actor, ipb_reason_id, ipb_timestamp,
   ipb_auto, ipb_anon_only, ipb_create_account, ipb_enable_autoblock, ipb_expiry,
   ipb_range_start, ipb_range_end, ipb_deleted, ipb_block_email,
   ipb_allow_usertalk, ipb_parent_block_id, ipb_sitewide)
SELECT
   ipb_id, ipb_address, ipb_user, ipb_by_actor, ipb_reason_id, ipb_timestamp,
   ipb_auto, ipb_anon_only, ipb_create_account, ipb_enable_autoblock, ipb_expiry,
   ipb_range_start, ipb_range_end, ipb_deleted, ipb_block_email,
   ipb_allow_usertalk, ipb_parent_block_id, ipb_sitewide
FROM p2pwiki_orig.ipblocks;

UPDATE p2pwiki.ipblocks b JOIN p2pwiki_orig.actor_map   m ON m.old_id = b.ipb_by_actor
   SET b.ipb_by_actor = m.new_id;
UPDATE p2pwiki.ipblocks b JOIN p2pwiki_orig.comment_map m ON m.old_id = b.ipb_reason_id
   SET b.ipb_reason_id = m.new_id;

-- -------------------------------------------------------------- the watchlists
-- 22,252 rows, keyed on the original user ids, which are now the live ids again.
DELETE FROM p2pwiki.watchlist WHERE wl_user NOT IN (1, 2947);
INSERT IGNORE INTO p2pwiki.watchlist (wl_user, wl_namespace, wl_title, wl_notificationtimestamp)
  SELECT wl_user, wl_namespace, wl_title, wl_notificationtimestamp FROM p2pwiki_orig.watchlist;
