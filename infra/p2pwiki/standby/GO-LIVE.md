# Going live — p2pwiki standby on GX10

Everything up to this point is done: the standby runs on GX10 and serves 45,458
pages on `127.0.0.1:18081`. What remains is publishing it and telling the edge
Worker it exists.

**Only steps 1 and 6 need you specifically** — they are browser logins. The rest
is copy-paste, and an agent can run them once those two are done.

Total time: about 15 minutes.

---

## Step 1 — Cloudflare login (you, browser)

```bash
cloudflared tunnel login
```

A browser opens. **Select the `p2pfoundation.net` zone**, not another one — the
certificate this writes is scoped to whatever you pick, and picking the wrong
zone fails later at step 3 with a permissions error rather than here.

Writes `~/.cloudflared/cert.pem`. (The existing one at that path is from
2026-01-02 and returns `unauthorized`; this replaces it.)

---

## Step 2 — Create the tunnel

```bash
cloudflared tunnel create p2pwiki-standby
```

Prints a UUID and writes `~/.cloudflared/<UUID>.json`. **Keep that file secret** —
it is the tunnel's credential. Note the UUID; the next steps need it.

```bash
cloudflared tunnel list          # confirm it exists
```

---

## Step 3 — Point DNS at it

```bash
cloudflared tunnel route dns p2pwiki-standby wiki-standby.p2pfoundation.net
```

Creates a proxied CNAME `wiki-standby.p2pfoundation.net → <UUID>.cfargotunnel.com`.

Verify:

```bash
dig +short wiki-standby.p2pfoundation.net    # Cloudflare edge IPs
```

---

## Step 4 — Install the connector on GX10

```bash
UUID=<paste-the-uuid-from-step-2>

scp ~/.cloudflared/$UUID.json spark:~/p2pwiki-standby/tunnel-credentials.json
ssh spark 'chmod 600 ~/p2pwiki-standby/tunnel-credentials.json'

ssh spark "cd ~/p2pwiki-standby && \
  sed 's/TUNNEL-UUID-HERE/$UUID/' cloudflared-config.yml.template > cloudflared-config.yml && \
  grep '^tunnel:' cloudflared-config.yml"
```

Then uncomment the `cloudflared` service block at the bottom of
`~/p2pwiki-standby/docker-compose.yml` (it is already written, just commented),
and start it:

```bash
ssh spark 'cd ~/p2pwiki-standby && docker compose up -d cloudflared'
ssh spark 'docker logs --tail 20 cloudflared-p2pwiki-standby'
```

Healthy output contains `Registered tunnel connection` — usually four of them.

---

## Step 5 — Verify the standby is publicly reachable

```bash
curl -sS -o /dev/null -w "%{http_code}\n" https://wiki-standby.p2pfoundation.net/Peer_to_Peer
```

Expect **200**. If you get 1033, the connector is not registered — go back to
step 4's log check.

Sanity-check that it is the standby and not something else:

```bash
curl -sS https://wiki-standby.p2pfoundation.net/Peer_to_Peer | grep -o '<title>[^<]*</title>'
# <title>Peer to Peer - P2P Foundation Wiki</title>
```

---

## Step 6 — Wrangler login (you, browser)

```bash
cd ~/Github/p2pfoundation-wiki/infra/p2pwiki/edge-fallback
wrangler login
```

---

## Step 7 — Point the Worker at the standby

Edit `wrangler.toml` and set:

```toml
STANDBY_ORIGIN = "https://wiki-standby.p2pfoundation.net"
```

Set it in the file rather than passing `--var` on the command line, so the
configuration is in git and the next person can see what is deployed.

Then:

```bash
npm test                 # 10/10 before you ship anything
wrangler deploy
```

`wrangler deploy` publishes the Worker **and** attaches the route
`wiki.p2pfoundation.net/*`.

---

## Step 8 — Verify the failover end to end

While Netcup is down, the fallback path is the live path:

```bash
curl -sS -D- -o /dev/null https://wiki.p2pfoundation.net/Peer_to_Peer | \
  grep -iE "^HTTP|x-p2pwiki-fallback"
```

Expect `x-p2pwiki-fallback: standby`. Open it in a browser — you should see the
article with a banner saying the main server is unavailable and editing is off.

**When Netcup comes back**, re-run the same command. The header should be
**absent** — meaning traffic is going to the real origin and the Worker is
staying out of the way. That is the check that matters most, and it is the one
that cannot be run today, so run it then.

---

## Rollback

Any of these, in increasing order of severity:

```bash
# Worker becomes a transparent pass-through, route stays attached
wrangler deploy --var FALLBACK_ENABLED:off

# Drop just the standby rung, keep snapshot + offline page
wrangler deploy --var STANDBY_ORIGIN:

# Remove the Worker entirely; traffic goes straight to origin
wrangler delete

# Stop publishing the standby
ssh spark 'cd ~/p2pwiki-standby && docker compose stop cloudflared'
```

The Worker also self-disarms: any unexpected error inside the fallback returns
the original origin response rather than failing the request.

---

## Two things to watch

**Workers free tier is 100k requests/day**, and the Worker runs on *every*
request to the wiki, not just failures. Check Cloudflare Analytics for the wiki's
actual traffic. Above that, Workers Paid is $5/mo.

**`wiki-standby.p2pfoundation.net` is publicly reachable** once step 3 lands.
That is required — Cloudflare Access in front of it would block the Worker's own
subrequest. It is mitigated rather than hidden: the standby sets
`noindex,nofollow`, refuses all edits, disables account creation and uploads, and
sends no mail. Do not put anything on that hostname that is not already public on
the wiki.

---

## Still outstanding after this

Going live does not make the standby *identical* to production. Four things live
only on the Netcup host and need copying once it is back — see the table in
`README.md`: `LocalSettings.php`, the extensions volume, the ~1.7 GB images tar,
and a current DB. Until then the standby runs a generated config with no custom
extensions, no CirrusSearch and no images.
