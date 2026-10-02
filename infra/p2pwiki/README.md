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

## Extensions — on disk is not the same as loaded

The `mediawiki:1.40` image ships ~37 bundled extensions, and the `p2pwiki-extensions`
named volume keeps them. **Presence does nothing**: only a `wfLoadExtension()` in
`LocalSettings.php` turns one on, and that file is not in this repo — so this table is
the only record a rebuilt host would have.

Loaded, read back from `api.php?action=query&meta=siteinfo&siprop=extensions` on
2026-10-02:

| Extension | Provides |
|-----------|----------|
| Vector (skin) | Default skin, legacy 2011 rendering |
| CategoryTree | `<categorytree>` |
| YouTube 1.9.4 | `<youtube>`, `<aoaudio>`, `<aovideo>`, `<nicovideo>` |
| ConfirmEdit + QuestyCaptcha | Account-creation CAPTCHA |
| WikiEditor | Edit toolbar |
| HitCounters | Page view counts (restored from the old wiki) |
| Elastica + CirrusSearch | Search (replaces MySQL full-text) |
| Cite | `<ref>` / `<references />` footnotes — enabled 2026-10-02 |

Everything else in `docker exec p2pwiki ls extensions` is on disk and inert. The one
that still costs something is **ParserFunctions**: a handful of pages use `{{#if}}`
and render it as raw text.

### Cite was lost, not never installed

`Help:Create Citations` is an on-wiki help page that documents the extension and uses
a footnote itself, so the wiki had Cite at some point. The archived `Special:Version`
of 2026-02-02 already shows it gone, and the `LocalSettings.php` now on Netcup never
loaded it — so 192 pages displayed `&lt;ref&gt;...&lt;/ref&gt;` as visible text and a
bare `<references/>` line where their footnotes should have been.

Re-enabled 2026-10-02 by appending `wfLoadExtension( 'Cite' );`. Nothing was
downloaded: `extensions/Cite` in the image is the version that matches this core, and
Cite has no tables, so no `update.php`. Two things worth knowing for next time:

* The parser cache hands out the old broken HTML for up to `$wgParserCacheExpireTime`
  (86400s here) after the switch. `UPDATE page SET page_touched = <now>` over the
  affected pages invalidates it immediately; 200 pages were touched and re-parsed.
* MediaWiki 1.40's Cite appends the footnote list at the foot of the article when
  `<references />` is missing, so the 17 pages without the tag need no edit. Only
  `Help:Create Citations` shows a red Cite error, and for a content reason: its
  examples define `<ref name="multiple">` twice with different text.

### Where this configuration actually lives

`LocalSettings.php` is **not** in this repo and never has been. Production runs
from `/opt/websites/p2pwiki` on Netcup, which is a checkout of a *second, private*
repo — `gitea.jeffemmett.com/jeffemmett/p2pwiki`, push-mirrored to the private
`github.com/Jeff-Emmett/p2pwiki` — and there `LocalSettings.php` **is** tracked.
The overlapping files (`docker-compose.yml`, `block-*.conf`, `robots.txt`) were
byte-identical in both repos when compared on 2026-10-02, but nothing enforces
that: edits here do not reach production, and edits there do not reach here.

Two traps found on 2026-10-02 while making the Cite change persistent:

* That checkout's deploy branch is **`dev`**, and its `main` was **8 commits
  behind** — the September scraper blocks among them. A fresh clone lands on
  `main`, so a rebuild from it would have resurrected a months-old config.
  `main` has since been fast-forwarded to `dev`.
* `/opt/deploy-webhook` carries a `p2pwiki` entry that runs
  `docker compose up -d --build` in that tree. Its branch filter compares the
  pushed branch against the one checked out on the server, so **pushing `dev`
  redeploys the live wiki** — it recreated `p2pwiki-db` (~45 s, clean start, no
  crash recovery) and restarted `p2pwiki`. Pushes to any other branch are
  skipped.

Backup: `/opt` is one of the 22 restic roots in `/opt/backup-system/backup-docker.sh`,
and `/opt/websites/p2pwiki/LocalSettings.php` is present in the nightly R2
snapshot (verified against the 2026-10-02 03:03 snapshot). The exclude list does
not touch `/opt/websites`.

### Drift probe

`monitoring/p2pwiki-extension-drift-probe.{sh,service,timer}` plus
`monitoring/p2pwiki-smtp-config-check.py` — installed on Netcup under
`/opt/scripts/`, every 6 h. Three assertions, because all three have failed
silently here:

