#!/usr/bin/env bash
# Close the standby's writable window and put it back to read-only.
#
# The inverse of enable-editing.sh — but NOT by deleting what that script
# appended. LocalSettings.php here has grown four overlapping blocks (the
# original read-only block, STANDBY WRITABLE WINDOW, NETCUP PARITY - FILES,
# GX10 PRIMARY MODE) and the file is read top to bottom with the last
# assignment winning. Cutting one block out of the middle means reasoning about
# what the three others then leave in force. Appending a final block that
# re-asserts the posture is both easier to verify and easier to reverse: delete
# this one section and the previous state returns exactly.
#
# WHY THIS IS THE RIGHT POSTURE NOW
# The wiki serves from Netcup again. This copy is ~100,000 revisions behind and
# exists only as rung 2 of the edge Worker, which falls back per-request on
# origin failure. A transient Netcup 5xx can therefore hand a reader a page from
# here. If that page were editable, an edit would land on the copy nobody merges
# from, and the divergence would be silent — exactly the failure mode the
# 2026-08-24 two-way merge existed to clean up.
#
# CLI stays writable. Maintenance scripts must keep working or the standby can
# never be resynced from Netcup; $wgReadOnly blocks importDump.php too.
set -euo pipefail
cd "$(dirname "$0")"

STANDBY_SSH="${STANDBY_SSH:-gx10}"
STANDBY_DIR="${STANDBY_DIR:-p2pwiki-standby}"
MARKER="# STANDBY READ-ONLY RESTORED"

if grep -q "$MARKER" LocalSettings.php 2>/dev/null; then
  echo "local template already carries the block"
fi

cat > /tmp/standby-readonly.php <<'PHP'

# ---------------------------------------------------------------------------
# STANDBY READ-ONLY RESTORED - 2026-08-24
#
# The wiki failed back to Netcup (see ../FAILBACK.md). This copy is a read-only
# last resort again, not the live wiki. Appended last on purpose: LocalSettings
# is read top to bottom, so this overrides STANDBY WRITABLE WINDOW and GX10
# PRIMARY MODE above without editing either of them. Delete this block to undo.
# ---------------------------------------------------------------------------

$wgReadOnly = 'This is a read-only backup copy of the P2P Foundation Wiki, '
            . 'served only while the main server is unreachable. Editing is '
            . 'disabled here so that no change is lost — please wait until '
            . 'wiki.p2pfoundation.net is back and edit there.';

# Belt and braces, because $wgReadOnly is one line and one line can be lost.
$wgGroupPermissions['*']['edit']          = false;
$wgGroupPermissions['*']['createaccount'] = false;
$wgGroupPermissions['user']['edit']       = false;
$wgGroupPermissions['user']['upload']     = false;
$wgGroupPermissions['user']['reupload']   = false;
$wgGroupPermissions['user']['move']       = false;
$wgGroupPermissions['user']['delete']     = false;

# The feature stays available to CLI (importImages.php on a resync); the right
# is denied above, so nothing reaches it through the web.
$wgEnableUploads = true;

# Never let the backup copy be indexed alongside the real wiki.
$wgDefaultRobotPolicy = 'noindex,nofollow';

# Say what this is. A reader only ever lands here because the edge Worker fell
# back mid-outage, and a page that looks normal but silently refuses edits is
# worse than one that explains itself.
$wgSiteNotice = '<div style="border:1px solid #e0c48a;background:#fdf3e3;'
  . 'color:#5c3d13;padding:.6rem .9rem;margin-bottom:1rem;font-size:.95em">'
  . '<strong>You are reading a backup copy.</strong> The main wiki is '
  . 'temporarily unreachable, so this read-only snapshot is being served '
  . 'instead. It may be missing recent changes, and editing is disabled. '
  . 'Please try again shortly.</div>';

# MUST BE LAST. $wgReadOnly blocks maintenance scripts as well as the web, so
# importDump.php would refuse to run and the standby could never be resynced.
# The web SAPI stays read-only, which is the surface that matters.
if ( PHP_SAPI === 'cli' ) {
    $wgReadOnly = false;
}
PHP

echo "== 1. append the read-only block =="
scp -q /tmp/standby-readonly.php "$STANDBY_SSH:/tmp/standby-readonly.php"
ssh "$STANDBY_SSH" "cd $STANDBY_DIR && \
  if grep -q '$MARKER' LocalSettings.php; then echo '   already applied'; else \
    cp -a LocalSettings.php LocalSettings.php.bak-readonly-\$(date -u +%Y%m%dT%H%M%SZ) && \
    cat /tmp/standby-readonly.php >> LocalSettings.php && echo '   appended'; fi"

echo
echo "== 2. close the merge window =="
ssh "$STANDBY_SSH" "cd $STANDBY_DIR && date -u +%Y%m%d%H%M%S > standby-readonly-since.txt && \
  echo \"   window was [\$(cat standby-writable-since.txt) .. \$(cat standby-readonly-since.txt)]\""

echo
echo "== 3. syntax check and restart =="
ssh "$STANDBY_SSH" "cd $STANDBY_DIR && docker exec p2pwiki-standby php -l /var/www/html/LocalSettings.php && \
  docker restart p2pwiki-standby >/dev/null && sleep 12 && \
  docker ps --format '{{.Names}}|{{.Status}}' | grep '^p2pwiki-standby|'"

echo
echo "== 4. verify =="
# Testing for the $wgReadOnly message text returns 0 hits and looks like a
# failure: the group-permission check fires FIRST, so MediaWiki renders a
# permissions error instead. Test for the read-only EDIT FORM shape.
ssh "$STANDBY_SSH" "curl -sS --max-time 30 'http://127.0.0.1:18081/index.php?title=Peer_to_Peer&action=edit' -o /tmp/ro.html -w '   edit form: %{http_code}\n'; \
  grep -q '<title>View source' /tmp/ro.html && echo '   OK  title is View source' || echo '   FAIL title is not View source'; \
  grep -q 'readonly' /tmp/ro.html && echo '   OK  textarea is readonly' || echo '   FAIL textarea is writable'; \
  grep -q 'wpSave' /tmp/ro.html && echo '   FAIL save button present' || echo '   OK  no save button'; \
  curl -sS --max-time 30 'http://127.0.0.1:18081/Peer_to_Peer' -o /dev/null -w '   article read: %{http_code}\n'"

echo
echo "== 5. CLI must still be able to write =="
ssh "$STANDBY_SSH" "docker exec p2pwiki-standby php /var/www/html/maintenance/showSiteStats.php >/dev/null 2>&1 && \
  echo '   maintenance scripts run' || echo '   FAIL maintenance scripts blocked'"

echo
echo "== done =="
echo "To reopen the window: delete the '$MARKER' block from LocalSettings.php"
echo "on the standby and restart the container."
