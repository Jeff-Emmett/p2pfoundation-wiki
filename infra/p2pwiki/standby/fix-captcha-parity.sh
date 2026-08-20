#!/usr/bin/env bash
# Stop asking trusted editors to solve a captcha to press "Show Preview".
#
# THE REPORT. Danyl Strype, 2026-08-20: "a constant barrage of Captcha questions
# while editing... not only when I'm actually publishing a change, but when I'm
# even trying to get it to Show Preview, which makes no change to the published
# wiki."
#
# THE CAUSE, and it is a rebuild regression rather than a policy anyone chose.
# Netcup's LocalSettings.php granted skipcaptcha to THREE groups:
#
#     $wgGroupPermissions['user']['skipcaptcha']  = true;
#     $wgGroupPermissions['sysop']['skipcaptcha'] = true;
#     $wgGroupPermissions['bot']['skipcaptcha']   = true;
#
# The standby rebuilt on 2026-08-17 has only sysop and bot. Confirmed against
# the live site rather than assumed — siteinfo/usergroups shows skipcaptcha on
# sysop and bot, absent on user. So every logged-in editor who is not an admin
# now meets QuestyCaptcha, and meets it again on every preview, because
# ConfirmEdit re-presents its challenge each time the edit form is submitted and
# a preview is a submission.
#
# It bit this wiki especially hard: $wgCaptchaTriggers['addurl'] is true, and
# almost every edit to the P2P Foundation wiki adds an external link.
#
# WHY GIVING IT BACK TO `user` IS NOT A LOWERING OF THE BAR. On this wiki
# $wgGroupPermissions['*']['createaccount'] is false and anonymous editing is
# off, so an account only exists because a human approved it through the
# editor-request flow. The spam gate is account creation — which keeps its
# captcha — not the thousandth edit by someone already vetted. A captcha there
# taxes the people you trust and stops nobody else.
#
# This also satisfies the request more completely than asked: the ask was that
# captchas never fire before Save Changes; the result is that a logged-in editor
# never sees one at all.
#
# LocalSettings.php is a single-file bind mount and read-only in the container,
# so this edits the host copy and restarts. Appending is deliberate: the file is
# sequential PHP, later assignments win, so a block at the end takes effect
# whatever came before it. Idempotent.
set -euo pipefail

DEST="${DEST:-$HOME/p2pwiki-standby}"
LS="$DEST/LocalSettings.php"
CONTAINER="${CONTAINER:-p2pwiki-standby}"
MARK="p2pwiki: captcha parity with netcup"

[ -w "$LS" ] || { echo "cannot write $LS" >&2; exit 2; }

if grep -qF "$MARK" "$LS"; then
  echo "== already applied — leaving $LS alone =="
else
  cp -a "$LS" "$LS.bak-$(date -u +%Y%m%dT%H%M%SZ)"

  # A stray closing tag would swallow anything appended after it. MediaWiki's
  # own LocalSettings ships without one; check rather than trust.
  if grep -qE '^\s*\?>' "$LS"; then
    echo "refusing to append: $LS ends with a PHP closing tag" >&2; exit 3
  fi

  cat >> "$LS" <<'PHP'

# --- p2pwiki: captcha parity with netcup (2026-08-20) -------------------------
# Restores the three skipcaptcha grants the 2026-08-17 rebuild dropped. Without
# the first line, every non-admin editor solves a QuestyCaptcha to save AND to
# preview, because ConfirmEdit re-challenges on each submission of the edit form
# and 'addurl' fires on any edit adding an external link — which is most edits
# here. Account creation keeps its captcha; that is where the spam gate belongs,
# since accounts on this wiki are created only by approval.
$wgGroupPermissions['user']['skipcaptcha']  = true;
$wgGroupPermissions['sysop']['skipcaptcha'] = true;
$wgGroupPermissions['bot']['skipcaptcha']   = true;

$wgCaptchaTriggers['createaccount'] = true;
$wgCaptchaTriggers['addurl']        = true;
$wgCaptchaTriggers['edit']          = false;
$wgCaptchaTriggers['create']        = false;
# -----------------------------------------------------------------------------
PHP
  echo "== appended the parity block to $LS =="
fi

echo "== syntax-check before restarting anything =="
# A LocalSettings.php that does not parse takes the whole wiki down, so check it
# inside the container's own PHP before that file is what the wiki loads.
docker exec "$CONTAINER" php -l /var/www/html/LocalSettings.php >/dev/null 2>&1 \
  || echo "   (container still has the pre-edit copy; checking the host file instead)"
docker run --rm -v "$LS":/tmp/ls.php:ro php:8.2-cli php -l /tmp/ls.php

echo "== restart so the new file is read =="
# Required, not cosmetic: LocalSettings.php is a SINGLE-FILE bind mount. Any
# editor that writes-and-renames gives the host a new inode while the container
# keeps the old one, so the change is invisible until the container restarts.
docker restart "$CONTAINER" >/dev/null
sleep 10

echo "== verify through the public API, which is what an editor actually hits =="
for i in 1 2 3 4 5 6; do
  out=$(curl -sS -m 30 "https://wiki.p2pfoundation.net/api.php?action=query&meta=siteinfo&siprop=usergroups&format=json" \
        | python3 -c '
import sys,json
g=json.load(sys.stdin)["query"]["usergroups"]
have=[x["name"] for x in g if "skipcaptcha" in x.get("rights",[])]
print("user" in have, ",".join(sorted(have)))' 2>/dev/null) || out="false "
  set -- $out
  if [ "${1:-false}" = "True" ]; then
    echo "   skipcaptcha now granted to: ${2:-?}"
    echo
    echo "trusted editors will no longer be shown a captcha — on save or on preview"
    exit 0
  fi
  sleep 5
done

echo "FAILED: 'user' still lacks skipcaptcha after the restart" >&2
exit 4
