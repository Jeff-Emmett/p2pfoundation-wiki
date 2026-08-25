# The headscale probe was reporting DOWN on a healthy control plane

**2026-08-25.** `headscale-control-push.sh` had been pushing `down` to Uptime
Kuma every 5 minutes with:

> control protocol returned 500 while health returns 200. This is the Cloudflare
> signature: POST with Upgrade tailscale-control-protocol is not being forwarded.

Every part of that message was wrong.

## What was actually happening

`vpn.jeffemmett.com` resolves to `159.195.32.209` — netcup's own IP, not a
Cloudflare one. **The record is grey-clouded: Cloudflare is not in this path at
all**, so it cannot be stripping anything.

The same request, against the same headscale, seconds apart:

```
POST /ts2021  --http1.1   ->  400   proto=1.1
POST /ts2021  (default)   ->  500   proto=2
```

**HTTP/2 has no Upgrade mechanism.** `Upgrade:` and `Connection: Upgrade` are
connection-specific headers that HTTP/2 forbids outright; sending them over h2
is malformed, and the 500 is the proxy saying so. curl negotiates h2 via ALPN by
default, so the probe was testing a path no tailscale client ever takes.

**400 is the healthy answer.** It means an unauthenticated bare POST reached the
real headscale and was rejected on its merits — which is exactly what the
existing runbook says to look for.

## The fix

Pin HTTP/1.1 on the `/ts2021` probe:

```bash
TS_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 --http1.1 \
  "${RESOLVE[@]}" -X POST \
  -H 'Upgrade: tailscale-control-protocol' \
  -H 'Connection: Upgrade' \
  "$HS_URL/ts2021" 2>/dev/null) || TS_CODE="000"
```

`--http1.1` is load-bearing. Real clients speak HTTP/1.1 for this handshake
precisely because the upgrade cannot exist in h2, so pinning it is what makes
the probe test the path clients actually use.

## Why this mattered more than a noisy alert

A monitor that cries wolf every 5 minutes is not merely annoying — it is
**indistinguishable from the real outage it exists to catch**. This is the same
class of failure as the backup that logs `SKIP` and exits 0: the signal is
present, it is just permanently saturated, so the one time it means something
nobody looks.

It also produced a real misdiagnosis. GX10 fell off the tailnet on 2026-08-25
and the standing red probe made that look like a headscale failure. It was not:
GX10 had exhausted its memory (swap 15G/15G, load average above 1000) and
dropped off the network. Headscale was serving correctly throughout.

## What this means for the VPS migration

`vps/deploy-headscale-on-vps.sh` opens by arguing that headscale must move to a
box with a public IP **because Cloudflare strips the upgrade header**. That
premise does not hold in the current configuration: the record is already
grey-clouded, headscale already has a direct public path, and the control
protocol already works.

The remaining argument for moving is availability, not correctness — headscale
is a single point of failure on netcup, and when netcup died on 2026-08-17 every
device logged out about three days later. That is still a real reason to move
it. It is a different reason, and the script's header should be read with this
correction in hand rather than at face value.