1. the extension set the wiki reports as loaded;
2. that `<ref>` still renders a footnote list — loaded and working are different
   claims, and this one parses from `text=` rather than a page, so the answer
   cannot come out of the parser cache;
3. that `$wgSMTP` still pairs `tls://` with 465 and still holds the password the
   secret file holds.

The SMTP comparison lives in its own Python file rather than a heredoc inside the
shell script: it has to match PHP string literals, and nesting quotes three deep
is how a probe quietly acquires a bug that makes it pass.

**Two alert channels on purpose, not by accident.** `OnFailure=unit-failure-notify@%n`
mails Jeff when a run *fails*. Uptime Kuma push monitor **332**
("p2pwiki config drift…", notification "Mailcow Email Alerts", heartbeat interval
7 h against the 6 h timer) goes DOWN when a heartbeat *never arrives* — which is
the case `OnFailure` structurally cannot see, because a timer that stops firing
produces no failed unit to react to. The thing this whole file is about went
unnoticed for months; one channel that cannot see a dead checker is not enough.

The monitor was created from `monitoring/create-kuma-push-monitor.py`, and
`monitoring/check-kuma-monitor.py` reads it back. Both run as a one-off through
the `kuma-alert-agent` service, which already carries the Kuma admin credential
from Infisical:

```sh
cd /opt/apps/kuma-alert-agent
docker compose run -T --no-deps --name kuma-mk kuma-alert-agent python - < create-kuma-push-monitor.py
```

That is the point of the detour: Kuma has no REST route for creating a monitor,
only socket.io, and the admin password never has to pass through a terminal or a
command line to get there. The script prints the push token on its own last line
so the caller can append it to `/etc/uptime-kuma-push.env` (0600) without reading
it. Creating the monitor is idempotent — a second run finds it by name and
reports `CREATED=no (reused)`.

Exit 0 OK, 3 drift, 2 inconclusive. **Inconclusive is deliberately not 0**: the
Cite loss and the reverted `$wgSMTP` fix both went unnoticed for months precisely
because nothing could distinguish "checked and fine" from "never checked". Both
failure paths were exercised before installing, six of them, and `EXPECTED`,
`CONTAINER`, `LS_FILE` and `SMTP_SECRET` are all overridable for exactly that
reason: `EXPECTED="Cite Nonexistent"` → 3, a copy with the port put back to 587 →
3, a copy with a wrong password → 3, an unreadable `LS_FILE` → 2,
`CONTAINER=no-such-container` → 2, and untouched → 0.

The alert path was exercised too, rather than assumed: a deliberate exit 3 in a
transient unit (`systemd-run --property=OnFailure=…`) reached
`jeff+agent@jeffemmett.com`, `status=sent`. While checking it,
`p2pwiki-log-tail.service` turned out to carry its `OnFailure=` inside
`[Service]`, where systemd ignores the key with a warning — so the one unit whose
three months of silence was the original bug still could not report its own
death. Moved to `[Unit]`.

## Mail (`$wgSMTP`) — repaired twice now, for the same reason

Password reset and the editor-access notifications go out through
`mail.rmail.online`. The scheme and the port have to agree: `tls://` is implicit
TLS, which lives on **465**. On 587 the connection dies before anything is
queued — from inside the wiki container,
`fsockopen("tls://mail.rmail.online", 587)` returns
`error:0A00010B:SSL routines::wrong version number`, and MediaWiki reports only
"Failed to connect socket".

Fixed on 2026-06-14 (port, plus a password rotation). The 2026-08-24 failback
restored a pre-June copy of the block and undid both, silently — the wiki serves
200s either way, so nothing noticed for six weeks. Fixed again 2026-10-02 and
verified three ways:

* `AUTH LOGIN` as `noreply@p2pfoundation.net` answers `235` on 465 (and on 587
  with STARTTLS, which is how we know the credential, not the port, was fine);
* PHP parses the literal in `LocalSettings.php` back to exactly the secret file's
  bytes — written as a single-quoted literal so a `$` or a backslash in the value
  cannot be interpolated;
* a send through MediaWiki's own `UserMailer` reached the mailbox
  (`status=sent` for `jeff@jeffemmett.com` in the postfix log).

The password was moved by a server-side script that read
`/opt/secrets/mailcow/p2pwiki_noreply_smtp_password` and wrote it straight back
out, reporting only sha256 prefixes. A credential that reaches a transcript has
to be rotated; one that never leaves the host does not.

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
