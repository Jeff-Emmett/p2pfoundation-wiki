# GX10 host configuration

Two root-owned files that the estate depends on and that nothing else in this
repo can install, because both need `sudo`.

**`daemon.json` carries no comments, deliberately.** dockerd validates strictly
and rejects any key it does not recognise:

```
unable to configure the Docker daemon with file /etc/docker/daemon.json:
the following directives don't match any configuration option: _comment
```

On a host with 350 containers, a daemon that will not start is a total outage,
so the explanation lives here instead of in the file. **Always run
`dockerd --validate --config-file <file>` before installing a change** — it
prints `configuration OK` or names the offending key, and it costs nothing.

---

## `dbus-max-connections.conf` → `/etc/dbus-1/system.d/50-docker-max-connections.conf`

Raises `max_connections_per_user` from dbus's built-in default of **256** to
**2048**.

Docker asks systemd to create one transient scope unit per container, over dbus,
as root. **This host runs ~352 containers against a cap of 256.** The cap is
below the working set, so the machine lives permanently at the edge of it and any
burst of container churn exhausts the pool. systemd is then left holding
half-registered scope units, and from that moment *every* container start fails:

```
unable to apply cgroup configuration: unable to start unit
"docker-<id>.scope" ... was already loaded or has a fragment file
```

That error names cgroups and is not about cgroups. The real line is in the
journal:

```
dbus-daemon: The maximum number of active connections for UID 0 has been
reached (max_connections_per_user=256)
```

This happened on 2026-08-22 and took the entire estate down — traefik, every
cloudflared tunnel, the wiki, p2pfoundation.net. **It is invisible until it is
total**, because containers already running are unaffected; nothing looks wrong
until something restarts, and then nothing can start at all.

Note the default is not written down anywhere on the host: the line in
`/usr/share/dbus-1/system.conf` is *commented out*, so 256 is a compiled-in
default and greppping for it finds nothing.

2048 is chosen to sit far above the container count rather than just above it,
so that adding a few services does not quietly walk back to the same cliff.

**Recovery, if it happens again before this is installed:**

```bash
sudo systemctl daemon-reexec     # clears systemd's stale transient scope units
sudo systemctl restart docker
```

Containers carry `restart: unless-stopped` and come back on their own. The
cloudflared tunnels take several minutes to reconnect, during which public
hostnames return **530** — that is the recovery in progress, not a second fault.

### Why not switch docker's cgroup driver instead

`native.cgroupdriver=cgroupfs` would stop docker creating systemd scopes at all,
which removes the dbus traffic entirely. It is the wrong trade here: this host is
cgroup v2, where the systemd driver is the supported configuration, and changing
drivers rewrites how every container's resources are accounted. Raising a limit
that is merely mis-sized for the workload is the smaller change.

---

## `daemon.json` → `/etc/docker/daemon.json`

Adds log rotation. Everything else in the file is the running configuration,
unchanged, and must stay: the `nvidia` runtime is what gives containers the GPU,
and the address pool exists because this host previously exhausted docker's
defaults — that failure surfaces as `all predefined address pools have been fully
subnetted` and reads like a compose error rather than an allocation one.

Container logs here are currently **uncapped**. `/var/lib/docker/containers`
holds ~4 GB of `json.log` with one container alone at 1.4 GB, and nothing ever
truncates them — the json-file driver writes until the disk fills. On a host
already at 94% used, that is a slow leak aimed at the failure mode that took
Netcup down twice in July.

`50m` x `3` per container bounds the worst case at roughly 50 GB fleet-wide
instead of unbounded, while leaving enough history to debug with.

**This does not shrink the logs that already exist** — the setting applies as
files are next written. Reclaim the current 4 GB separately.

### Installing

```bash
# validate BEFORE replacing the live file — a bad daemon.json means no docker
sudo dockerd --validate --config-file ./daemon.json

sudo cp /etc/docker/daemon.json /etc/docker/daemon.json.bak-$(date -u +%Y%m%dT%H%M%SZ)
sudo install -m 644 ./daemon.json /etc/docker/daemon.json
sudo install -m 644 ./dbus-max-connections.conf \
     /etc/dbus-1/system.d/50-docker-max-connections.conf
```

Both take effect on restart of their daemon. Do the dbus one first: restarting
docker is exactly the container-churn event that trips the old limit, so raising
the cap before restarting docker is the difference between a maintenance window
and a repeat of 2026-08-22.
