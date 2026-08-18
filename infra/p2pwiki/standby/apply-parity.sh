#!/usr/bin/env bash
# Bring the GX10 standby into configuration parity with the live Netcup wiki.
#
# WHERE THE SPEC CAME FROM. Netcup's LocalSettings.php is not in the repo and the
# host is unreachable, so the configuration was recovered from the wiki's own
# archived Special:Version page:
#
#   https://web.archive.org/web/20260202224905/http://wiki.p2pfoundation.net/Special:Version
#
# That page is authoritative about what was actually loaded, which is better
# evidence than guessing from the corpus. It gives:
#
#   extensions : CategoryTree, YouTube 1.9.4, ConfirmEdit 1.6.0, QuestyCaptcha
#   skin       : Vector, rendering as skin-vector-legacy (Vector 2011)
#   parser tags: aoaudio aovideo categorytree gallery html indicator langconvert
#                nicovideo nowiki pre youtube
#   logo       : /images/0/09/Logo-final-box-128.png
#
# WHAT IS DELIBERATELY *NOT* INSTALLED, and this is the discipline that makes
# this parity rather than improvement: there is no Cite and no ParserFunctions
# on the live wiki. 187 pages use <ref> and 6 use {{#if}}, and all of them render
# as raw text on Netcup too. Adding those extensions would make the standby
# *better* than production and therefore WRONG — a reader would see something the
# real wiki never showed them, and a future comparison would report a spurious
# diff. Parity includes the imperfections.
#
# Idempotent: safe to re-run.
set -euo pipefail
cd "$(dirname "$0")"

YT_REPO=https://github.com/wikimedia/mediawiki-extensions-YouTube
# The EXACT commit Netcup runs, read off its archived Special:Version:
# "YouTube 1.9.4 (279c8ab) 09:47, 29 January 2026".
#
# Not the REL1_40 branch, which was the obvious choice and is wrong: it pins
# 1.9.3 (bb4f387, June 2024), whose parser tags still include the long-dead
# <gtrailer>, <tangler> and <wegame>. Those three showed up in our
# Special:Version and not in Netcup's — which is exactly how a version skew
# announces itself if you bother to diff. 1.9.4 lives on master, and Netcup
# runs it against MediaWiki 1.40, so it is proven on this version.
YT_COMMIT=279c8ab

echo "== 1. YouTube extension (not bundled in the mediawiki image) =="
NEED_CLONE=1
if [ -d extensions/YouTube/.git ]; then
  HAVE=$(git -C extensions/YouTube rev-parse --short HEAD 2>/dev/null || echo none)
  if [ "$HAVE" = "$YT_COMMIT" ]; then NEED_CLONE=0; echo "   already at $YT_COMMIT"; else echo "   at $HAVE, want $YT_COMMIT — refetching"; fi
fi
if [ "$NEED_CLONE" = 1 ]; then
  rm -rf extensions/YouTube
  mkdir -p extensions
  git clone -q "$YT_REPO" extensions/YouTube
  git -C extensions/YouTube checkout -q "$YT_COMMIT"
  echo "   checked out $(git -C extensions/YouTube rev-parse --short HEAD)"
fi
test -f extensions/YouTube/extension.json && echo "   extension.json OK"

echo
echo "== 2. LocalSettings parity block =="
if grep -q "NETCUP PARITY" LocalSettings.php; then
  echo "   already applied"
else
  cat >> LocalSettings.php <<'PHP'

# ---------------------------------------------------------------------------
# NETCUP PARITY — reconstructed from the archived Special:Version of
# 2026-02-02, because LocalSettings.php itself lives only on the Netcup host.
#
# Do NOT add extensions that are absent from that list (Cite, ParserFunctions,
# and so on). The live wiki renders <ref> and {{#if}} as raw text, and this copy
# must render them the same way or it is not a standby, it is a fork.
# ---------------------------------------------------------------------------

wfLoadExtension( 'CategoryTree' );

# Spam prevention. Inert here because the wiki is read-only and anonymous edit
# is denied, but loaded so Special:Version matches and so that lifting read-only
# for a maintenance window does not silently drop the CAPTCHA.
wfLoadExtension( 'ConfirmEdit' );
wfLoadExtension( 'ConfirmEdit/QuestyCaptcha' );

