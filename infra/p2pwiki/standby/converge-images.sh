#!/usr/bin/env bash
# Run image recovery passes until it stops finding anything new, then import.
#
# One pass never gets everything: the Archive throttles and roughly a third of
# requests fail with 429/503 even after the in-script backoff. Those failures are
# not "the file is missing", they are "come back later" — and the recovery script
# skips whatever is already on disk, so simply running it again IS the retry.
#
# Converged = a pass adds nothing. That is a better stopping rule than a fixed
# number of attempts, which either stops early or wastes passes.
set -euo pipefail
cd "$(dirname "$0")"

MAX_PASSES="${MAX_PASSES:-8}"
WORKERS="${WORKERS:-3}"     # gentler than the first run's 4; throttling was the
                            # bottleneck, so fewer workers is often FASTER overall
DIR=dumps/recovered-images

count() { ls "$DIR" 2>/dev/null | wc -l; }

echo "=== converging image recovery ==="
prev=$(count)
echo "starting from $prev files"

for pass in $(seq 1 "$MAX_PASSES"); do
  echo
  echo "--- pass $pass (have $prev) ---"
  ./recover-images-from-wayback.py --download --workers "$WORKERS" 2>&1 | tail -3 || true
  now=$(count)
  gained=$(( now - prev ))
  echo "pass $pass: +$gained  (total $now)"
  if [ "$gained" -le 0 ]; then
    echo "converged — a full pass added nothing"
    prev=$now
    break
  fi
  prev=$now
  # A short breather between passes. If we just got throttled for a whole pass,
  # hammering straight back is how you stay throttled.
  sleep 60
done

echo
echo "=== importing $(count) files ==="
# Re-runnable: importImages skips names the wiki already has, so this is safe
# after every pass and does not duplicate.
docker exec p2pwiki-standby php /var/www/html/maintenance/importImages.php \
  --comment="Recovered from the Internet Archive" /dumps/recovered-images 2>&1 | tail -4

echo
docker exec p2pwiki-standby php /var/www/html/maintenance/showSiteStats.php 2>&1 | grep -i image
echo
echo "never archived (unrecoverable): $(wc -l < images-not-archived.txt 2>/dev/null || echo '?')"
