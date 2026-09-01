"""Render a proposal batch as the wikitext of an on-wiki review page.

The review page is the record of decision, not this repo and not a database in
a container that can die (p2pwiki-ai has been Exited(137) for two weeks — that
is exactly the failure this avoids). Editors approve the way they already edit:
by typing in a cell and signing. The wiki's own history, watchlists, permissions
and talk page are then the audit trail, and they survive us.

Writes to stdout / a file. Posting it is a separate, deliberate step.
"""
import argparse, json, os, time

SP = os.path.dirname(os.path.abspath(__file__))
DATA = os.path.join(SP, "..", "data")

HEADER = """{{{{DISPLAYTITLE:Suggested links — {date}}}}}
__NOTOC__

This page lists '''suggested wikilinks''' found by comparing what articles are
about, and it changes nothing until an editor here says so.

'''How to review.''' In the ''decision'' column type <code>yes</code> or
<code>no</code>, and sign the ''by'' column with <nowiki>~~~~</nowiki>. A blank
row is not approved and will not be applied — doing nothing is always the safe
option. Save the page normally when you are done; you can review a few rows at
a time.

'''What gets changed.''' Only the rows marked <code>yes</code>. Each approved
row is re-checked against the live article at the moment it is applied, and
skipped if the article has moved on. Edits are made by
[[User:{bot}|{bot}]] and every edit summary names the editor who approved it and
links back to this page, so anything here can be found and undone from the
article's own history.

'''To stop everything''', put any text on
[[P2P Foundation:Suggested links/STOP]]. Nothing will be applied while that page
is non-empty.

Batch <code>{date}</code> · {n_mention} suggestions · generated from {articles:,}
articles and their {links:,} existing links.

== Link a mention the article already makes ==

Each of these is a phrase '''already written in the article''', not currently a
link, where a page of that exact name exists. Approving wraps the existing words
in brackets. Nothing is added and nothing is claimed.

{table_mention}

== Notes ==

Questions, or a rule that keeps producing bad suggestions, belong on the
[[{talk}|talk page]]. If a whole class of these is unwelcome, say so there and it
will be removed from the generator rather than re-proposed next month.
"""

# No similarity column: after the exact-case and multi-word filters the cosine
# sits at ~1.0 for essentially every surviving row, so it separates nothing. A
# number that does not discriminate, printed next to a decision, only borrows
# authority it has not earned.
ROW = """|-
| <code>{pid}</code>
| [[{frm}]]
| [[{to}]]
| {ev}
|
|
"""

TABLE_HEAD = """{| class="wikitable sortable" style="font-size:90%"
! id !! article !! suggested link !! the sentence it appears in !! decision !! by
"""


def esc(s):
    """Neutralise wikitext that would break the table or the page."""
    return (s.replace("|", "&#124;").replace("{", "&#123;").replace("}", "&#125;")
             .replace("[", "&#91;").replace("]", "&#93;").replace("\n", " ").strip())


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, default=60,
                    help="rows in this batch; keep the first one small enough to actually read")
    ap.add_argument("--bot", default="P2PLinkBot")
    ap.add_argument("--out", default=os.path.join(DATA, "batch.wikitext"))
    a = ap.parse_args()

    P = json.load(open(os.path.join(DATA, "proposals.json"), encoding="utf-8"))
    date = P["generatedAt"]

    # Strongest first, and only the kind whose evidence is visible in one glance.
    rows = P["strict"][:a.limit]

    body = TABLE_HEAD
    for i, p in enumerate(rows):
        ctx = esc(p["context"])
        # mark the phrase being linked so the reviewer's eye lands on it
        body += ROW.format(pid="m%04d" % i, frm=p["fromTitle"], to=p["toTitle"],
                           ev="…" + ctx + "…")
    body += "|}\n"

    talk = "P2P Foundation talk:Suggested links/%s" % date
    text = HEADER.format(date=date, bot=a.bot, n_mention=len(rows), talk=talk,
                         articles=P["corpus"]["articles"], links=P["corpus"]["existingLinks"],
                         table_mention=body)

    open(a.out, "w", encoding="utf-8").write(text)
    print("wrote %s (%d rows, %d KB)" % (a.out, len(rows), len(text) // 1024))
    print("target page: P2P Foundation:Suggested links/%s" % date)
    print("\nNothing has been posted. Posting is a deliberate, manual step:")
    print("  1. read %s end to end" % os.path.basename(a.out))
    print("  2. create the page above and paste it in, signed in as yourself")
    print("  3. once editors have marked rows, dry-run the applier:")
    print("       apply-decisions.py --batch %s" % date)
    print("     and only then add --apply")


if __name__ == "__main__":
    main()
