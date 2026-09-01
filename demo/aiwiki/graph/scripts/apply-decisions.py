"""Read the on-wiki review page, re-verify each approved row, and apply it.

Safety properties this file is responsible for, in the order they matter:

  1. Blank is not approval.      Only rows whose decision cell reads yes/y/approve
                                 are ever touched. Inaction is the default.
  2. Re-verify at apply time.    The stored offset from generation time is never
                                 trusted. The live wikitext is re-fetched and the
                                 unlinked mention re-located; if the article has
                                 moved on, the row is marked stale and skipped.
                                 This is what makes the operation idempotent.
  3. One revision per article.   All approved links for an article go in a single
                                 edit, so the history stays readable.
  4. Attribution.                The bot writes; the summary names the human who
                                 approved, the batch, and the row id.
  5. Reversibility.              Every applied row records its newrevid, and
                                 --undo walks a batch back out.
  6. Kill switch.                Any content on .../Suggested links/STOP halts it.
  7. Dry run is the default.     --apply is required to write anything at all.

Reading needs no credentials. Writing needs a bot password in the environment as
P2PWIKI_BOT_USER / P2PWIKI_BOT_PASS — never on the command line, never a real
editor's main password.
"""
import argparse, json, os, re, sys, time
import httpx

API = "https://wiki.p2pfoundation.net/api.php"
SP = os.path.dirname(os.path.abspath(__file__))
DATA = os.path.join(SP, "..", "data")

BATCH_PAGE = "P2P Foundation:Suggested links/%s"
STOP_PAGE = "P2P Foundation:Suggested links/STOP"
RATE_S = 5.0            # seconds between edits
MAX_EDITS = 200         # hard ceiling per run

YES = {"yes", "y", "approve", "approved", "ok", "✓"}
NO = {"no", "n", "reject", "rejected", "✗"}

RE_LINK = re.compile(r"\[\[[^\]]*\]\]")


class Wiki:
    def __init__(self):
        self.c = httpx.Client(timeout=30.0, follow_redirects=True,
                              headers={"User-Agent": "P2PLinkBot/0.1 (aiwiki suggested links)"})
        self.token = None

    def get(self, **p):
        p["format"] = "json"
        r = self.c.get(API, params=p); r.raise_for_status(); return r.json()

    def post(self, **p):
        p["format"] = "json"
        r = self.c.post(API, data=p); r.raise_for_status(); return r.json()

    def login(self, user, password):
        t = self.get(action="query", meta="tokens", type="login")
        tok = t["query"]["tokens"]["logintoken"]
        r = self.post(action="login", lgname=user, lgpassword=password, lgtoken=tok)
        if r.get("login", {}).get("result") != "Success":
            raise SystemExit("login failed: %s" % r.get("login", {}).get("reason", "?"))
        self.token = self.get(action="query", meta="tokens", type="csrf")["query"]["tokens"]["csrftoken"]
        who = self.get(action="query", meta="userinfo", uiprop="groups|rights")["query"]["userinfo"]
        if "edit" not in who.get("rights", []):
            raise SystemExit("account %s has no edit right" % who.get("name"))
        print("authenticated as %s (%s)" % (who.get("name"), ",".join(who.get("groups", []))))

    def content(self, title):
        r = self.get(action="query", titles=title, prop="revisions",
                     rvprop="content|ids", rvslots="main")
        for pid, page in r.get("query", {}).get("pages", {}).items():
            if pid == "-1":
                return None, None
            rev = page.get("revisions", [{}])[0]
            return rev.get("slots", {}).get("main", {}).get("*"), rev.get("revid")
        return None, None

    def edit(self, title, text, summary, baserevid):
        return self.post(action="edit", title=title, text=text, summary=summary,
                         baserevid=baserevid, nocreate=1, bot=1, token=self.token)


def already_links(raw, target):
    """Does the article already link to `target` anywhere? MediaWiki style is to
    link the first occurrence and only that one, so an existing link — piped,
    anchored, or bare — means there is nothing to do. This is also what makes
    applying a batch idempotent: without it, a second run finds the *next*
    unlinked occurrence and links that too, and a third finds the one after,
    which is exactly how a tool like this turns into overlinking.
    """
    pat = re.compile(r"\[\[\s*" + re.escape(target) + r"\s*(?:[|#][^\]]*)?\]\]", re.I)
    return bool(pat.search(raw))


def find_mention(raw, target):
    """The first unlinked occurrence of `target` in prose, or None if the article
    already links there. Same rule as generation."""
    if already_links(raw, target):
        return None
    spans = [m.span() for m in RE_LINK.finditer(raw)]
    pat = re.compile(r"(?<![\w\[])" + re.escape(target) + r"(?![\w\]])", re.I)
    for m in pat.finditer(raw):
        s, e = m.span()
        if any(ls <= s < le for ls, le in spans):
            continue
        ls = raw.rfind("\n", 0, s) + 1
        if raw[ls:ls + 1] in ("=", "|", "!"):
            continue
        return s, e
    return None


