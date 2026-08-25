# Backups for the P2P Foundation estate

Two hosts, two different jobs, and one failure mode that has now happened twice.

## Where the live data is

The estate failed over to GX10 when Netcup died on 2026-08-17, and **failed back
to Netcup on 2026-08-24** (`dev-ops/gx10/netcup-return/netcup-return.sh`, cron
every 5 min). Netcup is authoritative again:

| Service | Live host | GX10 copy |
|---|---|---|
| `p2pwiki` + `p2pwiki-db` | Netcup | standby, frozen 2026-08-24 |
| `p2p-web` / `p2p-blog` (`p2p-db`) | Netcup | running, **zero traffic** |
| mailcow | Netcup | stopped 2026-08-24 13:26 |

Confirm which host is actually serving before trusting any backup — a standby
that is still *running* looks identical to a live service:

```bash
ssh netcup-full 'docker logs --since 15m p2p-web 2>&1 | wc -l'   # hundreds
ssh gx10        'docker logs --since 15m p2p-web 2>&1 | wc -l'   # 0
```

## The `_FILE` password trap (2026-08-25, second occurrence)

`/opt/backup-system/backup-docker.sh` on Netcup dumped MariaDB by reading the
root password out of the container with `printenv MYSQL_ROOT_PASSWORD`, and
skipped the container when that came back empty:

```
SKIP: p2pwiki-db (no root password found)
SKIP: p2p-db (no root password found)
```

Every container migrated to docker/Infisical secrets sets the **`_FILE` variant**
(`MYSQL_ROOT_PASSWORD_FILE`) instead, pointing at a mounted secret. The plain
variable is unset, so the lookup returned empty — and the same script *excludes
those volumes from restic* on the assumption the dump covers them:

```
--exclude="/var/lib/docker/volumes/p2pwiki_p2pwiki-db-data"
--exclude="/var/lib/docker/volumes/p2pfoundation_db_data"
```

Dump skipped + volume excluded = **no backup at all**. `p2p-db` (756 MB) and
`p2pwiki-db` (2.6 GB — the 45k-page wiki, 150,071 edits) were unprotected from
the `_FILE` migration until 2026-08-25.

It is silent by construction. A SKIP is not an error, the run still logs
`All backup tasks completed successfully`, and `backup-healthcheck.sh` only
spot-checked `mailcowdockerized-mysql-mailcow-1` and `gitea-db` — so nothing ever
named the two databases that were missing.

**This is the same fault as 2026-06-29 on the old host** (see memory
`p2pfoundation-gx10-rebuild-and-db-backup-hole`). It was fixed there, and the fix
did not travel with the rebuild.

### The fix

Resolve the password *inside* the container, accept both the plain and `_FILE`
forms, and hand it to the dump through the `MYSQL_PWD` environment variable so it
never reaches the host's argv, this script, or any log:

```
sh -c '
    pw="${MYSQL_ROOT_PASSWORD:-${MARIADB_ROOT_PASSWORD:-}}"
    [ -z "$pw" ] && [ -n "${MYSQL_ROOT_PASSWORD_FILE:-}" ] && pw=$(cat "$MYSQL_ROOT_PASSWORD_FILE")
    [ -n "$pw" ] || exit 9
    export MYSQL_PWD="$pw"
    exec "$1" --all-databases -u root
  ' _ "$dump_cmd"
```

The presence check runs the same resolution and discards the result, so a
container with no usable password still logs a real SKIP rather than producing a
truncated dump.

`backup-healthcheck.sh` now also spot-checks `p2p-db` and `p2pwiki-db`.
Originals kept as `*.bak-20260825T092502Z`.

### Verifying, and the only check worth running

**Count the dumps, do not trust the exit code.** The run reports success either
way; what distinguishes a working backup from a skipped one is a file with a
plausible size:

```bash
ssh netcup-full 'ls -la /var/backups/db-dumps/ | grep -E "p2p-db|p2pwiki-db"'
# p2p-db.sql       791838808   (756M)
# p2pwiki-db.sql  2707448524   (2.6G)
```

A dump that shrinks by an order of magnitude is the same failure wearing a
different mask — the earlier gap showed up as a `243K` `p2p_web` dump that looked
reasonable next to nothing.

## `backup-p2pfoundation.sh` (GX10) now backs up a standby

The script in this directory runs nightly on GX10 at 01:30. Since the failback it
dumps the **frozen** copy, so its output is a snapshot of 2026-08-24 that will
never advance while Netcup is live. It is not wrong, but it is not protection,
and its sizes are close enough to the real ones to be mistaken for it.

Netcup's own `backup-docker.sh` is the real coverage. Gate this one on being the
active host before relying on it again.
