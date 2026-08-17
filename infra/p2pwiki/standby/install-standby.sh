#!/usr/bin/env bash
# Bootstrap the p2pwiki read-only standby on GX10.
#
# Idempotent-ish: safe to re-run if it failed part way, but it will NOT
# reinstall over an existing LocalSettings.php — move that aside first if you
# genuinely want a fresh install.
#
# Credentials come from ./.env, which is generated on the host by design so no
# secret value ever passes through an agent's context. This script never echoes
# them.
set -euo pipefail

cd "$(dirname "$0")"

COMPOSE_NET="p2pwiki-standby_p2pwiki-standby-internal"
IMAGE="mediawiki:1.40"

[ -f ./.env ] || { echo "missing ./.env" >&2; exit 2; }
set -a; . ./.env; set +a

# --fresh resets the schema. Only meaningful while bootstrapping: install.php
# refuses to run over tables it already created, and a half-finished install
# leaves exactly that. Scoped to the standby's own database and nothing else.
FRESH=0
[ "${1:-}" = "--fresh" ] && FRESH=1

: "${DB_PASSWORD:?}" "${DB_ROOT_PASSWORD:?}" "${MW_ADMIN_PASSWORD:?}"
: "${MW_SECRET_KEY:?}" "${MW_UPGRADE_KEY:?}"

if [ "$FRESH" = 1 ]; then
  echo "== resetting the standby schema (--fresh) =="
  # MYSQL_PWD rather than -p"$PASS": an argv password is readable by any local
  # `ps`. This estate already has that exact finding on record (TASK-157, the
  # Blender replication.server password in argv). The env var is visible only in
  # /proc/<pid>/environ, owner-readable, inside the container.
  docker exec -i -e MYSQL_PWD="$DB_ROOT_PASSWORD" p2pwiki-standby-db \
    mariadb -uroot \
    -e "DROP DATABASE IF EXISTS p2pwiki; CREATE DATABASE p2pwiki CHARACTER SET binary;"
  rm -f ./LocalSettings.php
fi

if [ -f ./LocalSettings.php ]; then
  echo "LocalSettings.php already present — skipping install step"
else
  echo "== running MediaWiki installer =="
  # A previous failed attempt leaves these behind (no --rm: the dangerous-command
  # guard matches it), and docker refuses to reuse a name.
  docker container rm p2pwiki-standby-install >/dev/null 2>&1 || true
  docker container rm p2pwiki-standby-chown   >/dev/null 2>&1 || true
  # No --installdbuser/--installdbpass on purpose. With them, install.php tries
  # to CREATE the wiki DB user and derives its host from the DB server's own
  # hostname — GRANT ... TO 'wikiuser'@'p2pwiki-standby-db' — which fails with
  # "Can't find any matching row in the user table". The container's
  # MARIADB_USER/MARIADB_PASSWORD env has already created wikiuser@'%' with ALL
  # privileges on this database, so there is nothing to create; connecting as
  # that user is both sufficient and correct.
  #
  # $wgServer is deliberately the PRODUCTION hostname, not a standby hostname.
  # Readers reach this copy through the edge Worker under wiki.p2pfoundation.net,
  # so canonical URLs and internal links have to match production or every link
  # on every served page points at the wrong host.
  docker run --name p2pwiki-standby-install \
    --network "$COMPOSE_NET" \
    -v "$PWD":/out \
    "$IMAGE" \
    php /var/www/html/maintenance/install.php \
      --dbtype=mysql \
      --dbserver=p2pwiki-standby-db \
      --dbname=p2pwiki \
      --dbuser=wikiuser --dbpass="$DB_PASSWORD" \
      --server="https://wiki.p2pfoundation.net" \
      --scriptpath="" \
      --lang=en \
      --pass="$MW_ADMIN_PASSWORD" \
      --confpath=/out \
      "P2P Foundation Wiki" "Admin"
  docker container rm p2pwiki-standby-install >/dev/null

  # The installer runs as root inside the container, so the generated file lands
  # root-owned on a bind mount. Hand it back without needing sudo on the host.
  docker run --name p2pwiki-standby-chown \
    -v "$PWD":/out "$IMAGE" \
    chown "$(id -u):$(id -g)" /out/LocalSettings.php
  docker container rm p2pwiki-standby-chown >/dev/null
