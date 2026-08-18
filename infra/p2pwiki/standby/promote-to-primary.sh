#!/usr/bin/env bash
# Switch the GX10 wiki from "standby" posture to "this is the wiki".
#
# Netcup is DEACTIVATED, not merely unreachable. The distinction matters: a host
# that is down comes back on its own schedule, whereas a deactivated account
# comes back when an administrative process finishes, and until then this box is
# not a fallback — it is the P2P Foundation Wiki.
#
# The standby restrictions were all justified by "edits here must merge back
# cleanly". That reasoning is now inverted: the content that has to merge is
# Netcup's 2026-07-31..08-17 window merging INTO here, not the other way round.
# Restrictions that cripple a live wiki to protect a merge that runs the other
# direction are just damage.
#
#   before (standby)                    after (primary)
#   uploads denied to everyone          logged-in users may upload
#   moves denied to users               logged-in users may move
#   deletes denied to users             sysops may delete
#   "edits will be merged back"         accurate notice about missing content
set -euo pipefail
cd "$(dirname "$0")"

if grep -q "GX10 PRIMARY MODE" LocalSettings.php; then
  echo "already in primary mode"
else
  cat >> LocalSettings.php <<'PHP'

# ---------------------------------------------------------------------------
# GX10 PRIMARY MODE - 2026-08-18
# Netcup deactivated; this is the live wiki, not a standby. Overrides the
# STANDBY WRITABLE WINDOW block above (last assignment wins).
# ---------------------------------------------------------------------------

# Uploads are part of being a wiki. They do not travel in an XML export, so the
# backup takes the images volume as a separate tar — see backup-standby.sh, and
# restore both or you restore redlinks.
$wgGroupPermissions['user']['upload']   = true;
$wgGroupPermissions['user']['reupload'] = true;
$wgGroupPermissions['user']['move']     = true;

# Deletion stays with sysops. Not a merge concern any more — just the ordinary
# reason wikis do it that way.
$wgGroupPermissions['user']['delete']   = false;

# Anonymous editing and self-service account creation remain OFF, matching
# production. This wiki has an editor-approval culture and an open wiki collects
# spam within hours; being the only surviving copy is a reason for more care
# about what gets written, not less.
$wgGroupPermissions['*']['edit']          = false;
$wgGroupPermissions['*']['createaccount'] = false;

$wgSiteNotice = '<div style="border:1px solid #e0c48a;background:#fdf3e3;'
  . 'color:#5c3d13;padding:.6rem .9rem;margin-bottom:1rem;font-size:.95em">'
  . '<strong>The wiki is running on backup infrastructure.</strong> '
  . 'Reading and editing work normally and your edits are saved and backed up. '
  . 'Edits made on the main server between 31 July and 17 August are '
  . 'temporarily missing and will be restored. '
  . 'Need an account? Contact an administrator.</div>';
PHP
  echo "primary-mode block appended"
fi

echo "== restart =="
docker compose up -d p2pwiki-standby >/dev/null 2>&1
sleep 8

echo "== promoting the two active editors to sysop =="
# They need to be able to create accounts for other editors and clean up spam.
# No password argument: --force on an existing user only adjusts groups.
for u in Mbauwens JeffEmmett; do
  docker exec p2pwiki-standby php /var/www/html/maintenance/createAndPromote.php \
    --force --sysop "$u" >/dev/null 2>&1 && echo "   $u -> sysop" || echo "   $u FAILED"
done

echo
echo "== verify =="
curl -sS -o /dev/null -m 20 -w "   local HTTP %{http_code}\n" -H "Host: wiki.p2pfoundation.net" http://127.0.0.1:18081/Main_Page
