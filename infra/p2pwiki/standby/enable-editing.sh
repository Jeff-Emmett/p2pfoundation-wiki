#!/usr/bin/env bash
# Make the standby writable, and mark the boundary that makes edits mergeable.
#
# READ THIS BEFORE RUNNING IT.
#
# There is no shared datastore. Netcup is unreachable, so its MySQL and this one
# cannot be "the same store" in any sense — anyone who tells you otherwise is
# describing a wish. What this sets up instead is a ONE-DIRECTIONAL merge that
# is safe because only one side is alive:
#
#     standby edits  --(export)-->  conflict check  --(import)-->  Netcup
#
# Netcup is off, so it gains no edits while this window is open. That makes the
# merge tractable. It does NOT make it free, because of the gap below.
#
# THE GAP, which is the whole risk and cannot be engineered away:
#
#   this standby's content = Netcup as of 2026-08-04 (the XML dump we had)
#   Netcup's DB will return = its state at 2026-08-17 06:21Z
#   => ~13 days of Netcup edits that this copy has never seen
#
# Measured from the dump, this wiki averages ~9.4 edits per active day, so the
# gap holds roughly 120 edits over perhaps 60-100 distinct pages, out of 40,647
# articles — about 0.25%.
#
# If someone edits a page here that ALSO changed on Netcup during those 13 days,
# importing our version makes our older-derived text current and the Netcup
# change stops being the live text. It stays in the page history and is
# recoverable, but the visible article regresses. export-standby-edits.sh and
# merge-to-netcup.sh detect exactly these pages and refuse to merge them
# automatically. That refusal is the point.
#
# Uploads stay OFF: files do not travel in an XML export, so an uploaded image
# would merge as a redlink and the file itself would be stranded here.
#
# Deletions and page moves also do not propagate through an XML export. They are
# restricted to sysop and must be replayed by hand — see the README.
set -euo pipefail
cd "$(dirname "$0")"

BOUNDARY_FILE=standby-writable-since.txt

echo "== 1. boundary timestamp =="
if [ -f "$BOUNDARY_FILE" ]; then
  echo "   already set: $(cat "$BOUNDARY_FILE")"
else
  # MediaWiki timestamp format, UTC. Everything at or after this instant is a
  # standby-origin edit and is in scope for the merge. Recorded BEFORE editing
  # is enabled, so the window can never start earlier than the marker.
  date -u +%Y%m%d%H%M%S > "$BOUNDARY_FILE"
  echo "   set: $(cat "$BOUNDARY_FILE")"
fi
BOUNDARY=$(cat "$BOUNDARY_FILE")

echo
echo "== 2. LocalSettings: writable, but only for named accounts =="
if grep -q "STANDBY WRITABLE WINDOW" LocalSettings.php; then
  echo "   already applied"
else
  cat >> LocalSettings.php <<PHP

# ---------------------------------------------------------------------------
# STANDBY WRITABLE WINDOW - opened $BOUNDARY
#
# Overrides the read-only block far above. LocalSettings.php is read top to
# bottom, so these later assignments win; the earlier block is deliberately left
# in place so that removing this one section restores read-only exactly.
# ---------------------------------------------------------------------------

\$wgReadOnly = false;

# Anonymous editing stays OFF. Two reasons, both load-bearing:
#   1. Production denies it (three layers of anon-deny plus an editor-request
#      approval flow), so allowing it here would not be parity.
#   2. Anything written here is destined to be REPLAYED INTO PRODUCTION. An
#      open public wiki collects spam within hours, and that spam would arrive
#      in Netcup wearing the authority of a merge.
\$wgGroupPermissions['*']['edit']          = false;
\$wgGroupPermissions['*']['createaccount'] = false;
\$wgGroupPermissions['user']['edit']       = true;

# Uploads stay off: files do not travel in an XML export.
\$wgEnableUploads = false;

# Deletions and moves do not propagate through an XML export either. Held to
# sysop so they are rare and deliberate, and have to be replayed by hand.
\$wgGroupPermissions['user']['move']   = false;
\$wgGroupPermissions['user']['delete'] = false;

# Tell every editor what they are editing. Without this someone will make a
# large change believing it is safely on the main server.
\$wgSiteNotice = '<div style="border:1px solid #e0c48a;background:#fdf3e3;'
  . 'color:#5c3d13;padding:.6rem .9rem;margin-bottom:1rem;font-size:.95em">'
  . '<strong>You are editing the standby copy.</strong> The main server is '
  . 'temporarily unavailable. Edits made here ARE saved and will be merged back '
  . 'when it returns &mdash; but this copy is based on a 2026-08-04 snapshot, so '
  . 'if a page also changed on the main server after that date your version will '
  . 'need manual review. Uploads, moves and deletions are disabled.</div>';
PHP
  echo "   appended"
fi

echo
echo "== 3. restart =="
docker compose up -d p2pwiki-standby >/dev/null 2>&1
sleep 8

echo
echo "== 4. accounts =="
# Usernames MUST match Netcup's exactly. Revision attribution in an XML import
# is matched on the username string, so "mbauwens" would import as a different
# person from "Mbauwens" and quietly split one editor's history in two.
if [ "$#" -eq 0 ]; then
  echo "   no usernames given; create them with:"
  echo "     ./enable-editing.sh Mbauwens JeffEmmett ..."
else
  touch standby-accounts.txt && chmod 600 standby-accounts.txt
  for u in "$@"; do
    # Always --force with a generated password: deterministic whether or not the
    # account already exists, and these accounts are new here regardless. The
    # password is generated ON THIS HOST and written to a 0600 file — it is
    # never echoed, so it cannot end up in a transcript or in shell history.
    PW=$(openssl rand -base64 18)
    if docker exec p2pwiki-standby php /var/www/html/maintenance/createAndPromote.php \
         --force "$u" "$PW" >/dev/null 2>&1; then
      printf '%s\t%s\n' "$u" "$PW" >> standby-accounts.txt
      echo "   $u: ready (password appended to standby-accounts.txt)"
    else
      echo "   $u: FAILED to create" >&2
    fi
    unset PW
  done
  echo
  echo "   Hand those passwords over out of band, and have each person change"
  echo "   them at Special:ChangePassword. They are NOT the Netcup passwords —"
  echo "   the user table never left that host."
fi

echo
echo "== done =="
echo "boundary: $BOUNDARY"
echo "export edits with: ./export-standby-edits.sh"
