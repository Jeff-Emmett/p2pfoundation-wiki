# Going live — p2pwiki standby on GX10

Everything up to this point is done: the standby runs on GX10 and serves 45,458
pages on `127.0.0.1:18081`. What remains is publishing it and telling the edge
Worker it exists.

**Only steps 1 and 6 need you specifically** — they are browser logins. The rest
is copy-paste, and an agent can run them once those two are done.

Total time: about 15 minutes.

---

## Why the CLI route fails before you start

`~/.cloudflared/cert.pem` on the WSL box is **truncated**: 266 bytes containing
only the `ARGO TUNNEL TOKEN` block, with no `PRIVATE KEY` and no `CERTIFICATE`.
A healthy one is 1–2 KB with all three. Every API call made with it returns
`unauthorized`, and `cloudflared tunnel create` fails with:

```
failed to create tunnel: Create Tunnel API call failed:
Failed to create tunnel: code: 10000, reason: Authentication error
```

Same failure shape as the 2026-07-01 Netcup `cloudflare.env` truncation: a
credential file silently emptied by something, discovered only when next used.

Two ways forward. **Path A avoids the cert entirely and is fewer steps.**

---

## Path A (recommended) — dashboard tunnel, token only

No origin cert, no credentials file, no `config.yml`, no DNS command. Cloudflare
creates the DNS record for you.

### 1. Create the tunnel

Go to **one.dash.cloudflare.com** → **Networks** → **Tunnels** →
**Create a tunnel** → **Cloudflared**.

Name it `p2pwiki-standby` and save.

⚠️ Check the **account selector** at the top first. The tunnel must be created in
the account that holds `p2pfoundation.net` — the same account as the existing
`cloudflared-erpnext` / `cloudflared-immich` tunnels
(`0e7b3338d5278ed1b148e6456b940913`). Wrong account, and step 3 will not offer
the domain.

### 2. Copy the token

The install screen shows a command like
`cloudflared service install eyJhIjoi…`. You want **only the long token string**,
not the whole command. It is a secret — treat it like a password.

Put it on GX10 without it passing through anyone's clipboard history twice:

```bash
ssh spark
cd ~/p2pwiki-standby
read -rs TOKEN && echo "TUNNEL_TOKEN=$TOKEN" >> .env && unset TOKEN
# paste the token, press Enter — it will not echo
grep -c '^TUNNEL_TOKEN=' .env    # expect 1
```

### 3. Add the public hostname

On the tunnel's page, open the **Public Hostname** tab → **Add a public
hostname**:

| Field | Value |
|---|---|
| Subdomain | `wiki-standby` |
| Domain | `p2pfoundation.net` |
| Type | `HTTP` |
| URL | `p2pwiki-standby:80` |

The URL is the **container name and container port** — not `localhost`, not
`18081`. The connector runs inside the compose network and resolves
`p2pwiki-standby` by Docker DNS; the host port mapping is irrelevant to it.

Saving this auto-creates the proxied CNAME to `<UUID>.cfargotunnel.com`. No
`cloudflared tunnel route dns` needed.

### 4. Start the connector

Uncomment the **OPTION A** `cloudflared` block in
`~/p2pwiki-standby/docker-compose.yml`, then:

```bash
ssh spark 'cd ~/p2pwiki-standby && docker compose up -d cloudflared'
ssh spark 'docker logs --tail 20 cloudflared-p2pwiki-standby'
```

Healthy output contains `Registered tunnel connection` — usually four. The
dashboard should also flip the tunnel to **HEALTHY**.

Now skip to step 5.

---

## Path B — CLI tunnel (only if you want routing in git)

Requires repairing the cert first.

```bash
# 1. keep the broken one, in case it explains what truncated it
cp ~/.cloudflared/cert.pem ~/.cloudflared/cert.pem.truncated-20260817

# 2. re-authenticate
cloudflared tunnel login
```

`wslview` is installed, so this should open the Windows browser by itself. If it
does not, copy the printed URL manually — the command waits for the callback
either way.

In the browser: pick the **account** that holds `p2pfoundation.net`, then pick
the **`p2pfoundation.net` zone**. The cert is scoped to the zone you choose, and
choosing wrong does not fail here — it fails two steps later with a confusing
permissions error.

```bash
# 3. verify the repair BEFORE going further
wc -c < ~/.cloudflared/cert.pem                       # expect 1000-2000, not 266
grep -c 'BEGIN' ~/.cloudflared/cert.pem               # expect 3
cloudflared tunnel list                               # must NOT say unauthorized
```

If `wc -c` still shows a few hundred bytes, stop — the login did not take, and
every following step will fail with the same `code: 10000`.

```bash
# 4. create the tunnel and its DNS record
cloudflared tunnel create p2pwiki-standby
cloudflared tunnel route dns p2pwiki-standby wiki-standby.p2pfoundation.net
dig +short wiki-standby.p2pfoundation.net             # Cloudflare edge IPs

# 5. install the connector on GX10
UUID=<paste-the-uuid-from-step-4>
scp ~/.cloudflared/$UUID.json spark:~/p2pwiki-standby/tunnel-credentials.json
ssh spark 'chmod 600 ~/p2pwiki-standby/tunnel-credentials.json'
ssh spark "cd ~/p2pwiki-standby && \
  sed 's/TUNNEL-UUID-HERE/$UUID/' cloudflared-config.yml.template > cloudflared-config.yml"
```

Uncomment the **OPTION B** `cloudflared` block, then:

```bash
ssh spark 'cd ~/p2pwiki-standby && docker compose up -d cloudflared'
ssh spark 'docker logs --tail 20 cloudflared-p2pwiki-standby'
```

---

## Step 5 — Verify the standby is publicly reachable (both paths)

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
