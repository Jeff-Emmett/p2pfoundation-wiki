#!/usr/bin/env bash
# Snapshot the Cloudflare rulesets that gate wiki.p2pfoundation.net.
#
# These rules exist ONLY in the Cloudflare dashboard. On 2026-08-25 one of them
# (2 requests / 10s on any path containing Special:RecentChanges) was rendering
# the RecentChanges page blank for readers, and nothing in this repo said it
# existed. The *.pre-2026-08-25.json files here are the state before that fix,
# kept so the change is reversible and so the next person can see the drift.
#
# Token access is per-phase, which is not obvious and costs a confusing 403:
#
#   http_ratelimit, http_request_firewall_custom  CLOUDFLARE_JEFF_MAIN_API
#                                                 (CLOUDFLARE_API_TOKEN is dead
#                                                  as of 2026-09-01 — /user/tokens/verify
#                                                  returns "Invalid API Token")
#   http_request_cache_settings                   cloudflare/CLOUDFLARE_CACHE_TOKEN
#                                                 (JEFF_MAIN 403s on this one)
#
# The infra, roller and DNS-tunnel tokens 403 on all of them.
#
#   secretctl run --ref CF=isec://claude-ops/prod/CLOUDFLARE_JEFF_MAIN_API -- ./fetch.sh
#   secretctl run --ref CF=isec://claude-ops/prod/cloudflare/CLOUDFLARE_CACHE_TOKEN -- ./fetch.sh cache
set -euo pipefail
Z=ea1c3cf1f24e254c062d3bea33b7ba86   # p2pfoundation.net
PHASES="http_ratelimit http_request_firewall_custom"
[ "${1:-}" = "cache" ] && PHASES="http_request_cache_settings"
for phase in $PHASES; do
    curl -fsS -H "Authorization: Bearer $CF" \
        "https://api.cloudflare.com/client/v4/zones/$Z/rulesets/phases/$phase/entrypoint" \
        | python3 -m json.tool > "$phase.current.json"
    echo "wrote $phase.current.json"
done
