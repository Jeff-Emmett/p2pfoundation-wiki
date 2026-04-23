#!/usr/bin/env bash
# Wiki dump generator for p2pwiki — runs inside the compose project dir on Netcup.
#
# Cadence (root crontab):
#   0 4 * * 0  /opt/websites/p2pwiki/dump-wiki.sh >>/var/log/p2pwiki-dump.log 2>&1
#
# Weekly run always produces a current-revisions XML (~50 MB).
# On the first Sunday of each month it additionally produces:
#   • full-history XML (all revisions, all namespaces)
#   • tar of the p2pwiki-images Docker volume (~1.7 GB)
#
# Manual use:
#   ./dump-wiki.sh                # weekly cadence logic
#   ./dump-wiki.sh --current      # only the current-revisions XML
#   ./dump-wiki.sh --history      # only the full-history XML
#   ./dump-wiki.sh --images       # only the images tar
#   ./dump-wiki.sh --all          # everything, regardless of date

set -euo pipefail

DEPLOY_DIR="/opt/websites/p2pwiki"
DUMPS_DIR="${DEPLOY_DIR}/dumps"
CONTAINER="p2pwiki"
IMAGES_VOLUME="p2pwiki_p2pwiki-images"
DATE="$(date +%F)"
RETAIN_CURRENT=4
RETAIN_HISTORY=3
RETAIN_IMAGES=2

mkdir -p "${DUMPS_DIR}"

mode="${1:-auto}"
do_current=0; do_history=0; do_images=0
case "${mode}" in
  --current) do_current=1 ;;
  --history) do_history=1 ;;
  --images)  do_images=1 ;;
  --all)     do_current=1; do_history=1; do_images=1 ;;
  auto)
    do_current=1
    # First Sunday of month: dom 1–7 AND dow=7 (which cron already guarantees on Sunday)
    if [ "$(date +%d)" -le "07" ]; then
      do_history=1
      do_images=1
    fi
    ;;
  *) echo "unknown arg: ${mode}" >&2; exit 2 ;;
esac

log() { echo "[$(date -Iseconds)] $*"; }

dump_current() {
  local out="${DUMPS_DIR}/p2pwiki-${DATE}-current.xml.bz2"
  log "starting current-revisions dump"
  docker exec "${CONTAINER}" php /var/www/html/maintenance/dumpBackup.php --current --quiet \
    | bzip2 > "${out}"
  docker exec "${CONTAINER}" php /var/www/html/maintenance/dumpUploads.php \
    > "${DUMPS_DIR}/p2pwiki-${DATE}-uploads.txt"
  ln -sfn "$(basename "${out}")" "${DUMPS_DIR}/p2pwiki-latest-current.xml.bz2"
  ln -sfn "p2pwiki-${DATE}-uploads.txt" "${DUMPS_DIR}/p2pwiki-latest-uploads.txt"
  ls -1t "${DUMPS_DIR}"/p2pwiki-*-current.xml.bz2 2>/dev/null | tail -n +$((RETAIN_CURRENT + 1)) | xargs -r rm -f
  ls -1t "${DUMPS_DIR}"/p2pwiki-*-uploads.txt     2>/dev/null | tail -n +$((RETAIN_CURRENT + 1)) | xargs -r rm -f
  log "current dump: $(du -h "${out}" | cut -f1)"
}

dump_history() {
  local out="${DUMPS_DIR}/p2pwiki-${DATE}-history.xml.bz2"
  log "starting full-history dump"
  docker exec "${CONTAINER}" php /var/www/html/maintenance/dumpBackup.php --full --quiet \
    | bzip2 > "${out}"
  ln -sfn "$(basename "${out}")" "${DUMPS_DIR}/p2pwiki-latest-history.xml.bz2"
  ls -1t "${DUMPS_DIR}"/p2pwiki-*-history.xml.bz2 2>/dev/null | tail -n +$((RETAIN_HISTORY + 1)) | xargs -r rm -f
  log "history dump: $(du -h "${out}" | cut -f1)"
}

dump_images() {
  local out="${DUMPS_DIR}/p2pwiki-${DATE}-images.tar"
  log "starting images tar"
  # Read-only mount of the named volume into a throwaway alpine container.
  docker run --rm \
    -v "${IMAGES_VOLUME}":/src:ro \
    -v "${DUMPS_DIR}":/dst \
    alpine \
    tar cf "/dst/$(basename "${out}")" -C /src .
  ln -sfn "$(basename "${out}")" "${DUMPS_DIR}/p2pwiki-latest-images.tar"
  ls -1t "${DUMPS_DIR}"/p2pwiki-*-images.tar 2>/dev/null | tail -n +$((RETAIN_IMAGES + 1)) | xargs -r rm -f
  log "images tar: $(du -h "${out}" | cut -f1)"
}

write_index() {
  {
    echo '<!doctype html><meta charset=utf-8><title>P2P Foundation Wiki — Dumps</title>'
    echo '<style>body{font:14px/1.5 system-ui,sans-serif;max-width:760px;margin:2rem auto;padding:0 1rem}'
    echo 'table{border-collapse:collapse;width:100%}td,th{padding:.4rem .6rem;border-bottom:1px solid #ddd;text-align:left}'
    echo 'code{background:#f4f4f4;padding:1px 4px;border-radius:3px}h2{margin-top:2rem}</style>'
    echo '<h1>P2P Foundation Wiki — Dumps</h1>'
    echo '<p>MediaWiki XML exports and image archives from <a href="https://wiki.p2pfoundation.net">wiki.p2pfoundation.net</a>.'
    echo 'All content licensed <a href="https://creativecommons.org/licenses/by-sa/3.0/">CC&nbsp;BY-SA&nbsp;3.0</a>.</p>'
    echo '<h2>Latest</h2><ul>'
    echo '<li><a href="p2pwiki-latest-current.xml.bz2">p2pwiki-latest-current.xml.bz2</a> — current revisions, all namespaces (weekly)</li>'
    echo '<li><a href="p2pwiki-latest-history.xml.bz2">p2pwiki-latest-history.xml.bz2</a> — full revision history (monthly)</li>'
    echo '<li><a href="p2pwiki-latest-images.tar">p2pwiki-latest-images.tar</a> — all uploaded images, tar archive (monthly)</li>'
    echo '<li><a href="p2pwiki-latest-uploads.txt">p2pwiki-latest-uploads.txt</a> — image filename list</li>'
    echo '</ul>'
    echo '<h2>All files</h2><table><tr><th>File<th>Size<th>Modified</tr>'
    cd "${DUMPS_DIR}"
    for f in $(ls -1t p2pwiki-*.bz2 p2pwiki-*.tar p2pwiki-*.txt 2>/dev/null); do
      [ -L "$f" ] && continue
      size="$(du -h "$f" | cut -f1)"
      mtime="$(date -r "$f" -u +'%Y-%m-%d %H:%M UTC')"
      echo "<tr><td><a href=\"${f}\">${f}</a><td>${size}<td>${mtime}</tr>"
    done
    echo '</table>'
    echo '<h2>Importing</h2>'
    echo '<pre><code># Into a fresh MediaWiki container:'
    echo 'bzcat p2pwiki-latest-current.xml.bz2 | docker exec -i &lt;mw&gt; php maintenance/importDump.php --quiet'
    echo 'tar xf p2pwiki-latest-images.tar -C /path/to/mediawiki/images/</code></pre>'
  } > "${DUMPS_DIR}/index.html"
}

[ "${do_current}" = 1 ] && dump_current
[ "${do_history}" = 1 ] && dump_history
[ "${do_images}"  = 1 ] && dump_images
write_index

log "done"
