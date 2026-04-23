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

## Related P2P Foundation deployments (not in this repo)

| Project | Netcup dir | Repo |
|---------|-----------|------|
| French wiki | `/opt/websites/p2pwikifr/` | *(not extracted)* |
| WordPress blogs (`p2pfoundation.net`, `blog.`, `bloggr.`, `blogfr.`, `blognl.`) | `/opt/p2pfoundation/` | *(not extracted)* |
| AI chat backend | `/opt/apps/p2pwiki-ai/` | [`p2pwiki-ai`](https://gitea.jeffemmett.com/jeffemmett/p2pwiki-ai) |
