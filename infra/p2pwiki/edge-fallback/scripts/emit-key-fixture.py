#!/usr/bin/env python3
"""
Emit a title -> key fixture for test/fallback.test.mjs.

The Worker and the snapshot builder compute R2 keys independently, in two
languages. If they ever disagree the failure is SILENT — every lookup misses and
readers get the generic offline page instead of the article, with nothing logged
and nothing red. So the Python side writes down what it actually produced, and
the JS test asserts it can reproduce every one.

Sampling is deliberately biased towards the characters that break naive
encoders: parentheses, apostrophes, ampersands, percent signs, slashes,
non-ASCII, and long titles.

    ./emit-key-fixture.py --dump ~/.cache/p2pwiki-dump.xml.bz2 > test/key-fixture.json
"""

import argparse
import importlib.util
import json
import os
import re
import sys

# build-snapshot.py has a hyphen, so it cannot be imported by name. Load it by
# path rather than re-declaring safe_key() here — a second copy of that function
# would be exactly the drift this fixture exists to catch.
_spec = importlib.util.spec_from_file_location(
    "build_snapshot",
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "build-snapshot.py"),
)
_bs = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_bs)

# Characters that break naive encoders, plus anything non-ASCII.
INTERESTING = re.compile(r"[()'\"&%/:,!*]|[^\x00-\x7F]")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dump", required=True)
    ap.add_argument("--plain", type=int, default=150, help="ordinary titles to include")
    ap.add_argument("--tricky", type=int, default=850, help="awkward-character titles to include")
    args = ap.parse_args()

    plain, tricky = [], []
    for title, _text, _redir in _bs.iter_pages(args.dump):
        if title.startswith(_bs.SKIP_PREFIXES):
            continue
        bucket = tricky if INTERESTING.search(title) else plain
        limit = args.tricky if bucket is tricky else args.plain
        if len(bucket) < limit:
            bucket.append(title)
        if len(plain) >= args.plain and len(tricky) >= args.tricky:
            break

    # Synthetic cases the corpus may not contain, but which the scheme must survive.
    synthetic = [
        "Foo%Bar",
        "Foo%2FBar",
        "A/B/C",
        "Trailing space ",
        "  Leading space",
        "Double  space",
        "Tab\tseparated",
    ]

    # Field is "expected", not "key": gitleaks' generic-api-key rule matches on a
    # field NAMED key with a long value, and flagged eight article titles as
    # secrets. Renaming removes the trigger instead of suppressing the alarm.
    rows = [{"title": t, "expected": _bs.safe_key(t)} for t in plain + tricky + synthetic]
    json.dump(rows, sys.stdout, ensure_ascii=False, indent=1)
    sys.stdout.write("\n")
    print(f"{len(rows)} cases ({len(plain)} plain, {len(tricky)} tricky, {len(synthetic)} synthetic)", file=sys.stderr)


if __name__ == "__main__":
    main()
