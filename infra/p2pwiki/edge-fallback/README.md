# Edge fallback for wiki.p2pfoundation.net

A Cloudflare Worker that stands in front of the wiki and, when the origin is
unreachable, serves the article from our own snapshot instead of a raw
Cloudflare error screen.

**Status: built and tested locally, NOT deployed.** Deploying needs Cloudflare
credentials this repo does not have — see [Before you can deploy](#before-you-can-deploy).

---

## Why

On 2026-08-17 the Netcup host went off the network at layer 3 and took every site
on it down. Readers of the wiki got a bare Cloudflare **530 / `error code: 1033`**
for hours. The wiki was fine — its origin was gone, and nothing at the edge was
prepared to say so.

A Worker runs at the edge regardless of origin health, which is the point: it does
not care what the origin returned, or whether the origin exists.

### Why not Cloudflare's "Always Online"

It is one free toggle and it looks like it solves exactly this. It does not:

> "Always Online only activates when Cloudflare cannot connect to your origin at
> all (resulting in Cloudflare-generated 520–527 status codes)."
> — [Cloudflare docs](https://developers.cloudflare.com/cache/troubleshooting/always-online/)

The wiki is behind a Cloudflare Tunnel. A tunnel with no connector produces **530**
(sub-code 1033), which is outside 520–527. Always Online would not have fired on
2026-08-17, and would not fire on any repeat. It is free and harmless — enable it
if you like — but it is not the fix.

### Why our own snapshot outranks the Internet Archive

The obvious design puts web.archive.org first: no storage to manage, always current
enough. While this was being written, during the outage it was meant to cover,
web.archive.org returned:

```
503 — Internet Archive services are temporarily offline
```

A fallback whose availability you do not control is a nice-to-have. The Archive is
still in the chain, one rung down.

---

## Fallback order

| # | Source | Status served | Notes |
|---|--------|---------------|-------|
| 1 | Origin (Netcup) | whatever it says | A healthy origin is passed through untouched |
| 2 | **GX10 standby** | passed through (200) | Real MediaWiki on our own metal — skins, search, Special: pages. `STANDBY_ORIGIN` empty disables it |
| 3 | R2 snapshot (ours) | 503 + `Retry-After` | Article rendered from wikitext at the edge |
| 4 | Wayback Machine | 503 (docs) / 200 (assets) | Opportunistic; `WAYBACK_ENABLED=off` to disable |
| 5 | Branded offline page | 503 | Always works, needs nothing |

The standby is tried for **every** path, assets and `Special:` pages included,
because it is a real wiki and can serve things no static copy can. The rungs
below it only handle article paths.

**Why this does not loop.** The standby hostname is on the same zone, and
Cloudflare sends same-zone subrequests straight to the origin without re-running
Workers. The standby also does not host-canonicalise — verified, it answers 200
for any `Host` — so it cannot 301 back to `wiki.p2pfoundation.net` and round the
loop that way. Its `$wgServer` is the *production* hostname on purpose, so every
link it emits keeps readers on the real address.

**Why 503 and not 200** on fallback documents: it is the standards-correct signal for
temporary downtime, and it stops search engines treating a reduced offline copy as the
canonical page. Browsers render a 503 body normally, so readers see the article either
way. Assets fetched from the Archive are served **200** so the browser actually applies
them — only the HTML document carries the 503.

**What does NOT trigger the fallback:** 502, 503 and 504 from the origin. Those can come
from Traefik or MediaWiki itself, and a Traefik that answers is a host that is up. Serving
a two-week-old snapshot over a transient application hiccup would be a downgrade, so that
case is deliberately left alone.

---

## Before you can deploy

Two separate credentials, and neither lives in this repo:

1. **Cloudflare account access** for Wrangler. Easiest is `wrangler login` in your own
   terminal (browser OAuth). A scoped API token also works and needs:
   `Workers Scripts:Edit`, `Workers Routes:Edit`, `Account Settings:Read`, `Zone:Read`,
   and `Workers R2 Storage:Edit` for stage 2.
2. **An R2 S3 access key pair** (stage 2 only), created in the R2 dashboard, for `rclone`.
   This is *not* the same thing as the API token above.

Both belong in Infisical once it is reachable again — it was on the downed host, which is
why they are not referenced here yet.

---

## Stage 1 — the Worker (mitigates immediately, no snapshot)

Gets you the branded offline page plus opportunistic Archive content. Minutes to deploy.

```bash
cd infra/p2pwiki/edge-fallback
npm test                       # 10 assertions, no network, no credentials

# Exercise it against the real edge WITHOUT touching production.
# While the origin is down this runs the actual fallback path.
wrangler dev --remote

wrangler deploy                # publishes AND attaches the route in wrangler.toml
```

Verify:

```bash
curl -sS -D- https://wiki.p2pfoundation.net/Peer_to_Peer -o /dev/null | grep -i x-p2pwiki-fallback
# origin healthy  -> header absent
# origin down     -> x-p2pwiki-fallback: snapshot | wayback | offline-page
```

## Stage 2 — the snapshot (real content instead of an apology)

Already built and validated locally: **41,215 pages, 280 MB**, from the
2026-08-04 dump.

```bash
# 1. Build from the weekly MediaWiki XML dump
python3 scripts/build-snapshot.py \
    --dump ~/.cache/p2pwiki-dump.xml.bz2 \
    --out  ~/.cache/p2pwiki-snapshot

# 2. Create the bucket and upload
wrangler r2 bucket create p2pwiki-snapshot
rclone copy ~/.cache/p2pwiki-snapshot/pages r2:p2pwiki-snapshot/pages \
    --transfers 32 --checksum

# 3. Uncomment the [[r2_buckets]] block in wrangler.toml, then
wrangler deploy
```

The R2 binding stays commented out until the bucket exists, because deploying a binding
to a missing bucket fails the whole deploy.

### Keeping it fresh

`dump-wiki.sh` already writes a current-revisions XML every Sunday 04:00 to
`/opt/websites/p2pwiki/dumps/` — **on Netcup**, i.e. on the very host this snapshot exists
to survive. So the refresh has to pull, not push:

```
weekly, from GX10:  fetch /dumps/p2pwiki-latest-current.xml.bz2
                    build-snapshot.py
                    rclone copy → R2
```

You cannot snapshot a dead host, so the snapshot's freshness is capped by the last
successful pull. That is fine — but let the age show. The banner prints the build date
from R2 `customMetadata.builtAt`; set it on upload so readers are told how old the copy
is rather than guessing.

---

## Turning it off

```bash
wrangler deploy --var FALLBACK_ENABLED:off   # transparent pass-through, keeps the route
wrangler delete                              # remove entirely
```

The Worker also self-disarms: any unexpected error inside the fallback returns the
original origin response instead. A bug here must never be worse than the outage it covers.

---

## Cost

| Thing | Free tier | This wiki |
|-------|-----------|-----------|
| Workers requests | 100k/day | **Check first** — the Worker runs on *every* request. Read actual traffic off the Cloudflare dashboard; above 100k/day needs Workers Paid ($5/mo) |
| R2 storage | 10 GB | 280 MB |
| R2 Class A (writes) | 1M/month | 41,214 per full re-upload |
| R2 Class B (reads) | 10M/month | one read per article view, during outages only |

Only the Workers request count can push this off the free tier.

---

## Layout

| Path | Purpose |
|------|---------|
| `src/worker.js` | Fallback chain, failure detection, offline page, styles |
| `src/wikitext.js` | Minimal MediaWiki → HTML renderer (readable, not faithful) |
| `scripts/build-snapshot.py` | XML dump → one file per page, keyed for R2 |
| `scripts/emit-key-fixture.py` | Generates the cross-language key fixture |
| `test/fallback.test.mjs` | 10 assertions; run with `npm test` |
| `test/key-fixture.json` | 1007 real titles + expected keys, from the live corpus |

### The one test that matters

`snapshotKey()` in `worker.js` and `safe_key()` in `build-snapshot.py` compute R2 keys
independently, in two languages. If they drift, the failure is **silent**: every lookup
misses, readers get the generic offline page instead of the article, and nothing errors
or logs. So the Python side writes down what it actually produced for 1007 real titles —
parentheses, apostrophes, ampersands, non-ASCII, slashes, percent signs — and the JS side
must reproduce every one.

That is also why the key scheme is hand-rolled rather than `encodeURIComponent` /
`urllib.parse.quote`: those two disagree about `!'()*`, and wiki titles are full of
parentheses. Two "equivalent" encoders differing on one character class is exactly the
near-miss this estate has been bitten by before.

---

## Limitations

1. **Read-only.** Editing, login and search are unavailable during an outage. The offline
   page says so explicitly, and a non-GET request during fallback is refused rather than
   accepted-and-discarded — an edit that appears to save and vanishes is worse than a
   clear refusal.
2. **No images.** The snapshot is wikitext only; the image volume is ~1.7 GB. Image
   requests get an empty 503, so pages render with broken images rather than with an HTML
   error page in an `<img>`.
3. **Reduced rendering.** No template expansion, no parser functions, no tables — those
   need MediaWiki, which is the thing that is down. Tables are replaced by a visible
   `[table omitted]` note rather than dropped silently.
4. **Stale by up to a week**, more if a pull is missed. Shown in the banner.
5. **One known key collision** in the 2026-08-04 corpus: 41,215 pages produced 41,214
   files, so two titles normalise to the same key. Harmless at this scale, unchased.
6. **This changes what readers see, not whether the outage happens.** The recurrence work
   is `dev-ops/docs/single-host-blast-radius.md` (TASK-HIGH.70).