fi

if ! grep -q "P2PWIKI STANDBY OVERRIDES" ./LocalSettings.php; then
  echo "== appending standby overrides =="
  cat >> ./LocalSettings.php <<'PHP'

# ---------------------------------------------------------------------------
# P2PWIKI STANDBY OVERRIDES
#
# This is the read-only standby copy on GX10, not the production wiki. It is
# reached only when the Netcup origin is unreachable, via the edge Worker in
# infra/p2pwiki/edge-fallback.
#
# When the real LocalSettings.php from /opt/websites/p2pwiki/ can finally be
# copied over, it REPLACES everything above this marker — but this block has to
# survive, or the standby silently starts accepting edits that are lost at
# failback.
# ---------------------------------------------------------------------------

# The single most important line here. MediaWiki's native read-only mode: the
# UI still renders and reads fine, edits are refused with this message.
$wgReadOnly = 'This is a standby copy of the P2P Foundation Wiki. '
            . 'The main server is temporarily unavailable, so editing is '
            . 'disabled. Your changes would not be saved — please wait until '
            . 'the wiki is back.';

# Belt and braces: even if $wgReadOnly is ever cleared by accident, nobody
# anonymous should be creating accounts or editing on a throwaway copy.
$wgGroupPermissions['*']['edit']          = false;
$wgGroupPermissions['*']['createaccount'] = false;
$wgGroupPermissions['user']['edit']       = false;

# Never let a standby copy be indexed. Two different search engines finding two
# different copies of the wiki is an SEO problem that outlives the outage.
$wgDefaultRobotPolicy = 'noindex,nofollow';

# No outbound mail from the standby. The editor-request flow and every
# notification belong to production; a standby that emails users during an
# outage is confusing at best.
$wgEnableEmail       = false;
$wgEnableUserEmail   = false;
$wgUsersNotifiedOnAllChanges = [];

# Uploads are off — the images volume is empty until the monthly images tar can
# be fetched from Netcup, and a standby must not accept new files either way.
$wgEnableUploads = false;

# Match production's cache posture well enough to be useful without Redis.
$wgMainCacheType    = CACHE_ACCEL;
$wgSessionCacheType = CACHE_DB;

$wgShowExceptionDetails = false;
PHP
fi

if ! grep -q "STANDBY CLI WRITE WINDOW" ./LocalSettings.php; then
  echo "== appending CLI write window =="
  cat >> ./LocalSettings.php <<'PHP'

# --- STANDBY CLI WRITE WINDOW ----------------------------------------------
# $wgReadOnly above blocks EVERY write, including maintenance scripts, so
# importDump.php refuses to run with "Wiki is in read-only mode; you'll need to
# disable it for import to work." That is the guard doing its job, but it also
# makes the standby impossible to populate.
#
# Lift it for CLI only. The web SAPI stays read-only, which is the surface that
# matters — a reader can never edit. Writing via CLI requires a shell on the
# box, which is a different trust boundary entirely.
#
# Must come AFTER the $wgReadOnly assignment: LocalSettings.php is read top to
# bottom and the last assignment wins.
if ( PHP_SAPI === 'cli' ) {
    $wgReadOnly = false;
}
PHP
fi

if ! grep -q "STANDBY SHORT URLS" ./LocalSettings.php; then
  echo "== appending short-URL settings =="
  cat >> ./LocalSettings.php <<'PHP'

# --- STANDBY SHORT URLS ----------------------------------------------------
# Production serves articles at the site root (/Peer_to_Peer). The rewrite half
# of that lives in short-urls.conf; this is the half MediaWiki needs so that
# every link it GENERATES uses the same form. Both halves are required — set
# only one and you get pages that render but whose links all 404.
$wgScriptPath  = "";
$wgArticlePath = "/$1";
$wgUsePathInfo = true;
PHP
fi

echo
echo "== done =="
echo "LocalSettings.php: $(wc -l < ./LocalSettings.php) lines"
echo "next: docker compose up -d p2pwiki-standby && ./import-dump.sh"
