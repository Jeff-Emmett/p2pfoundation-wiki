#!/usr/bin/env python3
"""Rewrite dead external links in the P2P Foundation Wiki to Wayback snapshots.

For each dead domain, this uses the MediaWiki API to find every page linking to
that domain (Special:LinkSearch via list=exturlusage), looks up the closest
Internet Archive snapshot for each dead URL, and repoints the link in the page
wikitext to `https://web.archive.org/web/<ts>/<url>`.

Default mode is **dry-run**: it prints the planned per-page substitutions and
writes a unified report, but makes NO edits. Pass --apply (plus valid sysop/bot
cookies in /tmp/wiki_cookies.txt) to actually save edits.

Dead-domain list: one domain per line (comments with '#'), e.g. the DNSFAIL /
HTTP-410 domains from dead-links-report.md. Only rewrite domains you have
confirmed dead — a live domain returning a transient error must NOT be here,
or you would replace working links with archives.

    python wayback_rewrite.py --domains dead-domains.txt            # dry-run
    python wayback_rewrite.py --domains dead-domains.txt --apply    # live edits
    python wayback_rewrite.py --domains dead-domains.txt --limit 50 # cap pages

This intentionally does NOT run itself anywhere; it is a reviewed tool.
"""
import argparse
import http.cookiejar
import re
import sys
import time
import urllib.parse
from pathlib import Path

import httpx  # same dep the translation-cache uses; or: pip install httpx

API = "https://wiki.p2pfoundation.net/api.php"
WAYBACK = "https://archive.org/wayback/available"
UA = "p2pwiki-wayback-rewrite/1.0 (maintenance; contact michel@p2pfoundation.net)"


def load_cookies(path: Path) -> dict:
    jar = http.cookiejar.MozillaCookieJar(str(path))
    cookies = {}
    if path.exists():
        jar.load(ignore_discard=True, ignore_expires=True)
        cookies = {c.name: c.value for c in jar}
    return cookies


def read_domains(path: Path) -> list[str]:
    out = []
    for line in path.read_text().splitlines():
        line = line.split("#", 1)[0].strip()
        if line:
            out.append(line.lower())
    return out


def pages_linking(client: httpx.Client, domain: str) -> list[str]:
    """All page titles with an external link to `domain` (both http/https)."""
    titles, cont = set(), {}
    while True:
        params = {
            "action": "query", "list": "exturlusage", "euquery": domain,
            "euprotocol": "", "eunamespace": "0", "eulimit": "500",
            "format": "json", **cont,
        }
        r = client.get(API, params=params).json()
        for u in r.get("query", {}).get("exturlusage", []):
            titles.add(u["title"])
        if "continue" in r:
            cont = r["continue"]
        else:
            break
    return sorted(titles)


def wayback_snapshot(client: httpx.Client, url: str) -> str | None:
    try:
        r = client.get(WAYBACK, params={"url": url}, timeout=20).json()
        snap = r.get("archived_snapshots", {}).get("closest", {})
        if snap.get("available") and snap.get("url"):
            # normalise to https
            return snap["url"].replace("http://", "https://", 1)
    except Exception as e:
        print(f"    ! wayback lookup failed for {url}: {e}", file=sys.stderr)
    return None


def get_wikitext(client: httpx.Client, title: str) -> tuple[str, str] | None:
    params = {
        "action": "query", "prop": "revisions", "rvprop": "content|timestamp",
        "rvslots": "main", "titles": title, "format": "json",
    }
    pages = client.get(API, params=params).json()["query"]["pages"]
    for _, page in pages.items():
        if "missing" in page:
            return None
        rev = page["revisions"][0]
        return rev["slots"]["main"]["*"], rev["timestamp"]
    return None


def find_dead_urls(text: str, domain: str) -> list[str]:
    # match full URLs on this domain as they appear in wikitext
    pat = re.compile(r"https?://" + re.escape(domain) + r"[^\s\]\|\}<>\"']*", re.I)
    return sorted(set(pat.findall(text)))


def csrf_token(client: httpx.Client) -> str:
    r = client.get(API, params={"action": "query", "meta": "tokens", "format": "json"})
    return r.json()["query"]["tokens"]["csrftoken"]


def save(client: httpx.Client, title: str, text: str, token: str, base_ts: str) -> dict:
    data = {
        "action": "edit", "title": title, "text": text, "token": token,
        "basetimestamp": base_ts, "bot": "1", "format": "json",
        "summary": "Rewrite dead external link(s) to Internet Archive snapshot "
                   "(automated link-rot repair; see maintenance/wayback_rewrite.py)",
    }
    return client.post(API, data=data).json()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--domains", required=True, type=Path)
    ap.add_argument("--apply", action="store_true", help="perform edits (needs cookies)")
    ap.add_argument("--limit", type=int, default=0, help="max pages to touch (0=all)")
    ap.add_argument("--cookies", type=Path, default=Path("/tmp/wiki_cookies.txt"))
    ap.add_argument("--report", type=Path, default=Path("wayback-rewrite-report.txt"))
    args = ap.parse_args()

    cookies = load_cookies(args.cookies) if args.apply else {}
    if args.apply and not cookies:
        print("ERROR: --apply needs sysop/bot cookies at", args.cookies, file=sys.stderr)
        return 2

    client = httpx.Client(headers={"User-Agent": UA}, cookies=cookies, timeout=30)
    domains = read_domains(args.domains)
    token = csrf_token(client) if args.apply else ""

    snap_cache: dict[str, str | None] = {}
    report, touched = [], 0
    for domain in domains:
        titles = pages_linking(client, domain)
        print(f"# {domain}: {len(titles)} page(s)")
        for title in titles:
            if args.limit and touched >= args.limit:
                print("reached --limit, stopping.")
                break
            got = get_wikitext(client, title)
            if not got:
                continue
            text, base_ts = got
            new_text, changes = text, []
            for url in find_dead_urls(text, domain):
                if url not in snap_cache:
                    snap_cache[url] = wayback_snapshot(client, url)
                    time.sleep(0.3)  # be polite to archive.org
                snap = snap_cache[url]
                if snap and snap not in new_text:
                    new_text = new_text.replace(url, snap)
                    changes.append((url, snap))
            if not changes:
                continue
            touched += 1
            report.append(f"[{title}]")
            for old, new in changes:
                report.append(f"    {old}\n      -> {new}")
            if args.apply:
                res = save(client, title, new_text, token, base_ts)
                ok = "edit" in res and res["edit"].get("result") == "Success"
                print(f"  {'EDITED' if ok else 'FAILED'} {title}: {res.get('error', '')}")
            else:
                print(f"  [dry-run] would edit {title} ({len(changes)} link(s))")
        else:
            continue
        break  # only reached when inner loop hit --limit

    args.report.write_text("\n".join(report) + "\n")
    print(f"\n{'APPLIED' if args.apply else 'DRY-RUN'}: {touched} page(s); "
          f"report -> {args.report}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
