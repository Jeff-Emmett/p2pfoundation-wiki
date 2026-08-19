# p2pfoundation.net on GX10 — the Netcup rebuild

Netcup went off the network at 2026-08-17 06:21Z and has not come back
(layer-3 dead on every path: tailnet, public :443/:22/:2222, ICMP). Everything
here is the p2pfoundation estate rebuilt on GX10 from the R2 restic backups,
2026-08-19.

Deployed from `~/apps/*` on GX10, not from this directory. These files are the
record of what was deployed and why it differs from netcup's originals.

## What is running

| Hostname | Stack | Where on GX10 | Data |
|---|---|---|---|
| `p2pfoundation.net`, `www.` | WordPress `p2p-web` | `~/apps/p2pfoundation-refugee` | files 08-17, **DB 06-29** |
| `blog.p2pfoundation.net` | WordPress `p2p-blog` | same stack | files 08-17, **DB 06-29** |
| `newsletter.p2pfoundation.net` | listmonk | `~/apps/p2p-newsletter` | DB 08-17 (current) |
| `translate.p2pfoundation.net` | translation-cache | `~/apps/translation-cache` | stateless + redis |
| `socials.p2pfoundation.net` | postiz | `~/apps/postiz-p2pfoundation` | DB 08-17 (current) |
| `blogfr/bloggr/blognl` | traefik 308 redirects | `~/apps/traefik/config` | n/a — decommissioned 2026-05-09 |
| `wikifr` | traefik 301 redirect | same | n/a — deprecated 2026-05-09 |
| `wiki.p2pfoundation.net` | MediaWiki standby | `~/p2pwiki-standby` | separate; see `infra/p2pwiki` |

`forum.p2pfoundation.net` and `ai.p2pfoundation.net` are NOT here. Both have a
cloudflared ingress rule pointing at netcup's traefik, but no compose file
anywhere in `/opt`, no traefik router, no docker volume, and no Wayback capture.
They had no backing service on netcup either — they would have answered 404
there too. There is nothing to restore, only a DNS/ingress entry to retire.

## The 7-week hole in the WordPress database

**The MariaDB behind both WordPress sites has no backup after 2026-06-29.** The
newest `p2p-db.sql` in R2 is snapshot `c5790d62`, and that is what is restored.

Two safeguards, each defensible alone, cancelled each other out:

1. `backup-docker.sh` dumps MariaDB by reading `$MYSQL_ROOT_PASSWORD` out of the
   container environment. This stack was migrated to Infisical's
   `MYSQL_ROOT_PASSWORD_FILE` pattern, so `printenv MYSQL_ROOT_PASSWORD` returned
   empty and the script logged `SKIP: p2p-db (no root password found)` — a skip,
   not a failure, so nothing alerted.
2. `--exclude=/var/lib/docker/volumes/p2pfoundation_db_data` was in the restic
   command *because* the logical dump was believed to cover it.

The same pairing applies to `p2pwiki_p2pwiki-db-data`; the wiki survived only
because it is separately rebuildable from the XML dump.

What this costs: posts, edits and comments between 2026-06-29 and 2026-08-17.
Uploaded media from that window **is** present — it lives in the file volumes,
which were backed up nightly. Measured after restore: 14,375 published posts,
newest `post_type=post` is 2020-10-16, so the visible content gap is small.

If Netcup ever returns, **its database is authoritative and newer.** Reconcile
toward it. Do not push this copy over it.

## Fixes the restore itself needed

- **`**/cache/**` is in restic's exclude list**, and it matched
  `wp-content/plugins/polylang/src/integrations/cache/` — real plugin code, not a
  cache. Two missing files fatal'd every blog request. Refetched from
  wordpress.org (Polylang 3.8.3, the installed version) and diffed against the
  restore: exactly those two files were missing.
- **The blog cached its own error page.** `advanced-cache.php` stores any
  response over 1 KB containing `</html>` for 30 minutes and serves it before
  WordPress loads. While the DB secret was briefly unreadable it banked 295
  copies of "Error establishing a database connection" and kept serving them
  after the fix. Cleared via `wp-content/cache/{simple,pages}`.
- **Secret files need an owner the container can read.** restic restored them as
  uid 1000; PHP runs as `www-data` (33), and listmonk drops `cap_drop: ALL`,
  which removes `CAP_DAC_OVERRIDE` so even its root cannot bypass the mode bits.
  WordPress secrets are `0:33 640`, listmonk's are `0:0 600`.
- **Every default docker address pool on GX10 is consumed** (172.17–172.31/16 and
  192.168.16–240 in /20s, across 34 networks), so an unpinned bridge network
  fails outright. Every network here pins an explicit subnet in the
  192.168.208–221 gap. Widening `default-address-pools` in `daemon.json` is the
  real fix and needs a docker restart — not during an outage.
- **The refugee shim only listened on :80.** Most netcup ingress rules say
  `http://localhost:80`, but `newsletter` and `translate` say
  `https://localhost:443`. Those two answered Cloudflare 502 while every
  neighbour worked. `~/apps/netcup-refugee/refugee-nginx.conf` gained a `stream`
  block passing :443 through to traefik:443 — TCP passthrough so SNI still picks
  the router. Applied with `nginx -s reload`, so no tunnel was restarted.

- **Temporal's Postgres password was never what the compose said it was.**
  `postiz-p2pf-temporal` connects with a literal `POSTGRES_PWD=temporal`, while
  the shared Postgres takes `${TEMPORAL_POSTGRES_PASSWORD}` from its `.env`.
  These disagreed on netcup too — it worked only because `POSTGRES_PASSWORD`
  applies at volume initialisation and netcup's volume predated the `.env` value,
  so the old literal was still the live password. A fresh volume here honours the
  `.env`, and temporal crashlooped on `password authentication failed`. Fixed
  toward the stronger secret: postiz's temporal now reads the same
  `TEMPORAL_POSTGRES_PASSWORD`, appended to its `.env` without the value ever
  being displayed.

## Related

The wiki's own outage the same day has a different cause and is written up in
the commit for `infra/p2pwiki/edge-fallback` — reviving the dead netcup tunnel is
what broke it.
