# Recovering the missing content from Netcup's disk

The standby is frozen at **2026-07-31 12:46**. The missing 16 days exist only on
the Netcup host, which has been off the network since 2026-08-17 06:21Z.

This gets them back **without needing the guest to boot**.

---

## The prize, and why it is not the database

`dump-wiki.sh` runs from root's crontab at `0 4 * * 0` — Sundays 04:00. The last
Sunday before the outage was **2026-08-16**, so the weekly dump ran **26 hours
before the host died**:

```
/opt/websites/p2pwiki/dumps/p2pwiki-2026-08-16-current.xml.bz2
```

That is a clean, internally consistent MediaWiki XML export. Importing it closes
the content gap from 16 days to roughly one.

Copying the raw MariaDB data directory would also work, but it is strictly worse:
the files come from an instance that was never shut down cleanly, so they need
InnoDB crash recovery in a scratch server before they can be read, and a
half-written page is a real possibility. Take the dump. The database directory is
the fallback if the dump is missing or truncated.

Also worth taking while you are in there — this closes the remaining parity gaps:

| Path | Why |
|---|---|
| `/opt/websites/p2pwiki/dumps/p2pwiki-2026-08-16-current.xml.bz2` | **the point of the exercise** |
| `/opt/websites/p2pwiki/dumps/p2pwiki-*-images.tar` | all 1,248 images, including the 21 the Archive never had |
| `/opt/websites/p2pwiki/LocalSettings.php` | the real config; ours is reconstructed from an archived Special:Version |
| `/opt/websites/p2pwiki/.htaccess` | the real short-URL rules |
| `/var/lib/docker/volumes/p2pwiki_p2pwiki-db-data/_data` | fallback only, if the dump is unusable |

⚠️ **Do NOT copy `/var/lib/docker/volumes/p2pwiki_p2pwiki-extensions/_data`.**
That is executable code from a host with a confirmed intrusion (TASK-158, ~929
executions of an attacker-supplied payload). Extensions are already installed
here from upstream git at the exact commit Netcup ran. Re-fetch code from
upstream; never carry it across from a compromised machine.

---

## Do this FIRST — the console, before any shutdown

**SCP → your server → `Screen`.** This is the VNC console; it does not touch the
guest's network stack, so it works even though nothing else does.

If the guest is alive and only its networking is broken, you can fix it there and
the entire outage ends — no shutdown, no rescue system, and the dumps come out
over the network normally. That is a far better outcome than this runbook, so
spend five minutes on it before committing to a shutdown.

| Console shows | Meaning | Do |
|---|---|---|
| A login prompt | Alive; networking broken | Log in and run the triage below |
| Kernel panic / call trace | Hung | Go to the rescue section |
| `emergency mode` / initramfs | Filesystem trouble | Go to the rescue section |
| Blank, no response to keys | Hung or no console output | Go to the rescue section |

Triage at the console, in this order — it is almost always one of these:

```bash
df -h                     # a full / kills sshd, docker and networking at once.
                          # 932 core dumps were accumulating under /root/gitea.
ip a; ip r                # interface down? default route gone?
nft list ruleset | head   # or: iptables -L -n | head   (a bad rule blocks all)
systemctl status ssh docker
dmesg | grep -i -E 'oom|killed process' | tail
```

If `df -h` shows `/` at 100%, that is very likely the whole outage. Free
something (`journalctl --vacuum-size=200M`, delete the core dumps under
`/root/gitea`), then `systemctl restart ssh docker` and the box comes back.

---

## Rescue system — only if the console says the guest is dead

### 1. Activate

**SCP → Control → ACPI Shutdown**, wait for it to stop, then
**Media → Rescue System → Activate → OK**. Note the password it displays.

If ACPI shutdown does nothing after a couple of minutes the guest is hung and you
will need a hard reset. Read the risk section below before you do that.

### 2. Log in and find the disk

```bash
ssh root@159.195.32.209        # the rescue password from step 1
lsblk                          # find the root partition: vda1/sda1, or LVM
```

