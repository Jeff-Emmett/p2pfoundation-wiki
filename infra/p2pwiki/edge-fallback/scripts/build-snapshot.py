#!/usr/bin/env python3
"""
Build the offline snapshot the edge fallback serves from.

Input is the MediaWiki current-revisions XML dump that dump-wiki.sh already
produces weekly (`p2pwiki-latest-current.xml.bz2`). Output is one file per page,
named by the page title with spaces as underscores — the same normalisation
MediaWiki applies to URLs — so the Worker can look a page up with a direct R2
GET and no manifest.

    ./build-snapshot.py --dump ~/.cache/p2pwiki-dump.xml.bz2 --out ~/.cache/p2pwiki-snapshot
    rclone copy ~/.cache/p2pwiki-snapshot/pages r2:p2pwiki-snapshot/pages --transfers 32

WHERE THIS MUST RUN, and it is not obvious: the dump is generated ON the Netcup
host, which is precisely the machine whose loss the snapshot exists to survive.
A refresh pipeline that pulls the dump only when Netcup is up is correct — you
cannot snapshot a dead host — but it means the snapshot's freshness is capped by
the last successful pull. Pull to somewhere off-Netcup (GX10) after each weekly
dump, and let the age show in the banner rather than hiding it.

Namespaces: only main-namespace articles are kept by default. Talk, User and
MediaWiki pages are not what a reader hits during an outage, and every one of
them costs an R2 object.
"""

import argparse
import bz2
import os
import re
import sys
import unicodedata
import xml.etree.ElementTree as ET

# Derived from the dump at parse time rather than hardcoded: the export schema
# version moves with MediaWiki (0.11 as of 1.40.4), and pinning it here would make
# a future upgrade fail as "0 pages found" instead of as an error.
def _ns_of(tag: str) -> str:
    return tag[: tag.index("}") + 1] if "}" in tag else ""

# Namespace prefixes to skip. "File:" is kept out because we do not snapshot the
# images themselves, so a File: page would render a description with no picture.
SKIP_PREFIXES = (
    "Talk:", "User:", "User talk:", "Project:", "Project talk:",
    "File:", "File talk:", "MediaWiki:", "MediaWiki talk:",
    "Template talk:", "Help talk:", "Category talk:",
    "Special:", "Media:",
)


def safe_key(title: str) -> str:
    """
    Title -> object key. MUST stay byte-identical to `snapshotKey()` in
    src/worker.js, or every lookup silently misses and the fallback quietly
    degrades to the generic offline page.

        spaces -> "_"   (MediaWiki's own URL normalisation)
        "%"    -> "%25" (first, so the next rule stays unambiguous)
        "/"    -> "%2F"

    R2 keys are flat strings, so "Foo" and "Foo/Bar" could both live there
    untouched — but this tree is staged on a filesystem before rclone uploads it,
    and a filesystem cannot hold a file and a directory at the same path. That is
    a real collision here, not a hypothetical: "Michel Bauwens" is a page AND a
    subpage prefix.

    Deliberately NOT urllib.parse.quote: it escapes !'()* while JavaScript's
    encodeURIComponent leaves them alone, and wiki titles are full of
    parentheses. Two "equivalent" encoders that disagree on one character class
    is exactly the kind of near-miss that fails green.
    """
    key = unicodedata.normalize("NFC", title).strip()
    key = re.sub(r"\s+", "_", key)
    return key.replace("%", "%25").replace("/", "%2F")


def iter_pages(dump_path):
    opener = bz2.open if dump_path.endswith(".bz2") else open
    ns = None
    with opener(dump_path, "rb") as fh:
        for event, elem in ET.iterparse(fh, events=("end",)):
            if ns is None:
                ns = _ns_of(elem.tag)
            if elem.tag != f"{ns}page":
                continue
            title_el = elem.find(f"{ns}title")
            text_el = elem.find(f".//{ns}text")
            redirect_el = elem.find(f"{ns}redirect")
            if title_el is not None and title_el.text:
                yield (
                    title_el.text,
                    (text_el.text if text_el is not None else "") or "",
                    redirect_el.get("title") if redirect_el is not None else None,
                )
            elem.clear()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dump", required=True, help="path to p2pwiki current-revisions XML (.bz2 or .xml)")
    ap.add_argument("--out", required=True, help="output directory; pages/ is created inside it")
    ap.add_argument("--all-namespaces", action="store_true", help="keep Talk:/User:/etc too")
    ap.add_argument("--min-bytes", type=int, default=0, help="skip pages shorter than this")
    args = ap.parse_args()

    pages_dir = os.path.join(args.out, "pages")
    os.makedirs(pages_dir, exist_ok=True)

    kept = skipped = redirects = 0

    for title, text, redirect_target in iter_pages(args.dump):
        if not args.all_namespaces and title.startswith(SKIP_PREFIXES):
            skipped += 1
            continue
        if len(text) < args.min_bytes:
            skipped += 1
            continue

        # Redirects are kept as a one-line wikitext link. They are cheap, and a
        # reader following an internal link to a redirect during an outage would
        # otherwise hit the generic offline page for no reason.
        if redirect_target:
            text = f"Redirect to [[{redirect_target}]]"
            redirects += 1

        key = safe_key(title)
        path = os.path.join(pages_dir, key)

        # Keys are flat by construction (see safe_key), so the only remaining
        # filesystem limit is the 255-byte filename cap. Report rather than
        # truncate: a truncated key is a key the Worker will never ask for.
        try:
            with open(path, "w", encoding="utf-8") as fh:
                fh.write(text)
        except OSError as exc:
            print(f"SKIP ({exc.strerror}): {title}", file=sys.stderr)
            skipped += 1
            continue

        kept += 1
        if kept % 5000 == 0:
            print(f"  {kept} pages written", file=sys.stderr)

    print(f"kept={kept} (of which redirects={redirects}) skipped={skipped}")
    print(f"output: {pages_dir}")
    print()
    print("Upload with:")
    print(f"  rclone copy {pages_dir} r2:p2pwiki-snapshot/pages --transfers 32 --checksum")


if __name__ == "__main__":
    main()
