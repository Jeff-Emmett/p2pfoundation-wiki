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
OUT="dist"              # -> Cloudflare Pages (the tour, and the /graph redirect)
ATLAS_OUT="dist-atlas"  # -> netcup, served at wiki.p2pfoundation.net/explore

# The two outputs are kept apart on purpose. The atlas is canonical on the wiki;
# publishing it to Pages as well meant 20 MB of duplicate riding along in every
# deploy, reachable only if the redirect were ever removed. One payload, one home.
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

# ---- the atlas -------------------------------------------------------
# A second page rather than a seventh chapter: a pan/zoom canvas inside a
# scrolling narrative fights the scroll and gets a letterboxed viewport. The
# graph payload is fetched rather than inlined, because the leads alone are
# 15 MB and a reader opens a handful of them.
GRAPH_DATA="graph/data/plane3.json"
if [ -f "$GRAPH_DATA" ]; then
  PY_BIN="${PY_BIN:-/home/jeffe/Github/p2pwiki-ai/.venv/bin/python}"
  "$PY_BIN" graph/scripts/pack-graph.py
  # The semantic space is a separate payload because the page only fetches it
  # when someone actually opens the reorganise controls.
  "$PY_BIN" graph/scripts/pack-semantic.py
  sed "s|__BUILD__|$BUILD_ID|g" graph/graph.template.html > "$ATLAS_OUT/index.html"
  echo "  $ATLAS_OUT/index.html  $(( $(wc -c < "$ATLAS_OUT/index.html") / 1024 )) KB"
else
  echo "  ⚠ skipping the atlas — no $GRAPH_DATA (run graph/scripts/ first)" >&2
fi

# The atlas is canonical at wiki.p2pfoundation.net/explore/ now. This build
# still emits it under dist/graph (that tree is what gets rsynced to the wiki
# host), but the copy published here only redirects, so the two can never drift.
cat > "$OUT/_redirects" <<'REDIR'
/graph/*  https://wiki.p2pfoundation.net/explore/:splat  302
/graph    https://wiki.p2pfoundation.net/explore/        302
REDIR

printf 'User-agent: *\nAllow: /\n' > "$OUT/robots.txt"
echo "✓ built ($BUILD_ID)"

if [ "${1:-}" = "--deploy" ]; then
  : "${CLOUDFLARE_ACCOUNT_ID:?set CLOUDFLARE_ACCOUNT_ID}"
  # A stored `wrangler login` session does NOT satisfy this: wrangler refuses
  # OAuth in a non-interactive shell and asks for the token anyway. Inject it
  # without it passing through a shell history or a transcript:
  #
  #   secretctl run --ref CLOUDFLARE_API_TOKEN=isec://claude-ops/prod/CLOUDFLARE_API_TOKEN \
  #     -- ./build.sh --deploy
  #
  # That ref is claude-workers, which carries Pages Write. It looked dead for a
  # day on 2026-09-01 — the monthly rotator rolled it and failed to store the
  # result — which sent this build hunting for a separate Pages token it never
  # needed. See dev-ops/netcup/scripts/cf-rotate-token.sh.
  : "${CLOUDFLARE_API_TOKEN:?set CLOUDFLARE_API_TOKEN (see the comment above this line)}"
  npx --yes wrangler@4 pages deploy "$OUT" \
      --project-name "$PROJECT" --branch main --commit-dirty=true
fi
