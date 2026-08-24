#!/usr/bin/env bash
# Build the aiwiki demo into a standalone static site.
#
# The page is one self-contained HTML file: the corpus JSON is inlined at build
# time so there is no fetch at page load, nothing to rate-limit, and nothing that
# can be down when someone opens the link.
#
#   ./build.sh            build into dist/
#   ./build.sh --deploy   build, then publish to Cloudflare Pages
set -euo pipefail
cd "$(dirname "$0")"

DATA="data/aiwiki-data.json"
OUT="dist"
PROJECT="aiwiki-jeffemmett"

[ -f "$DATA" ] || { echo "✗ missing $DATA — run: python3 scripts/build-corpus.py" >&2; exit 1; }

BUILD_ID="$(date +%s | xargs printf '%x')"
rm -rf "$OUT"; mkdir -p "$OUT"

# Inline the JSON. python rather than sed: the data contains every character sed
# would treat as a delimiter or a backreference.
python3 - "$DATA" "$BUILD_ID" <<'PY'
import json, sys
data_path, build_id = sys.argv[1], sys.argv[2]
tpl = open("index.template.html").read()
raw = open(data_path).read()
json.loads(raw)                                  # fail loudly on malformed data
# </script> inside the payload would close the host tag early.
raw = raw.replace("</", "<\\/")
html = tpl.replace("__DATA__", raw).replace("<head>", f"<head>\n<!-- aiwiki build {build_id} -->", 1)
open("dist/index.html", "w").write(html)
print(f"  dist/index.html  {len(html)//1024} KB")
PY

printf 'User-agent: *\nAllow: /\n' > "$OUT/robots.txt"
echo "✓ built ($BUILD_ID)"

if [ "${1:-}" = "--deploy" ]; then
  : "${CLOUDFLARE_API_TOKEN:?set CLOUDFLARE_API_TOKEN}"
  : "${CLOUDFLARE_ACCOUNT_ID:?set CLOUDFLARE_ACCOUNT_ID}"
  npx --yes wrangler@4 pages deploy "$OUT" \
      --project-name "$PROJECT" --branch main --commit-dirty=true
fi
