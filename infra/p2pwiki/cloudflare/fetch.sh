#!/usr/bin/env bash
# Snapshot the Cloudflare rulesets that gate wiki.p2pfoundation.net.
#
# These rules exist ONLY in the Cloudflare dashboard. On 2026-08-25 one of them
# (2 requests / 10s on any path containing Special:RecentChanges) was rendering
# the RecentChanges page blank for readers, and nothing in this repo said it
# existed. The *.pre-2026-08-25.json files here are the state before that fix,
# kept so the change is reversible and so the next person can see the drift.
#
# Only CLOUDFLARE_JEFF_MAIN_API and CLOUDFLARE_API_TOKEN work on this endpoint;
# the infra, roller and DNS-tunnel tokens all 403.
#
#   secretctl run --ref CF=isec://claude-ops/prod/CLOUDFLARE_JEFF_MAIN_API -- ./fetch.sh
set -euo pipefail
Z=ea1c3cf1f24e254c062d3bea33b7ba86   # p2pfoundation.net
for phase in http_ratelimit http_request_firewall_custom; do
    curl -fsS -H "Authorization: Bearer $CF" \
        "https://api.cloudflare.com/client/v4/zones/$Z/rulesets/phases/$phase/entrypoint" \
        | python3 -m json.tool > "$phase.current.json"
    echo "wrote $phase.current.json"
done
