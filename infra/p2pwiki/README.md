# p2pwiki — MediaWiki deployment

Docker Compose stack for <https://wiki.p2pfoundation.net> as deployed on Netcup at `/opt/websites/p2pwiki/`. Files here mirror that live directory; treat the server copy as canonical and sync changes in both directions.

## Stack

| Service | Image | Purpose |
|---------|-------|---------|
| `p2pwiki` | `mediawiki:1.40` | Wiki front-end, Apache + PHP |
| `p2pwiki-db` | `mariadb:10.11` | Wiki database |
| `p2pwiki-elasticsearch` | `elasticsearch:7.10.2` | Search backend for CirrusSearch extension |
| `p2pwiki-dumps` | `nginx:alpine` | Serves `./dumps/` at <https://wiki.p2pfoundation.net/dumps/> |

Split across two compose files:

- `docker-compose.yml` — base: wiki + db + Traefik routing + rate-limiting + CF-only IP whitelist
- `docker-compose.override.yml` — elasticsearch + dumps nginx sidecar

Deploy uses both (the default `docker compose up` picks up `.yml` and `.override.yml` automatically).

## Files

| File | Purpose |
|------|---------|
| `docker-compose.yml` | Base stack (wiki + db) |
| `docker-compose.override.yml` | Elasticsearch + dumps nginx |
| `block-bots.conf` | Apache config: IP range blocks + server-level rewrite for aggressive scrapers |
| `robots.txt` | Served at `/robots.txt`; disallows Special pages, aggressive crawlers (Applebot, LinkupBot) |
| `uploads.ini` | PHP: `upload_max_filesize=50M`, `memory_limit=256M`, `max_input_time=300` |
| `htaccess-enable.conf` | Enables `AllowOverride All` so MediaWiki's `.htaccess` (short URLs) applies |
| `remoteip.conf` | Loads `mod_remoteip`; trusts `Cf-Connecting-Ip` from Cloudflare |
| `dump-wiki.sh` | Cron-invoked dump generator (see below) |

## Secrets / LocalSettings.php

Not in repo. On the server `LocalSettings.php` and `.env` live in `/opt/websites/p2pwiki/` and contain `SecretKey`, `UpgradeKey`, `DB_ROOT_PASSWORD`, `DB_PASSWORD`. Backed up via `/opt/backup-system/backup-docker.sh`.

## Dumps

Weekly current-revisions XML + monthly full-history XML + monthly images tarball, served at:

- <https://wiki.p2pfoundation.net/dumps/> — directory index
- `/dumps/p2pwiki-latest-current.xml.bz2` — current revisions, all namespaces (~53 MB)
- `/dumps/p2pwiki-latest-history.xml.bz2` — full revision history (~135 MB)
- `/dumps/p2pwiki-latest-images.tar` — all uploaded images (~1.7 GB)
- `/dumps/p2pwiki-latest-uploads.txt` — list of image filenames

Covers all namespaces (Main, Template, Category, File, Talk, User, Draft, MediaWiki, Help). Licensed CC BY-SA 3.0.

### Schedule

Root crontab on Netcup:

```
0 4 * * 0 /opt/websites/p2pwiki/dump-wiki.sh >> /var/log/p2pwiki-dump.log 2>&1
```

`dump-wiki.sh` decides what to produce:

- **Every Sunday**: current XML + uploads list
- **First Sunday of each month**: additionally full-history XML + images tar

Retention: 4 weeks current, 3 months history, 2 months images.

### Manual triggers

```bash
./dump-wiki.sh --current    # only current-revisions XML
./dump-wiki.sh --history    # only full-history XML
./dump-wiki.sh --images     # only images tar
./dump-wiki.sh --all        # everything, regardless of date
```

### Importing into a fresh wiki

```bash
bzcat p2pwiki-latest-history.xml.bz2 \
  | docker exec -i <mw-container> php maintenance/importDump.php --quiet
tar xf p2pwiki-latest-images.tar -C /path/to/mediawiki/images/
docker exec <mw-container> php maintenance/rebuildall.php
```

## Rate limits — there are TWO, and one of them is not in this repo

A reader clicking a RecentChanges option and getting a **blank changes list** is
almost always a 429, not an empty result set. `mediawiki.rcfilters`'
`Controller.js` invalidates the list before it fetches and then, on failure,
does literally nothing (`// Do nothing for failure`), so one rejected XHR leaves
an empty list that never recovers until the page is reloaded. Whether a given
click survives is a race, which is why the same window looks broken at one
`limit=` and fine at another.

Two independent limiters can produce that 429:

1. **Cloudflare** — zone `ea1c3cf1f24e254c062d3bea33b7ba86`, ruleset
   `4e93a0d4967f47089d6535aaa3f406fb` (`P2PWiki rate limits`), rule
   `c7855f743a5f4838969f66b3e041cc38`, matching
   `http.request.uri.path contains "Special:RecentChanges"`. **This rule lives
   only in the Cloudflare dashboard.** Added 2026-04-21 at **2 requests per 10
   seconds**, which is below what the page costs itself: one navigation plus
   rcfilters' live-update `peek=1` poll every 3s already exceeds it, so the
   *next* thing the reader clicked was guaranteed to be blocked. Raised
   2026-08-25 to **20 per 10s**, mitigation 10s. Read it with
   `GET /zones/$Z/rulesets/phases/http_ratelimit/entrypoint`
   (needs `CLOUDFLARE_JEFF_MAIN_API`; the infra/roller/DNS tokens all 403 here).
   Note it matches the **path**, so `index.php?title=Special:RecentChanges`
   sails past it and the pretty URL the UI actually uses does not — test with
   the pretty form or the limiter looks like it is not there.