# Third-party. Provides <youtube>, <aoaudio>, <aovideo>, <nicovideo> — 9 pages
# in the corpus embed video and would otherwise show the raw tag.
wfLoadExtension( 'YouTube' );

# The live wiki has <html> among its parser extension tags, which is only true
# when raw HTML is enabled. 8 pages depend on it (comparison charts and the
# WikiSprint pages) and render as visible markup without it.
#
# This is a genuinely dangerous setting on a wiki that accepts edits — it lets an
# editor inject arbitrary HTML. It is acceptable HERE specifically because this
# copy is read-only: $wgReadOnly is set, anonymous and user edit are denied, and
# uploads are off, so no new content can arrive through the web at all. If this
# box is ever promoted to a writable primary, this line must be reconsidered
# rather than inherited.
$wgRawHtml = true;

# Vector, rendering as the 2011 legacy version — matching skin-vector-legacy on
# the body element of the archived page.
$wgDefaultSkin = 'vector';

$wgLogos = [ '1x' => '/images/0/09/Logo-final-box-128.png' ];
PHP
  echo "   appended"
fi

if ! grep -q "NETCUP PARITY - SKINS" LocalSettings.php; then
  echo "   appending skin parity"
  cat >> LocalSettings.php <<'PHP'

# --- NETCUP PARITY - SKINS -------------------------------------------------
# Netcup's Special:Version lists exactly one skin: Vector (which itself provides
# both the 2011 legacy and 2022 variants). The mediawiki image also ships
# MinervaNeue, MonoBook and Timeless, which would appear in Special:Version and
# be selectable in preferences — neither is true on the live wiki.
#
# Hidden rather than deleted: the files live inside the image, so removing them
# would mean shadowing the whole skins/ tree with a mount and taking on the job
# of keeping it in sync with the image. $wgSkipSkins gets the same observable
# result in one line.
$wgSkipSkins = [ 'minerva', 'monobook', 'timeless' ];
PHP
fi

if ! grep -q "NETCUP PARITY - NAMESPACES" LocalSettings.php; then
  echo "   appending namespace parity"
  cat >> LocalSettings.php <<'PHP'

# --- NETCUP PARITY - NAMESPACES --------------------------------------------
# Read from the <siteinfo> block of the XML dump, which records the live wiki's
# actual namespace configuration. Two of these are NOT MediaWiki defaults, and
# both were wrong here until it was checked:
#
#   ns 4/5    renamed to "P2P Foundation Wiki" (default would be "Project"),
#             so those 30 pages were reachable at the wrong URL.
#   ns 118/119 "Draft"/"Draft talk" are CUSTOM and did not exist here at all, so
#             the dump's Draft page imported into the main namespace as an
#             ordinary article literally titled "Draft:...". importDump does not
#             warn about this; it just puts the page somewhere else.
$wgMetaNamespace     = 'P2P_Foundation_Wiki';
$wgMetaNamespaceTalk = 'P2P_Foundation_Wiki_talk';
$wgExtraNamespaces[118] = 'Draft';
$wgExtraNamespaces[119] = 'Draft_talk';
PHP
fi

echo
echo "== 3. logo into the images volume =="
if [ -f logo-final-box-128.png ]; then
  docker exec p2pwiki-standby mkdir -p /var/www/html/images/0/09
  docker cp logo-final-box-128.png p2pwiki-standby:/var/www/html/images/0/09/Logo-final-box-128.png
  docker exec p2pwiki-standby chown -R 33:33 /var/www/html/images/0 2>/dev/null || true
  echo "   installed"
else
  echo "   SKIP: logo-final-box-128.png not staged here"
fi

echo
echo "== 4. restart so the extension bind mount is live =="
docker compose up -d p2pwiki-standby

echo
echo "== 5. schema updates for the new extensions =="
docker exec p2pwiki-standby php /var/www/html/maintenance/update.php --quick 2>&1 | tail -6

echo
echo "== 6. site stats (articles showed 0 before rebuildall completed) =="
docker exec p2pwiki-standby php /var/www/html/maintenance/initSiteStats.php --update 2>&1 | tail -4
docker exec p2pwiki-standby php /var/www/html/maintenance/showSiteStats.php 2>&1 | head -8
