#!/usr/bin/env bash
# standby-guard — answer "is THIS host actually serving the thing I am about to
# back up?", and make a backup script exit quietly when the answer is no.
#
# WHY THIS EXISTS. The estate failed over to GX10 when netcup died on
# 2026-08-17 and failed BACK on 2026-08-24. GX10's copies of p2p-web and
# p2p-blog were never stopped: they are still `Up`, still healthy, still passing
# their healthchecks, and serving nobody. The nightly backups kept running
# against them and kept reporting success, so from 08-24 onward
# `p2pfoundation-backup.sh` produced a perfect archive of a frozen database
# while the live one on netcup drifted away from it.
#
# That is worse than no backup, because it looks like one. The sizes are within
# a few percent of the real thing, the log ends in "ok", and the failure is only
# visible if you already suspect it.
#
# WHY NOT A STATE FILE. The obvious design is a flag written by whatever fails
# the estate over. There isn't one: `netcup-return.sh` only manages
# katheryn-website's DNS, and the 08-24 estate move did not go through it. A
# flag also has to be correct at exactly the moment everything else is on fire,
# which is when flags are least trustworthy. So this measures instead of asking.
#
# HOW IT DECIDES. Two tests, cheapest first:
#
#   1. If the container is not running here, this host is not serving it. That
#      alone settles mailcow, docmost, votc and zulip, which the failback
#      stopped.
#
#   2. Otherwise, prove it positively: fetch the PUBLIC url with a nonce in the
#      query string and look for that nonce in this container's access log. If
#      it lands here, this host is what the world reaches. If it does not, some
#      other host is, and this copy is a standby.
#
# Test 2 is the important one. A running container with no traffic is exactly
# what a standby looks like, and it is indistinguishable from a live service by
# `docker ps`, by uptime, or by healthcheck. Traffic is the only thing that
# actually differs, and a nonce we generated is traffic we can attribute.
#
# The nonce goes in the query string so Cloudflare treats it as a distinct
# object and cannot answer from cache — a cached 200 would prove nothing about
# which origin is alive.
#
# USAGE, from inside a backup script, before it does any work:
#
#     source /home/mycopunk/bin/standby-guard.sh
#     guard_active p2p-web https://p2pfoundation.net/ || exit 0
#
# Exit 0 on skip, deliberately: a standby that declines to back up is a correct
# outcome, not a failure, and cron should not mail about it every night.
set -uo pipefail

GUARD_LOG="${GUARD_LOG:-$HOME/standby-guard.log}"

_guard_say() {
    printf '%s [standby-guard] %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*" \
        | tee -a "$GUARD_LOG" >&2
}

# guard_active <container> [public-url] [--require-url]
#
# Returns 0 when this host is serving, 1 when it is a standby.
guard_active() {
    local container="$1" url="${2:-}" strict="${3:-}"

    if ! docker inspect "$container" --format '{{.State.Running}}' 2>/dev/null | grep -qx true; then
        # Stopped here does not by itself mean "another host has it". It can also
        # mean the service is down EVERYWHERE — and then this host's copy may be
        # the newest one in existence, which is the worst possible moment to skip
        # a backup. zulip was in exactly this state on 2026-08-25: stopped on
        # GX10 by the failback, and 502 on netcup too.
        if [ -n "$url" ]; then
            local down_code
            down_code=$(curl -s -o /dev/null -w '%{http_code}' -m 25 "$url" 2>/dev/null || echo 000)
            case "$down_code" in
                2*|3*)
                    _guard_say "SKIP: $container is stopped here and $url (HTTP $down_code) is served elsewhere"
                    return 1 ;;
                *)
                    _guard_say "PROCEED: $container is stopped here AND $url is down (HTTP $down_code) — this copy may be the only one"
                    return 0 ;;
            esac
        fi
        _guard_say "SKIP: $container is not running here — this host is not serving it"
        return 1
    fi

    # No public URL given: running-and-present is all we can check. Say so
    # rather than implying the stronger test passed.
    if [ -z "$url" ]; then
        _guard_say "OK: $container is running (no url given — traffic not verified)"
        return 0
    fi

    local nonce="hostprobe-$$-$(date -u +%s)"
    local sep='?'; case "$url" in *\?*) sep='&';; esac

    # --max-time, not --connect-timeout: a live origin that is slow still counts
    # as live, but a probe must never wedge a nightly backup.
    curl -s -o /dev/null -m 25 -H 'Cache-Control: no-cache' \
         "${url}${sep}__hostprobe=${nonce}" 2>/dev/null || true

    # The request has to travel Cloudflare -> tunnel -> traefik -> container, and
    # access logs are written on response. Poll instead of sleeping a fixed
    # guess: usually one round, and a slow origin does not produce a false SKIP.
    local i
    for i in 1 2 3 4 5 6; do
        if docker logs --since 2m "$container" 2>&1 | grep -q "$nonce"; then
            _guard_say "OK: $container served the probe — this host is live"
            return 0
        fi
        sleep 2
    done

    # The probe did not land here. Usually that means another host is serving.
    # It can also mean the site is down everywhere, which is NOT a reason to
    # skip a backup -- so distinguish the two before deciding.
    if [ "$strict" = "--require-url" ]; then
        _guard_say "SKIP: $container did not serve the probe — another host is live"
        return 1
    fi

    local code
    code=$(curl -s -o /dev/null -w '%{http_code}' -m 25 "$url" 2>/dev/null || echo 000)
    case "$code" in
        2*|3*)
            _guard_say "SKIP: $container is running but $url (HTTP $code) is served elsewhere"
            return 1 ;;
        *)
            # Nobody is serving it. Back up anyway: during an outage this host's
            # copy may be the only copy, and skipping would be the one decision
            # that cannot be undone later.
            _guard_say "PROCEED: $url is unreachable (HTTP $code) — backing up anyway rather than skipping during an outage"
            return 0 ;;
    esac
}
