#!/usr/bin/env bash
# The wiki's own notice — the single source of truth for "what is going on".
#
# Deliberately a MediaWiki page (MediaWiki:Sitenotice) rather than $wgSiteNotice
# in LocalSettings or a banner in the edge Worker: a sysop can correct it in
# thirty seconds without a deploy and without me. Messaging that only an
# infrastructure change can update goes stale exactly when the situation moves,
# which is what happened when the Worker kept telling readers that "editing and
# login are disabled" for hours after editing was enabled.
#
# Usage:
#   ./update-sitenotice.sh                 # clear the notice (MediaWiki reads "-" as none)
#   ./update-sitenotice.sh notice.html     # publish the contents of a file
#   ./update-sitenotice.sh - < notice.html # publish from stdin
#
# The text is NOT baked into this script any more. It was, and the incident it
# described (the 31 Jul–17 Aug gap, transferred passwords) was fixed on
# 2026-08-20 while the script still carried the old wording — a re-run would
# have put a false warning back in front of every reader. Pass the wording in.
set -euo pipefail
cd "$(dirname "$0")"

src="${1:-}"
if [[ -z "$src" ]]; then
  printf -- "-\n" > /tmp/sitenotice.txt
  summary="clear status notice"
elif [[ "$src" == "-" ]]; then
  cat > /tmp/sitenotice.txt
  summary="status notice"
else
  cp "$src" /tmp/sitenotice.txt
  summary="status notice"
fi

docker cp /tmp/sitenotice.txt p2pwiki-standby:/tmp/sitenotice.txt >/dev/null
docker exec p2pwiki-standby bash -c \
  "php /var/www/html/maintenance/edit.php --user=Admin --summary='$summary' MediaWiki:Sitenotice < /tmp/sitenotice.txt"

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
