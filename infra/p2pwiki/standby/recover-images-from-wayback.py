#!/usr/bin/env python3
"""
Recover the wiki's uploaded files from the Internet Archive.

The images live in a ~1.7 GB Docker volume on the Netcup host and are published
only as a monthly tar at /dumps/ — both unreachable while that host is down. But
the files were served publicly for years, so the Archive has most of them.

Measured coverage on the first run: 1,227 of 1,248 File: pages, i.e. 98%.

METHOD, and why it is not the obvious one. The obvious approach is to compute
MediaWiki's storage path for each filename — /images/<a>/<ab>/<Name>, where the
hex comes from md5(name) — and probe the Archive for each. That is 1,248 guesses,
most of a wasted round trip each, and it silently misses anything whose name was
normalised differently from what md5 was computed over.

Instead this ENUMERATES what the Archive actually holds, via the CDX index, and
intersects that with what the wiki needs. One bulk query replaces 1,248 probes,
and the answer is what exists rather than what we assumed would exist.

    ./recover-images-from-wayback.py --list          # coverage report only
    ./recover-images-from-wayback.py --download      # fetch into ./dumps/recovered-images
"""

import argparse, json, os, subprocess, sys, time, urllib.parse, urllib.request
from concurrent.futures import ThreadPoolExecutor

CDX = ("https://web.archive.org/cdx/search/cdx?url=wiki.p2pfoundation.net/images*"
       "&output=text&fl=original,timestamp,statuscode,mimetype"
       "&filter=statuscode:200&collapse=urlkey&limit=200000")
OUT = "dumps/recovered-images"
UA = "p2pwiki-standby-recovery/1.0 (+restoring our own wiki's files)"


def fetch(url, timeout=90, retries=4):
    """Wayback throttles hard; back off rather than hammering it."""
    delay = 2.0
    for attempt in range(retries):
        try:
            req = urllib.request.Request(url, headers={"User-Agent": UA})
            with urllib.request.urlopen(req, timeout=timeout) as r:
                return r.read()
        except Exception as e:
            if attempt == retries - 1:
                raise
            # 429/503 are the common ones and they mean "slow down", not "fail".
            time.sleep(delay)
            delay *= 2
    return None


def archived_index():
    raw = fetch(CDX, timeout=280).decode("utf-8", "replace")
    best = {}
    for line in raw.splitlines():
        p = line.split()
        if len(p) < 4:
            continue
        url, ts, _st, mime = p[0], p[1], p[2], p[3]
        if "/thumb/" in url or mime.startswith("text/"):
            continue
        base = urllib.parse.urlsplit(url).path.rsplit("/", 1)[-1]
        if not base:
            continue
        # MediaWiki stores titles with underscores; the URL carries whatever was
        # percent-encoded at the time. Decode, then normalise the one way MW does.
        key = urllib.parse.unquote(base).replace(" ", "_")
        if key not in best or ts > best[key][0]:
            best[key] = (ts, url)
    return best


def needed_files():
    """Ask the wiki which files it references, rather than trusting a list."""
    sql = "SELECT page_title FROM page WHERE page_namespace=6;"
    pw = None
    for line in open(".env", encoding="utf-8"):
        if line.startswith("DB_ROOT_PASSWORD="):
            pw = line.split("=", 1)[1].strip()
    if not pw:
        sys.exit("no DB_ROOT_PASSWORD in .env")
    out = subprocess.run(
        ["docker", "exec", "-i", "-e", f"MYSQL_PWD={pw}", "p2pwiki-standby-db",
         "mariadb", "-uroot", "-N", "-B", "-e", sql, "p2pwiki"],
        capture_output=True, text=True, check=True).stdout
    return [l.strip() for l in out.splitlines() if l.strip()]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--download", action="store_true")
    ap.add_argument("--workers", type=int, default=4,
                    help="keep this low; the Archive throttles and we are a guest")
    args = ap.parse_args()

    os.chdir(os.path.dirname(os.path.abspath(__file__)))

    print("enumerating the Archive ...")
    arch = archived_index()
    need = needed_files()
    have = [n for n in need if n in arch]
    miss = [n for n in need if n not in arch]

    print(f"  File: pages needed      {len(need)}")
    print(f"  distinct archived files {len(arch)}")
    print(f"  recoverable             {len(have)}  ({100*len(have)/max(len(need),1):.0f}%)")
    print(f"  not archived            {len(miss)}")
    with open("images-not-archived.txt", "w", encoding="utf-8") as fh:
        fh.write("\n".join(miss) + ("\n" if miss else ""))
    print("  unrecoverable list -> images-not-archived.txt")

    if not args.download:
        print("\n(--download to fetch them)")
        return

    os.makedirs(OUT, exist_ok=True)
    todo = [n for n in have if not os.path.exists(os.path.join(OUT, n))]
    print(f"\ndownloading {len(todo)} ({len(have)-len(todo)} already on disk)")

    ok = fail = 0

    def grab(name):
        ts, url = arch[name]
        # 'im_' returns the original bytes with no Wayback rewriting or toolbar.
        wb = f"https://web.archive.org/web/{ts}im_/{url}"
        try:
            data = fetch(wb)
            if not data or len(data) < 64:
                return name, False
            tmp = os.path.join(OUT, name + ".part")
            with open(tmp, "wb") as fh:
                fh.write(data)
            os.replace(tmp, os.path.join(OUT, name))
            return name, True
        except Exception:
            return name, False

    with ThreadPoolExecutor(max_workers=args.workers) as ex:
        for i, (name, good) in enumerate(ex.map(grab, todo), 1):
            ok, fail = (ok + 1, fail) if good else (ok, fail + 1)
            if i % 100 == 0:
                print(f"  {i}/{len(todo)}  ok={ok} fail={fail}", flush=True)

    print(f"\ndone: {ok} recovered, {fail} failed")
    print(f"files in {OUT}: {len(os.listdir(OUT))}")
    print("\nimport them with:")
    print("  docker exec p2pwiki-standby php /var/www/html/maintenance/importImages.php \\")
    print("      --comment='Recovered from the Internet Archive' /dumps/recovered-images")


if __name__ == "__main__":
    main()
