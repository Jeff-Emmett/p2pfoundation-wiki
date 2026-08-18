#!/usr/bin/env bash
# The wiki's own notice — the single source of truth for "what is going on".
#
# Deliberately a MediaWiki page (MediaWiki:Sitenotice) rather than $wgSiteNotice
# in LocalSettings or a banner in the edge Worker: a sysop can correct it in
# thirty seconds without a deploy and without me. Messaging that only an
# infrastructure change can update goes stale exactly when the situation moves,
# which is what happened when the Worker kept telling readers that "editing and
# login are disabled" for hours after editing was enabled.
set -euo pipefail
cd "$(dirname "$0")"

cat > /tmp/sitenotice.txt <<'MSG'
<div style="border:1px solid #e0c48a;background:#fdf3e3;color:#5c3d13;padding:.7rem 1rem;margin-bottom:1rem;line-height:1.5">
<strong>The wiki is running on backup infrastructure.</strong>
Reading and editing both work normally, and everything you save here is kept and backed up daily.

Two things to know: edits made on the main server between <strong>31 July and 17 August</strong> are temporarily missing and will be restored; and <strong>your existing password will not work</strong> — account passwords live on the main server and could not be transferred. Contact Michel or Jeff for access. Password reset by email is unavailable for now.
</div>
MSG

docker cp /tmp/sitenotice.txt p2pwiki-standby:/tmp/sitenotice.txt >/dev/null
docker exec p2pwiki-standby bash -c \
  "php /var/www/html/maintenance/edit.php --user=Admin --summary='status notice' MediaWiki:Sitenotice < /tmp/sitenotice.txt"

# $wgSiteNotice in LocalSettings OVERRIDES the MediaWiki:Sitenotice page, so the
# page would be ignored while that variable is set. Neutralise it and let the
# wiki page win.
if ! grep -q "SITENOTICE HANDED TO THE WIKI" LocalSettings.php; then
  cat >> LocalSettings.php <<'PHP'

# --- SITENOTICE HANDED TO THE WIKI -----------------------------------------
# Unset so MediaWiki:Sitenotice (an ordinary wiki page a sysop can edit) takes
# effect. $wgSiteNotice here would override it and could then only be changed by
# someone with shell access.
$wgSiteNotice = '';
PHP
  docker compose up -d p2pwiki-standby >/dev/null 2>&1
fi
echo "done"
