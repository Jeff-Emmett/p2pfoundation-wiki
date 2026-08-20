# p2pwiki read-only standby — GX10

A second copy of the wiki on our own metal, so a Netcup outage does not take the
wiki off the internet.

**Status: running on GX10, not yet publicly routable.** It serves correctly on
`127.0.0.1:18081`. Publishing it needs a Cloudflare tunnel credential — see
[What is still blocked](#what-is-still-blocked).

---

## Why

2026-08-17: the Netcup host went off the network at layer 3 and every site on it
went dark, the wiki included. The wiki itself was fine. A standby on separate
hardware, in a separate building, on a separate uplink, is the difference between
"the wiki is down" and "the wiki is read-only for a while".

Traffic reaches it only when the primary is unreachable. The edge Worker in
`../edge-fallback` makes that decision — it tries the Netcup origin first and
falls through to this box only on a Cloudflare-generated origin failure.

---

## What runs here

| | |
|---|---|
| Host | GX10 / `spark` (aarch64), `~/p2pwiki-standby` |
| Images | `mediawiki:1.40`, `mariadb:10.11` — identical tags to production |
| Local port | `127.0.0.1:18081` (8081 was taken) |
| Content | 41k+ pages from the 2026-08-04 XML dump |
| Writes | Refused. `$wgReadOnly`, plus group permissions, plus uploads off |

`~/p2pwiki-standby` rather than `/opt/websites/p2pwiki` because the deploy user
has no write access to `/opt` and this needed no sudo. Move it if you want the
paths to match; nothing depends on the location.

---

## How it differs from production, and why

Every difference is deliberate and marked `DELTA` in `docker-compose.yml`. The
short version:

| Delta | Reason |
|---|---|
| No Traefik labels | GX10 runs no Traefik. Publication is a cloudflared tunnel |
| Secrets in `.env`, not `/opt/infisical/secret-files/*` | **Infisical is on the host this standby exists to survive.** It cannot be the source of the standby's own credentials |
| No Elasticsearch | CirrusSearch is configured in `LocalSettings.php`, which is not available. An ES container would burn 3 GB serving nothing. Add it back *with* the real config |
| No dumps nginx | The standby consumes dumps; it does not publish them |
| `$wgReadOnly` set | An edit accepted here is lost at failback. Refusing is the kinder failure |
| `short-urls.conf` instead of `.htaccess` | The production `.htaccess` is not in the repo. Server config beats a per-directory override anyway |

### What is NOT yet identical

Four things live only on the Netcup host and cannot be copied while it is down.
This is the honest gap, and it closes with four file copies once Netcup returns:

| Missing | Where it lives | Effect today |
|---|---|---|
| `LocalSettings.php` | `/opt/websites/p2pwiki/` | Extensions, skin config, CirrusSearch, permissions all absent. A generated config stands in |
| `p2pwiki-extensions` volume | Docker volume on Netcup | No custom extensions |
| Images (~1.7 GB) | monthly tar at `/dumps/` | Pages render without pictures |
| Current DB | restic repo on Netcup's own storage | Content is as of 2026-08-04 |

The 2026-08-16 `db-dumps` on the Hetzner storage box were checked and do **not**
include p2pwiki — it is covered by restic to a repository on Netcup's own
storage, which is unreachable for the same reason everything else is. Worth
fixing on its own merits: a backup you cannot reach during an outage is a backup
with a precondition nobody wrote down.

---

## Operating it

```bash
ssh spark
cd ~/p2pwiki-standby

docker compose ps
docker compose up -d
docker compose logs -f p2pwiki-standby

# Production sends Host: wiki.p2pfoundation.net, so test with it —
# $wgServer is the production hostname and links are generated from it.
curl -sS -H "Host: wiki.p2pfoundation.net" http://127.0.0.1:18081/Main_Page | head
```

### Refreshing the content

```bash
# 1. fetch the current dump (only possible while Netcup is UP)
curl -o p2pwiki-dump.xml.bz2 https://wiki.p2pfoundation.net/dumps/p2pwiki-latest-current.xml.bz2
# 2. reload
rm -f dumps/import.xml && ./import-dump.sh
```

`dump-wiki.sh` writes a fresh current-revisions XML every Sunday 04:00 on Netcup.
A weekly pull from GX10 keeps the standby within a week of production. **You
cannot snapshot a dead host**, so the standby's freshness is always capped by the
last successful pull — which is the argument for pulling on a schedule rather
than remembering to.

### The read-only trap, recorded because it will catch the next person

`$wgReadOnly` blocks **every** write, including maintenance scripts, so
`importDump.php` fails with *"Wiki is in read-only mode; you'll need to disable
it for import to work."* The fix is at the bottom of `LocalSettings.php`:

```php
if ( PHP_SAPI === 'cli' ) { $wgReadOnly = false; }
```

Web stays read-only; CLI can write. It must come **after** the `$wgReadOnly`
assignment — `LocalSettings.php` is read top to bottom and the last one wins.

### Link tables

The import runs with `--no-updates`, so category listings and "what links here"
are empty until:

```bash
docker exec -d p2pwiki-standby php /var/www/html/maintenance/rebuildall.php
```

Hours on this corpus. Page text renders correctly without it — this is a quality
improvement to a fallback, not a prerequisite for one.

---

## What is still blocked

**Public routing.** The standby answers only on localhost. To publish it:

1. Create a tunnel and its DNS record (needs Cloudflare auth — the local
   `cloudflared` origin cert returns `unauthorized`, and GX10's existing tunnels
   are `--token`/remotely-managed so their ingress cannot be edited from the host):
   ```bash
   cloudflared tunnel login
   cloudflared tunnel create p2pwiki-standby
   cloudflared tunnel route dns p2pwiki-standby wiki-standby.p2pfoundation.net
   ```
2. Drop the credentials JSON at `~/p2pwiki-standby/tunnel-credentials.json`,
   write `cloudflared-config.yml` with `service: http://p2pwiki-standby:80`,
   uncomment the `cloudflared` service, `docker compose up -d`.
3. Point the Worker at it and deploy:
   ```bash
   cd ../edge-fallback
   wrangler deploy --var STANDBY_ORIGIN:https://wiki-standby.p2pfoundation.net
   ```

Until step 3, the Worker's standby rung is inert (`STANDBY_ORIGIN` empty) and it
falls through to the R2 snapshot and offline page as before.

---

## Editing, and how edits get back to Netcup

The standby is **writable** as of `standby-writable-since.txt`. Say plainly what
that does and does not mean:

> There is no shared datastore. Netcup is unreachable, so its MySQL and this one
> cannot be "the same store" in any sense. What exists instead is a
> one-directional merge that is tractable **because only one side is alive** —
> Netcup gains no edits while it is off, so nothing has to be reconciled in the
> other direction.

```
standby edits ──export──> conflict check ──import──> Netcup
```

| Script | Does |
|---|---|
| `enable-editing.sh` | Opens the window, records the boundary, creates accounts |
| `export-standby-edits.sh` | Exports every page edited since the boundary, full history |
| `merge-to-netcup.sh --check` | Compares against Netcup, splits SAFE vs CONFLICT, changes nothing |
| `merge-to-netcup.sh --apply-safe` | Imports only the safe set |
| `verify-edit-roundtrip.sh` | Proves the whole chain end to end through the public URL |

### The gap, which is the whole risk

| | |
|---|---|
| This copy's content | Netcup as of **2026-07-31 12:46:47Z** |
| Netcup's DB on return | its state at **2026-08-17 06:21Z** |
| Unseen Netcup edits | **16 days**, ~150 revisions over perhaps 60–100 pages of 40,647 |

If a page is edited *here* that also changed *there* in those 16 days, importing
our version makes our older-derived text current and the Netcup change stops
being the live text. It survives in history and is recoverable, but the article
visibly regresses. `merge-to-netcup.sh` detects exactly these and **refuses to
merge them** — there is no `--apply-all`, deliberately.

⚠️ **The watermark is recorded, never derived.** `import-watermark.txt` holds
`20260731124647`, taken from the dump's newest revision. Do not compute it as
`MAX(rev_timestamp)` over the local database: `install.php` writes its own
Main_Page revision when the wiki is created, which made the local maximum
`20260817101902` — *after* Netcup had already died at 06:21Z. A watermark of
that value asks Netcup for changes made after it was dead, gets none, and
declares every page conflict-free. The detector would be permanently, silently
green. The export script now refuses to run without the recorded file.

Note also that the dump's *filename* says 2026-08-04 while its newest revision
is 2026-07-31 — the filename records when it was generated, not what is in it.
That is why the gap is 16 days and not 13.

### Deliberate restrictions while the window is open

- **Login required, no anonymous editing.** Production denies anon edits, and
  anything written here is destined to be replayed into production — an open
  wiki collects spam within hours, and that spam would arrive in Netcup wearing
  the authority of a merge.
- **Accounts use Netcup's exact usernames** (`Mbauwens`, `JeffEmmett`, …).
  Revision attribution is matched on the username string at import, so
  `mbauwens` would merge as a different person and split one editor's history.
  Passwords are freshly generated here — the user table never left Netcup — and
  are in `standby-accounts.txt` (0600).
- **Uploads off.** Files do not travel in an XML export; an upload would merge
  as a redlink with the file stranded here.
- **Moves and deletions are sysop-only.** They do not propagate through an XML
  export either and must be replayed by hand.

## Limitations

1. **Read-only, always.** Not a failover you can write to; a failback would lose
   the edits.
2. **GX10 is a home box** on a residential uplink, currently at 90/121 GB RAM
   with swap in use. Its availability is below Cloudflare's edge, which is why
   the Worker keeps the R2 snapshot underneath it rather than treating this as
   the last word.
3. **Stale by up to a week**, more if a pull is missed.
4. **No images, no custom extensions, generated config** until Netcup returns.
5. **Credentials are local to the box**, not in Infisical, and deliberately so
   while Infisical lives on the host this is meant to survive.

## Gap import — executed 2026-08-20

`import-gap-2026-08.sh` ran on GX10 against `p2pwiki-standby`, reached over the
`gx10-ssh2` cloudflared door rather than the mesh (Headscale was still down for
this machine; see memory `cloudflared-ssh-doors`). Result:

- 163/163 pages verified **through the public API**, not from inside the
  container: `ok=163 missing=0 stale=0`. Every live revision is at or newer than
  the delta's.
- Attribution landed on the real accounts — `user=Mbauwens`, not
  `imported>Mbauwens` — which is what dropping `--no-local-users` was for.
- `Special:Statistics` now reads pages=45559 articles=15309 edits=46838
  **users=2283** images=1209. It read 6 users before step 4.
- Pre-import database snapshot kept at
  `~/p2pwiki-standby/dumps/pre-gap-import/p2pwiki-20260820T145757Z.sql.gz`
  (139 MB) on GX10.

The dry run reported 163 pages/163 revisions and the real run imported the same
count with no errors. Re-running is harmless; importDump skips revisions the
wiki already has.