### 3. Mount **read-only**

```bash
mkdir -p /mnt/old
mount -o ro /dev/vda1 /mnt/old     # adjust to what lsblk showed
ls /mnt/old/opt/websites/p2pwiki/dumps/
```

`-o ro` is not optional. It is what makes this operation unable to damage
anything, and it is the difference between "read the disk" and "risk the disk".
If the root filesystem is LVM, activate first: `vgchange -ay`, then mount the
mapper device.

### 4. Copy the files out

From **your own machine**, pulling:

```bash
scp root@159.195.32.209:/mnt/old/opt/websites/p2pwiki/dumps/p2pwiki-2026-08-16-current.xml.bz2 .
scp root@159.195.32.209:/mnt/old/opt/websites/p2pwiki/dumps/p2pwiki-*-images.tar .
scp root@159.195.32.209:/mnt/old/opt/websites/p2pwiki/LocalSettings.php .
scp root@159.195.32.209:/mnt/old/opt/websites/p2pwiki/.htaccess .
```

Check the dump before you trust it — a truncated bz2 will fail here rather than
halfway through an import:

```bash
bzip2 -t p2pwiki-2026-08-16-current.xml.bz2 && echo "archive intact"
bzcat p2pwiki-2026-08-16-current.xml.bz2 | grep -o '<timestamp>[^<]*' | sort | tail -1
```

That last line prints the newest revision in it. Expect something on 2026-08-16.

### 5. Deactivate

**Media → Rescue System → Deactivate → OK.** The server shuts down; power it on
manually and it boots from the normal disk again.

---

## Risks, and how each is contained

**Nothing here writes to the Netcup disk.** The mount is read-only, so the
recovery step itself cannot corrupt anything.

The real risks are around it:

1. **A hard reset, if ACPI shutdown will not work.** This is the one that can
   affect data elsewhere: every service on that box — Gitea, Mailcow, Infisical,
   ~400 containers — stops without flushing. In practice journalled filesystems
   and InnoDB crash recovery handle this, and the host has already been in an
   unknown state for 31 hours, so it is not a *new* risk so much as a slightly
   increased one. But it is real, and it is the reason to try the console first.

2. **Rescue mode disables the firewall**, in netcup's own words: *"When the
   rescue system is active, the firewall is disabled and cannot be activated."*
   That is a bare, internet-facing host with a netcup-issued root password, on a
   machine that has already been compromised once. Keep the window to minutes,
   not hours, and deactivate as soon as the files are copied.

3. **Carrying the compromise forward.** The XML dump is text and the images are
   media — both safe to import. `LocalSettings.php` is PHP: read it before using
   it rather than dropping it in. The extensions volume is executable code and
   should not be copied at all (see above).

---

## After you have the dump

```bash
scp p2pwiki-2026-08-16-current.xml.bz2 spark:~/p2pwiki-standby/
ssh spark
cd ~/p2pwiki-standby

# Import ON TOP of the existing content. importDump merges by revision, so
# revisions we already have are skipped and only the missing ones land.
rm -f dumps/import.xml
./import-dump.sh p2pwiki-2026-08-16-current.xml.bz2

# THEN update the watermark — this is what shrinks the conflict window from
# 16 days to about one, and the merge check reads it.
bzcat p2pwiki-2026-08-16-current.xml.bz2 | grep -o '<timestamp>[^<]*' \
  | sort | tail -1 | tr -d '<timestamp>-:TZ' > import-watermark.txt
cat import-watermark.txt

# And the images, if you got the tar
tar tf p2pwiki-*-images.tar | head
docker cp p2pwiki-*-images.tar p2pwiki-standby:/tmp/
docker exec p2pwiki-standby bash -c 'cd /var/www/html/images && tar xf /tmp/p2pwiki-*-images.tar'
docker exec p2pwiki-standby php /var/www/html/maintenance/rebuildImages.php --missing
```

Then re-run `rebuildall.php` so link and category tables reflect the new
revisions, and the standby is within a day of what Netcup had.