2. **Traefik**, in `docker-compose.yml` — `p2pwiki-ratelimit` and
   `p2pwiki-inflightreq`, keyed on `Cf-Connecting-Ip`. `inflightreq.amount=8`
   was the binding constraint: it counts *concurrent* requests, and a single
   page load (HTML + ResourceLoader batches + icons) goes past 8 on its own,
   so subresources were being dropped at random. Now 12 for dynamic paths, with
   a separate `p2pwiki-static` router (priority 200) carrying `/load.php`,
   `/resources/`, `/skins/`, `/extensions/` and `/images/` at 48 concurrent —
   static assets are what makes a page load concurrent, and they are cheap.

Telling them apart: Traefik answers `429` with a 26-byte `text/plain` body and
the `permissions-policy`/`referrer-policy` headers from `security-headers@file`.
Cloudflare answers `429` with a 17-byte body and a `retry-after` header.

### Cloudflare defaults that were overriding this repo's stated policy

Fixed 2026-08-25. All three were the same shape: a Cloudflare default silently
winning over a policy written down here, with no error anywhere to notice.

- **`is_robots_txt_managed` was `true`**, and CF's managed robots.txt does not
  append to the origin's — it **replaces it outright**. `robots.txt` in this
  directory is bind-mounted and was never served. CF's version also asserted
  `Content-Signal: ai-train=no` and `Disallow: /` for Amazonbot and
  Applebot-Extended. Now `false`; the served file matches this repo's again.
- **`ai_bots_protection` was `"block"`**, blocking AI crawlers at the edge —
  the exact opposite of the policy `block-bots.conf` states in a comment ("AI
  crawlers are ALLOWED - P2P Foundation content should be in AI training
  data"). Now `disabled`. GPTBot and ClaudeBot fetch articles at 200.
- **WAF rule `3f4dab25` challenged `Special:Search`**, so every logged-out
  search returned 403 `cf-mitigated: challenge`. `block-scrapers.conf` promises
  the opposite ("HUMANS KEEP: ... ordinary search (only deep `offset=`
  pagination is refused)"). The `Special:Search` clauses are removed; the
  origin still refuses deep `offset=` pagination, which is where the actual
  scraper cost was.

Still challenged, deliberately: `WhatLinksHere`, `RecentChangesLinked`,
`action=history`, `diff=`, `oldid=`, `Contributions`, `Log`.

`bot_management.pre-2026-08-25.json` in `cloudflare/` holds the previous state.

### The poll that spends the budget

rcfilters polls `peek=1` while the tab is visible, every
`$wgStructuredChangeFiltersLiveUpdatePollingRate` seconds. `Controller.js` asks
for `limit: 1` but the model's own params win, so **the poll carries the current
`limit`/`days`** — an open 90-day tab re-runs the widest query the reader chose,
indefinitely. At the stock 3s that is ~20 requests/minute per reader against the
one endpoint Cloudflare rate-limits by path. Set to **10** in `LocalSettings.php`
on 2026-08-25 (alongside `$wgRCMaxAge`); live updates still work, at a third of
the traffic.

`LocalSettings.php` is bind-mounted as a **single file**, so its inode is shared
with the container: `sed -i` replaces the inode and the container keeps reading
the old file until it is recreated. Edit it in place (`open(p,'w')`, `cat >`) and
check `stat -c %i` matches on both sides. No restart is needed — but `opcache`
runs with `revalidate_freq=60`, so the web SAPI serves the old value for up to a
minute while CLI (`maintenance/getConfiguration.php`) already reports the new
one. That disagreement is the cache, not a failed edit; wait and re-check.
Verify what actually reaches the browser:

```
load.php?modules=mediawiki.rcfilters.filters.ui&only=scripts&raw=1
```

### The WAF rule that broke the feed icon

Custom rule `3f4dab25ab06421e80fd21c3a41e99dc` managed-challenges any path
`contains "/feed"`. MediaWiki serves its RSS icon from
`/resources/src/mediawiki.feedlink/images/feed-icon.svg` — which contains
`/feed` — so the icon 403'd with `cf-mitigated: challenge` for every reader. A
subresource cannot render a challenge interstitial; it just fails. The rule now
excludes the wiki's static trees. Any future `contains` rule needs the same
check against `/resources/`, `/skins/`, `/extensions/`, `/images/`, `/load.php`.

## Related P2P Foundation deployments (not in this repo)

| Project | Netcup dir | Repo |
|---------|-----------|------|
| French wiki | `/opt/websites/p2pwikifr/` | *(not extracted)* |
| WordPress blogs (`p2pfoundation.net`, `blog.`, `bloggr.`, `blogfr.`, `blognl.`) | `/opt/p2pfoundation/` | *(not extracted)* |
| AI chat backend | `/opt/apps/p2pwiki-ai/` | [`p2pwiki-ai`](https://gitea.jeffemmett.com/jeffemmett/p2pwiki-ai) |
