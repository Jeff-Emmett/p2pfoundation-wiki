#!/usr/bin/env bash
# Ship the built atlas to wiki.p2pfoundation.net/explore and purge the edge.
#
#   ./deploy.sh                 # upload only
#   ./deploy.sh --purge         # upload, then drop the edge copies
#
# The purge matters. Cloudflare is told to respect the origin's Cache-Control
# (nginx.conf sets max-age=3600), so without it a rebuild takes up to an hour to
# reach anyone. The filenames never change between builds — core.json is
# rewritten in place — so there is no cache-busting to fall back on.
#
# Cloudflare splits these two capabilities across two tokens, which is not
# guessable and costs a 401 to discover:
#   editing cache RULES   cloudflare/CLOUDFLARE_CACHE_TOKEN   (cannot purge)
#   purging the CACHE     CLOUDFLARE_JEFF_MAIN_API            (cannot edit rules)
#
#   secretctl run --ref T=isec://claude-ops/prod/CLOUDFLARE_JEFF_MAIN_API -- ./deploy.sh --purge
set -euo pipefail
cd "$(dirname "$0")"

SRC="../../demo/aiwiki/dist-atlas"
HOST="netcup-full"
DEST="/opt/websites/p2pwiki-atlas/site/explore"

[ -f "$SRC/index.html" ] || { echo "✗ no build at $SRC — run demo/aiwiki/build.sh first" >&2; exit 1; }

echo "→ uploading $(du -sh "$SRC" | cut -f1) to $DEST"
ssh "$HOST" "mkdir -p $DEST"
tar -C "$SRC" -czf - . | ssh "$HOST" "tar -C $DEST -xzf -"
ssh "$HOST" "docker exec p2pwiki-atlas nginx -s reload >/dev/null 2>&1 || true"
echo "✓ uploaded"

if [ "${1:-}" = "--purge" ]; then
  : "${T:?set T to a token with Cache Purge — see the header}"
  # Purge by URL, not by prefix: prefix purging is Enterprise-only and this zone
  # is on the Free plan, which allows 30 URLs per call. The tree used to be 29
  # files and fitted in one call with one to spare; the gist shards took it past
  # 50, and the previous `urls[:30]` silently dropped the rest — a purge that
  # reports success while leaving most of the payload stale at the edge.
  BASE="https://wiki.p2pfoundation.net/explore"
  BATCHES=$(mktemp)
  python3 - "$SRC" "$BASE" <<'PYPURGE' > "$BATCHES"
import json, os, sys
src, base = sys.argv[1], sys.argv[2]
urls = [base + "/"]
for root, _, names in os.walk(src):
    for n in names:
        rel = os.path.relpath(os.path.join(root, n), src).replace(os.sep, "/")
        urls.append(f"{base}/{rel}")
for i in range(0, len(urls), 30):
    print(json.dumps({"files": urls[i:i + 30]}))
PYPURGE
  echo "→ purging $(python3 -c 'import json,sys; print(sum(len(json.loads(l)["files"]) for l in open(sys.argv[1])))' "$BATCHES") URLs in $(wc -l < "$BATCHES") call(s)"
  while IFS= read -r BATCH; do
    curl -fsS -X POST \
      "https://api.cloudflare.com/client/v4/zones/ea1c3cf1f24e254c062d3bea33b7ba86/purge_cache" \
      -H "Authorization: Bearer $T" -H "Content-Type: application/json" \
      --data "$BATCH" \
      | python3 -c 'import json,sys; sys.exit(0 if json.load(sys.stdin)["success"] else 1)' \
      || { echo "✗ purge failed" >&2; rm -f "$BATCHES"; exit 1; }
  done < "$BATCHES"
  rm -f "$BATCHES"
  echo "✓ purged"
fi
