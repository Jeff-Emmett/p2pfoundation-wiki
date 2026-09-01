"""Read the wikitext mirror and produce the graph's raw inputs.

The wiki's own `pagelinks` table is not usable — refreshLinks never ran after
the XML import, so it holds 45k rows for 39k articles. The wikitext does hold
the links, so this reads them straight out of `<repo>/wiki/*.mediawiki`:
titles, categories, outbound links, lead text, byte length.

Output: data/corpus.json.gz
"""
import gzip, json, os, re, sys, time, unicodedata

REPO = "/home/jeffe/Github/p2pfoundation-wiki"
WIKI = os.path.join(REPO, "wiki")
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "data")

T0 = time.time()
def log(*a): print("[%6.1fs]" % (time.time() - T0), *a, flush=True)

# Namespaces that are not articles. Interwiki prefixes are filtered the same
# way — a `[[Wikipedia:X]]` link leaves this wiki and is not an edge in it.
NS = re.compile(r"^(file|image|category|template|help|user|talk|special|media|"
                r"mediawiki|portal|wikipedia|wiktionary|commons|w|de|fr|es|it|"
                r"nl|pt|ru|zh|ja|simple|meta|b|q|s|v|n):", re.I)

RE_CAT      = re.compile(r"\[\[\s*Category\s*:\s*([^\]|#]+)", re.I)
RE_LINK     = re.compile(r"\[\[\s*([^\]|#<>\[\{\}\n]+?)\s*(?:\|[^\]]*)?\]\]")
RE_REDIRECT = re.compile(r"^\s*#\s*REDIRECT\s*\[\[\s*([^\]|#]+)", re.I)

# Lead-text cleanup: the same shape of stripping the gateway's stripMarkup()
# does, kept deliberately simple because the lead is display text, not input
# to retrieval.
RE_STRIP = [
    (re.compile(r"<ref[^>]*>.*?</ref>", re.S | re.I), " "),
    (re.compile(r"<ref[^>]*/>", re.I), " "),
    (re.compile(r"<!--.*?-->", re.S), " "),
    (re.compile(r"<[^>]+>", re.S), " "),
    (re.compile(r"\{\{[^{}]*\}\}", re.S), " "),
    (re.compile(r"\[\[\s*(?:File|Image|Category)\s*:[^\]]*\]\]", re.I | re.S), " "),
    (re.compile(r"\[\[[^\]|]*\|([^\]]*)\]\]"), r"\1"),
    (re.compile(r"\[\[([^\]]*)\]\]"), r"\1"),
    (re.compile(r"\[https?://\S+\s+([^\]]*)\]"), r"\1"),
    (re.compile(r"https?://\S+"), " "),
    (re.compile(r"^[=]{1,6}\s*(.*?)\s*[=]{1,6}\s*$", re.M), r"\1. "),
    (re.compile(r"'{2,}"), ""),
    (re.compile(r"^[*#:;]+\s*", re.M), ""),
    (re.compile(r"[|{}\[\]]"), " "),
    (re.compile(r"\s+"), " "),
]

def norm(t):
    """MediaWiki title normalisation: underscores are spaces, the first letter
    is capitalised, whitespace collapses."""
    t = unicodedata.normalize("NFC", t).replace("_", " ").strip()
    t = re.sub(r"\s+", " ", t)
    return t[:1].upper() + t[1:] if t else t

def strip_markup(raw):
    s = raw
    for pat, rep in RE_STRIP:
        s = pat.sub(rep, s)
    return s.strip()

names = [f for f in os.listdir(WIKI) if f.endswith(".mediawiki")]
names.sort()
log("files", len(names))

titles, raws = [], []
for i, fn in enumerate(names):
    try:
        with open(os.path.join(WIKI, fn), encoding="utf-8", errors="ignore") as fh:
            # Read the whole file. A 60,000-byte cap looked harmless — 99% of
            # articles are under 41 KB — but categories and "see also" links sit
            # at the *end* of a page, so the cap silently stripped them from the
            # 163 largest articles, which are the hubs. [[Commons]] lost all 8.
            raws.append(fh.read())
    except OSError:
        continue
    titles.append(norm(fn[:-len(".mediawiki")]))
    if i % 8000 == 0:
        log("read", i)
log("read all")

# ---- redirects collapse into their target -----------------------------
redirect = {}
real = []
for t, raw in zip(titles, raws):
    m = RE_REDIRECT.match(raw)
    if m:
        redirect[t] = norm(m.group(1))
    else:
        real.append(t)
log("redirects", len(redirect), "articles", len(real))

index = {t: i for i, t in enumerate(real)}

def resolve(t):
    """Follow a redirect chain to an article, bounded so a cycle cannot hang."""
    for _ in range(4):
        if t in index:
            return index[t]
        nxt = redirect.get(t)
        if nxt is None or nxt == t:
            return None
        t = nxt
    return None

cats, links, leads, sizes = [], [], [], []
raw_by_title = dict(zip(titles, raws))
dangling = 0
for n, t in enumerate(real):
    raw = raw_by_title[t]
    sizes.append(len(raw))
    cats.append(sorted({norm(c) for c in RE_CAT.findall(raw)}))

    out = set()
    for tgt in RE_LINK.findall(raw):
        tgt = tgt.strip()
        if not tgt or NS.match(tgt):
            continue
        j = resolve(norm(tgt))
        if j is None:
            dangling += 1
        elif j != n:
            out.add(j)
    links.append(sorted(out))

    lead = strip_markup(raw[:6000])
    leads.append(lead[:900])
    if n % 8000 == 0:
        log("parsed", n)

nedges = sum(len(l) for l in links)
log("edges", nedges, "dangling", dangling,
    "mean out-degree", round(nedges / max(len(real), 1), 2))

os.makedirs(OUT, exist_ok=True)
p = os.path.join(OUT, "corpus.json.gz")
with gzip.open(p, "wt", encoding="utf-8") as fh:
    json.dump({"titles": real, "cats": cats, "links": links,
               "leads": leads, "sizes": sizes,
               "nRedirects": len(redirect), "nDangling": dangling},
              fh, ensure_ascii=False, separators=(",", ":"))
log("written", os.path.getsize(p) // 1024, "KB ->", p)
