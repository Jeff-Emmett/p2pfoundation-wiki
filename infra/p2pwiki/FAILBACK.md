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

## RecentChanges looked empty after the failback, and was not

The first report after cutting over was "I only see back to Aug 20". Nothing was
missing. `Special:RecentChanges` defaults to **7 days / 50 entries**, and the
wiki spent 08-17 to 08-24 on the standby, so Netcup's revision table holds almost
nothing in a 7-day window. The page rendered exactly four lines. The last
pre-outage edit (`Glomo`, 08-17 05:39) sat *just* outside seven days.

Raised in `LocalSettings.php` (server-only, not in this repo):

    $wgDefaultUserOptions['rcdays']  = 30;
    $wgDefaultUserOptions['rclimit'] = 500;

30 days is the largest window `Special:RecentChanges` offers in its own UI;
`$wgRCMaxAge` is 90 days, so `?days=90&limit=500` reaches further by hand. The
30-day default now renders 500 entries back to late July in ~1.8 s. **If Apache
saturates again (`block-scrapers.conf`, 2026-08-14), this limit is the first
knob to turn back down** — RecentChanges is anon-readable and DB-expensive.

An outage makes RecentChanges lie in both directions: the standby made it look
too thin by collapsing history, and the failback made it look too short by
leaving a quiet week inside the default window. Read the revision table, not the
special page, when deciding whether data survived.

## Loss audit, run after the cutover

Compared **every page on both hosts**, not just recently-changed ones.

- 45,579 pages on Netcup, 45,566 on the standby. **Nothing real exists only on
  the standby.** The seven standby-only titles are 3 standby-local interface
  pages (`MediaWiki:Sitenotice`/`Loginprompt`/`Userloginprompt`) and 4 namespace
  import artifacts — `Draft:Template_Draft`, `Ns274:AddThis/Vimeo/YouTube` landed
  in ns 0 as literal titles because those namespaces were undeclared at import.
  Netcup holds all four correctly in ns 118 and ns 274.
- **Files: the standby has none Netcup lacks** (0 by `img_name`), while Netcup has
  35 the Wayback recovery never found, including MDEE's two 2026-05-18 uploads.
  4,802 files / 1.8 GB on disk against 1,244 `image` rows — `oldimage` versions
  are there too, so the volume is whole.
- **Accounts: no editor exists only on the standby.** Its two extras are `Admin`
  and `MediaWiki default`, both created by its own installer.
- Interim writes on the standby were 4 Mbauwens page edits (merged), 3 interface
  pages, and one test page created and deleted. **No user uploaded a file to the
  standby** — its 1,209 upload log rows are all the Wayback recovery, and it never
  ran the `editor-request` flow, so no access requests were stranded there either.
- The four merged pages hash **byte-identical** on both hosts
  (`action=parse&prop=wikitext`). Verify a merge this way, not by eye.

Only 1,210 pages are "newer" on the standby: 1,209 `File:` description pages the
Wayback re-upload restamped, and `Main_Page`, which the standby had to rebuild
because the installer stub had overwritten it. **Neither should ever be pushed to
Netcup**, which holds the authentic versions.

## Left standing, deliberately

- ~~`cloudflared-netcup-local` must stay stopped.~~ **Removed from
  `~/apps/netcup-refugee/docker-compose.yml` on GX10, 2026-08-24**, so
  `docker compose up` can never recreate it.

  The container itself is deliberately KEPT, stopped. `tunnel-primary-guard.sh`
  (cron, every 2 min, on both hosts) starts and stops it by name and is the
  automatic failover for the 431 hostnames on tunnel `a838e9dc` — it took over at
  09:56Z when the tunnel was provably unserved and yielded at 11:33Z when Netcup
  returned, arbitrated by the TXT record `_tunnel-primary.jeffemmett.com`
  (currently `"netcup"`). The guard yields unconditionally and takes over only on
  two consecutive 530s, so a stopped container is safe; an absent one means no
  failover and a `FATAL` on every guard run.

  **Never run `docker compose up --remove-orphans` in that directory** — it would
  delete the stopped container and the failover with it.

- ~~A GX10 tunnel token was disclosed and needs rotating.~~ **Rotated 2026-08-24
  20:54Z** with `standby/rotate-tunnel-token.sh`
  (`57eb9758…` → `79bfd6a5…`). It PATCHes `tunnel_secret`, which rotates the
  credential while **keeping the tunnel id** — necessary, because
  `wiki-standby.p2pfoundation.net` is a CNAME to `<id>.cfargotunnel.com` and the
  Worker's `STANDBY_ORIGIN` points at that hostname. Recreating the tunnel would
  cost a DNS change and a Worker redeploy for nothing.

  Two things that are easy to get wrong:
  **`docker restart` is not enough.** The token is an argv element baked in at
  container-create time, so a restart re-runs the dead one. Use
  `docker compose up -d --force-recreate`.
  **Verify by `opened_at`, not by status.** A tunnel reads `healthy` the moment
  any connector attaches; the proof a rotation took is that *every* connection
  opened after it. All four did, within three seconds.

  The token is now `isec://claude-ops/prod/P2PWIKI_STANDBY_TUNNEL_TOKEN` as well
  as in the standby's `.env`, closing the note in the standby README about it
  living outside Infisical. The rotation is driven by
  `CLOUDFLARE_DNS_TUNNEL_TOKEN`; `CLOUDFLARE_API_TOKEN` is not authorized for
  `cfd_tunnel` on this account.

  **Do not inspect a token-run connector with `docker inspect … .Config.Cmd` or
  `.Env`.** That is how the value leaked in the first place. `.Mounts` is safe.
- ~~The standby is still writable.~~ **Closed 2026-08-24 20:34Z** with
  `standby/disable-editing.sh`. The writable window was
  `[20260818083810 .. 20260824203432]`, recorded in `standby-readonly-since.txt`
  next to `standby-writable-since.txt`. The risk it closes: the edge Worker falls
  back per-request, so a transient Netcup 5xx could hand a reader an editable
  page on the copy nobody merges from, and that divergence would be silent.

  The script appends a final block rather than deleting what `enable-editing.sh`
  added. That file now carries four overlapping blocks and is read top to bottom
  with the last assignment winning, so cutting one out of the middle means
  reasoning about what the other three leave in force. Appending is easier to
  verify and reverses by deleting one section.

  **Test the shape of the edit form, never the $wgReadOnly message.** The
  group-permission check fires first, so grepping for the message returns 0 hits
  and reads as a failure. Confirmed four ways: `<title>View source`, a `readonly`
  textarea, no `wpSave`, and `action=edit` over the API returning error code
  `readonly`. Reads still 200; `showSiteStats.php` still runs, because the
  `PHP_SAPI === 'cli'` exception is re-asserted last — without it `importDump.php`
  refuses and the standby could never be resynced.
- **The standby is now far behind** (46,852 revisions vs 150,071) and is a
  read-only last resort, not a peer.
