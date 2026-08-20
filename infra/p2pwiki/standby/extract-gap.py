#!/usr/bin/env python3
"""
Find every page edited in the window the live wiki is missing, and write a
delta XML that MediaWiki can import.

The standby was rebuilt from p2pwiki-2026-08-02-current.xml.bz2 even though
p2pwiki-latest-current.xml.bz2 already pointed at the 08-16 dump, so the live
wiki's newest content is 2026-07-31 while Netcup ran until 2026-08-17 06:21Z.

Works on the raw text rather than an XML tree on purpose. These are
current-revision dumps, so each <page> block is self-contained, and copying the
header and the matching blocks byte-for-byte sidesteps every way a re-serialised
tree can drift from what MediaWiki's importer expects — namespace prefixes,
attribute order, xml:space on <text>.
"""
import bz2
import re
import sys

SRC = sys.argv[1]
OUT = sys.argv[2]
CUTOFF = sys.argv[3]  # ISO8601, exclusive

TS = re.compile(r"<timestamp>([^<]+)</timestamp>")
TITLE = re.compile(r"<title>([^<]*)</title>")
USER = re.compile(r"<username>([^<]*)</username>")
IPC = re.compile(r"<ip>([^<]*)</ip>")

header: list[str] = []
in_header = True
buf: list[str] = []
in_page = False

pages_total = 0
kept = []

with bz2.open(SRC, "rt", encoding="utf-8", errors="replace") as f, \
     open(OUT, "w", encoding="utf-8") as out:
    for line in f:
        if in_header:
            header.append(line)
            if "</siteinfo>" in line:
                in_header = False
                out.writelines(header)
            continue

        if "<page>" in line:
            in_page, buf = True, [line]
            continue

        if in_page:
            buf.append(line)
            if "</page>" in line:
                in_page = False
                pages_total += 1
                block = "".join(buf)
                stamps = TS.findall(block)
                newest = max(stamps) if stamps else ""
                if newest > CUTOFF:
                    t = TITLE.search(block)
                    u = USER.search(block) or IPC.search(block)
                    kept.append((newest, t.group(1) if t else "?",
                                 u.group(1) if u else "(anon)"))
                    out.write(block)
            continue

    out.write("</mediawiki>\n")

kept.sort()
print(f"pages scanned : {pages_total}")
print(f"pages in gap  : {len(kept)}   (current revision newer than {CUTOFF})")
print()
by_user: dict[str, int] = {}
for _, _, u in kept:
    by_user[u] = by_user.get(u, 0) + 1
print("by editor:")
for u, n in sorted(by_user.items(), key=lambda kv: -kv[1]):
    print(f"  {u:24} {n}")
print()
print("pages (oldest first):")
for ts, title, user in kept:
    print(f"  {ts}  {user:20} {title}")
