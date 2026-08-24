# Failing the wiki back from the GX10 standby to Netcup

Done for real on 2026-08-24. This is the reverse of `standby/GO-LIVE.md`.

## What the two copies were

Netcup died 2026-08-17 06:21Z. The GX10 standby
(`standby/`, `~/p2pwiki-standby` on spark) served `wiki.p2pfoundation.net`
from 08-17 11:40Z until 08-24. It was built from a **current-only** XML dump, so
it is not an equal copy and never was:

|                            | Netcup      | GX10 standby |
|----------------------------|-------------|--------------|
| revisions                  | **150,071** | 46,852       |
| revisions in the last 90 d | **1,340**   | 691          |
| files (`image` table)      | **1,244**   | 1,209        |
| CirrusSearch               | yes         | no           |

The 90-day row is the one that matters to readers: a current-only dump keeps one
revision per page, so **Special:RecentChanges on the standby showed roughly half
the real edit activity** — a page edited three times in July appeared once. The
page count and the front page look identical either way, which is why this went
unnoticed for a week.

## Order of operations that worked

1. **Reconcile content BOTH ways before touching DNS.** Neither box was a
   superset of the other. See the two-way merge below.
2. **Restore the config Netcup lost.** A rebuilt host silently reverted to an
   older `block-bots.conf`, re-blocking anonymous `Special:RecentChanges`. Had
   DNS flipped first, the failback would have "worked" while making the very
   thing the failback was for invisible to logged-out readers.
3. **Flip one DNS record.** `wiki.p2pfoundation.net` CNAME
   `0c75deea…` (GX10 standby tunnel) → `a838e9dc-0af5-4212-8af2-6864eb15e1b5`
   (Netcup tunnel), proxied. Record `b7980056fed7640101796b28a1657d75`, zone
   `ea1c3cf1f24e254c062d3bea33b7ba86`. Token:
   `isec://claude-ops/prod/CLOUDFLARE_DNS_TUNNEL_TOKEN` (**not**
   `CLOUDFLARE_API_TOKEN`, which 10000s on this zone).
4. **Verify against the statistics API, not the front page.**
   `api.php?action=query&meta=siteinfo&siprop=statistics` — `edits: 150071`
   proves Netcup is answering; `46852` would mean the standby still is.

The edge Worker keeps the standby as rung 2 automatically: it tries the origin
first and falls back only on failure, so a bad flip degrades to the previous
behaviour instead of an outage. `x-p2pwiki-fallback` absent = origin is serving.

## The two-way merge (`standby/merge-to-netcup.sh` is only half of it)

- **standby → Netcup**: 4 pages Mbauwens edited during the interim —
  `Copyfair`, `Netarchical Capitalism`, `Noosphere`, `Social Contracts`.
- **Netcup → standby**: **11 pages / 22 revisions from 08-16 09:41 → 08-17
  05:39** that the standby never had, because the 08-16 dump it was topped up
  from is cut at 04:00. Michel's and Strypey's whole 08-16 workday was in the
  hole between the last dump and the crash.

**Conflict test: compare `rev_len` as well as `rev_timestamp`.** A matching
timestamp alone does not prove the standby edit descends from Netcup's current
text. All four were clean by both.

**A gap import bounded by a dump's FILENAME always leaves a sliver.** Bound it
by `MAX(rev_timestamp)` in the surviving primary instead.

After importing on either side: `importDump.php`, then
`rebuildrecentchanges.php`, then `initSiteStats.php --update`. Without the
rebuild the revisions exist and RecentChanges does not show them.

## Config that had silently reverted on Netcup

`block-bots.conf` was the pre-2026-06-14 version, so **all three** anon-deny
layers were blocking plain `Special:RecentChanges` again:

1. `block-bots.conf` — server rewrite + `<If>` in `<LocationMatch>`
2. `.htaccess` — two more rewrites (**server-only until now; committed here as
   `htaccess`, because a layer that lives nowhere but the host is a layer that
   reverts without anyone noticing**)
3. `LocalSettings.php` — a PHP prepend above `<?php … $wgSitename`, which cannot
   be committed (secrets). Narrow its regex to
   `/^Special:RecentChangesLinked/i` by hand.

All three narrow to **RecentChangesLinked only**: the recursive variant and the
anon `feedrecentchanges` RSS stay blocked, plain RecentChanges is public.

`block-bots.conf`, `.htaccess`, `LocalSettings.php` are **single-file bind
mounts**. Editing them in place changes the inode and the running container
keeps reading the old one — `docker restart p2pwiki` or the change is a no-op
that looks applied.

Verify (browser UA; curl gets a Cloudflare managed challenge on `Special:*`, so
test at the origin with a `Host:` header, not through the edge):

    /index.php?title=Special:RecentChanges                    -> 200
    /Special:RecentChanges                                    -> 200
    /index.php?title=Special:RecentChangesLinked&target=…     -> 403

Also backported from the host, where they existed only on disk: `robots.txt`
(the 2026-05-09 crawler rules), `block-scrapers.conf` (the 2026-08-14
worker-pool saturation fix) and its compose mount.

## Left standing, deliberately

- **`cloudflared-netcup-local` on GX10 is stopped and must stay stopped.** It
  runs the Netcup tunnel against a GX10 Traefik with no wiki router. Reviving it
  on 08-19 is what turned a covered outage into a visible one. It is
  `restart=unless-stopped`, so a `docker compose up` in that directory would
  bring it back.
- **The standby is still writable.** That was right during a multi-day outage
  and is a divergence risk now: a transient Netcup 5xx sends a reader to a
  standby they can still edit. Make it read-only, or accept that the merge has
  to be re-run.
- **The standby is now far behind** (46,852 revisions vs 150,071) and is a
  read-only last resort, not a peer.