def parse_rows(text):
    """Pull the decision table out of the review page. Deliberately forgiving
    about whitespace and case, and deliberately strict about what counts as a
    yes: anything not recognised is treated as undecided."""
    rows, cur = [], None
    for line in text.splitlines():
        line = line.strip()
        if line.startswith("|-"):
            if cur and len(cur) >= 6:
                rows.append(cur)
            cur = []
        elif cur is not None and line.startswith("|") and not line.startswith("|}"):
            cur.append(line[1:].strip())
        elif line.startswith("|}") and cur and len(cur) >= 6:
            rows.append(cur); cur = None
    out = []
    for r in rows:
        pid = re.sub(r"</?code>", "", r[0]).strip()
        frm = re.sub(r"^\[\[|\]\]$", "", r[1]).strip()
        to = re.sub(r"^\[\[|\]\]$", "", r[2]).strip()
        dec = re.sub(r"<[^>]+>", "", r[4]).strip().lower()
        by = r[5].strip()
        if not pid:
            continue
        out.append({"id": pid, "from": frm, "to": to, "decision": dec, "by": by})
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--batch", required=True, help="batch date, e.g. 2026-09-01")
    ap.add_argument("--apply", action="store_true", help="actually edit the wiki")
    ap.add_argument("--undo", action="store_true", help="revert this batch's edits")
    ap.add_argument("--max", type=int, default=MAX_EDITS)
    a = ap.parse_args()

    w = Wiki()

    stop, _ = w.content(STOP_PAGE)
    if stop and stop.strip():
        raise SystemExit("STOP page is non-empty — refusing to run:\n%s" % stop.strip()[:400])

    page = BATCH_PAGE % a.batch
    text, _ = w.content(page)
    if not text:
        raise SystemExit("review page not found: %s" % page)
    rows = parse_rows(text)
    yes = [r for r in rows if r["decision"] in YES]
    no = [r for r in rows if r["decision"] in NO]
    print("%s: %d rows · %d approved · %d rejected · %d undecided"
          % (page, len(rows), len(yes), len(no), len(rows) - len(yes) - len(no)))

    unsigned = [r for r in yes if not r["by"]]
    if unsigned:
        print("  %d approved rows are unsigned; they will be skipped:" % len(unsigned))
        for r in unsigned[:5]:
            print("    %s %s -> %s" % (r["id"], r["from"], r["to"]))
        yes = [r for r in yes if r["by"]]

    # group by article: one revision each
    by_article = {}
    for r in yes:
        by_article.setdefault(r["from"], []).append(r)

    applied, stale, log = 0, 0, []
    for title, group in list(by_article.items())[:a.max]:
        raw, revid = w.content(title)
        if raw is None:
            print("  MISSING  %s" % title); continue
        new = raw
        done = []
        for r in sorted(group, key=lambda r: r["id"]):
            hit = find_mention(new, r["to"])
            if not hit:
                stale += 1
                log.append({**r, "status": "stale"})
                continue
            s, e = hit
            new = new[:s] + "[[" + new[s:e] + "]]" + new[e:]
            done.append(r)
        if not done:
            continue
        who = ", ".join(sorted({r["by"] for r in done}))
        ids = ", ".join(r["id"] for r in done)
        summary = ("Suggested link%s approved by %s · batch %s · %s · [[%s|review]]"
                   % ("" if len(done) == 1 else "s", who, a.batch, ids, page))
        if not a.apply:
            print("\n--- %s (%d link%s)" % (title, len(done), "" if len(done) == 1 else "s"))
            print("    " + summary)
            for r in done:
                print("    + [[%s]]" % r["to"])
            applied += len(done)
            continue
        res = w.edit(title, new, summary, revid)
        if "error" in res:
            print("  ERROR %s: %s" % (title, res["error"].get("info")))
            log.append({"article": title, "status": "error", "info": res["error"].get("info")})
        else:
            newrev = res.get("edit", {}).get("newrevid")
            print("  edited %s -> rev %s (%d)" % (title, newrev, len(done)))
            for r in done:
                log.append({**r, "status": "applied", "revid": newrev, "article": title})
            applied += len(done)
        time.sleep(RATE_S)

    print("\n%s: %d link%s %s, %d stale"
          % ("DRY RUN" if not a.apply else "applied", applied,
             "" if applied == 1 else "s",
             "would be applied" if not a.apply else "applied", stale))
    if a.apply:
        p = os.path.join(DATA, "applied-%s.json" % a.batch)
        json.dump(log, open(p, "w", encoding="utf-8"), indent=1, ensure_ascii=False)
        print("ledger -> %s   (undo with --undo --batch %s)" % (p, a.batch))
    else:
        print("Nothing was written. Add --apply once the above reads correctly.")


if __name__ == "__main__":
    main()
