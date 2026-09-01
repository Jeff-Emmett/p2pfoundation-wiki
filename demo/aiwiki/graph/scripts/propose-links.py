"""Turn semantic neighbours into a small, reviewable set of link proposals.

The graph knows 6 semantic neighbours for each of 39,915 articles. That is
~120,000 undirected pairs, and only 44,357 wikilinks exist in the whole wiki,
so nearly all of those pairs are "not linked". Shipping that to an editor is
not a review queue, it is a denial of service on their attention.

So the work here is almost entirely subtraction. Two kinds of proposal survive,
and they are different kinds of claim:

  mention   B's exact title already appears in A's prose, unlinked. The edit
            wraps text the author already wrote. An editor can verify it in one
            glance and the change asserts nothing new.

  see-also  A and B are mutual near-neighbours, well above the similarity floor,
            with no link either way and no shared neighbour that already makes
            the connection navigable. The edit adds a See Also entry. This is an
            editorial judgement, so it is held to a much higher bar and a much
            smaller queue.

Nothing here writes to the wiki. Output is data/proposals.json.
"""
import gzip, json, os, re, sys, time
from collections import defaultdict

SP = os.path.dirname(os.path.abspath(__file__))
DATA = os.path.join(SP, "..", "data")
WIKI = "/home/jeffe/Github/p2pfoundation-wiki/wiki"

# --- the bar ------------------------------------------------------------
SIM_MENTION   = 0.30   # a mention is its own evidence; similarity only ranks
SIM_SEE_ALSO  = 0.62   # a see-also asserts a relation, so it must be strong
MAX_PER_PAGE  = 3      # no single article gets swamped by one batch
MAX_OUTDEG    = 60     # above this a page is an index, not prose
MIN_BYTES     = 1200   # a stub has nowhere to put a See Also
TITLE_MIN_LEN = 8      # "Commons" matches everything; short titles need a link,
                       # not a regex, so they are only proposed as see-also

T0 = time.time()
def log(*a): print("[%6.1fs]" % (time.time() - T0), *a, flush=True)

NB = json.load(open(os.path.join(DATA, "neighbours.json"), encoding="utf-8"))
C = json.load(gzip.open(os.path.join(DATA, "corpus.json.gz"), "rt", encoding="utf-8"))
titles, cats, links, leads, sizes = C["titles"], C["cats"], C["links"], C["leads"], C["sizes"]
knnIdx, knnSim, region = NB["knnIdx"], NB["knnSim"], NB["region"]
N = len(titles)

# The nine reading-room lenses, applied with the gateway's own rule:
# case-insensitive substring of a lens keyword against each category, unioned.
AIWIKI = os.path.join(DATA, "..", "..", "data", "aiwiki-data.json")
facets = json.load(open(AIWIKI, encoding="utf-8"))["facets"] if os.path.exists(AIWIKI) else []
facetOf = [[] for _ in range(N)]
for fi, f in enumerate(facets):
    kws = [k.lower() for k in f["keywords"]]
    for i, cs in enumerate(cats):
        low = [x.lower() for x in cs]
        if any(k in cat for k in kws for cat in low):
            facetOf[i].append(fi)
log("corpus", N, "lenses", len(facets))

# --- what the wiki already connects -------------------------------------
linked = set()
outset = [set(l) for l in links]
inset = defaultdict(set)
for a, l in enumerate(links):
    for b in l:
        linked.add((min(a, b), max(a, b)))
        inset[b].add(a)
log("existing undirected links", len(linked))

def already_navigable(a, b):
    """True if a reader can already get from one to the other in two hops."""
    return bool((outset[a] | inset[a]) & (outset[b] | inset[b]))

# --- candidate pairs: mutual k-NN only ----------------------------------
knn_set = [set(k) for k in knnIdx]
cands = {}
for a in range(N):
    for rank, b in enumerate(knnIdx[a]):
        if a >= b or a not in knn_set[b]:
            continue                       # mutual agreement only
        cands[(a, b)] = knnSim[a][rank]
log("mutual k-NN pairs", len(cands))

cands = {p: s for p, s in cands.items() if p not in linked}
log("  not already linked", len(cands))

# --- page-level eligibility ---------------------------------------------
INDEXY = re.compile(r"(participants|directory|^category-|^introduction to|"
                    r"index|list of|^p2p blog|bibliography)", re.I)
def eligible(i):
    return (len(links[i]) <= MAX_OUTDEG and sizes[i] >= MIN_BYTES
            and not INDEXY.search(titles[i]))

# --- mention detection --------------------------------------------------
# Scan each article once, for the titles of its own candidate partners only.
partners = defaultdict(set)
for (a, b) in cands:
    partners[a].add(b); partners[b].add(a)

def read_raw(t):
    try:
        with open(os.path.join(WIKI, t + ".mediawiki"), encoding="utf-8", errors="ignore") as fh:
            return fh.read(60000)
    except OSError:
        return ""

RE_EXISTING_LINK = re.compile(r"\[\[[^\]]*\]\]")

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
    """First occurrence of `target` in prose, outside any existing [[...]] and
    outside a heading, and only if the article does not already link there.
    Returns (start, end, sentence) or None."""
    if already_links(raw, target):
        return None
    spans = [m.span() for m in RE_EXISTING_LINK.finditer(raw)]
    pat = re.compile(r"(?<![\w\[])" + re.escape(target) + r"(?![\w\]])", re.I)
    for m in pat.finditer(raw):
        s, e = m.span()
        if any(ls <= s < le for ls, le in spans):
            continue
        line_start = raw.rfind("\n", 0, s) + 1
        if raw[line_start:line_start + 1] in ("=", "|", "!"):
            continue
        ctx_s = max(0, s - 140); ctx_e = min(len(raw), e + 140)
        return s, e, raw[ctx_s:ctx_e].replace("\n", " ").strip()
    return None

proposals = []
per_page = defaultdict(int)
log("scanning for mentions")
for n, (a, ps) in enumerate(sorted(partners.items())):
    if not eligible(a):
        continue
    raw = read_raw(titles[a])
    if not raw:
        continue
    for b in ps:
        if len(titles[b]) < TITLE_MIN_LEN:
            continue
        pair = (min(a, b), max(a, b))
        sim = cands.get(pair)
        if sim is None or sim < SIM_MENTION:
            continue
        hit = find_mention(raw, titles[b])
        if not hit:
            continue
        s, e, ctx = hit
        surface = raw[s:e]
        # Two rules do nearly all the precision work, and neither is about the
        # model. `exactCase` asks whether the author wrote the phrase as a name
        # or merely used a common word: "…integrate the information" is not a
        # reference to [[Information]], and "…personal development" is not a
        # reference to [[Development]]. `multiWord` throws out the single common
        # nouns that overlinking is made of. Together they take 720 candidates
        # to 219, and that is the batch worth an editor's time.
        exact_case = surface == titles[b]
        multi_word = len(titles[b].split()) >= 2
        proposals.append({
            "exactCase": exact_case, "multiWord": multi_word,
            "strict": exact_case and multi_word,
            "kind": "mention",
            "from": a, "to": b,
            "fromTitle": titles[a], "toTitle": titles[b],
            "sim": round(sim, 3),
            "sharedCats": sorted(set(cats[a]) & set(cats[b])),
            "sharedFacets": sorted(set(facetOf[a]) & set(facetOf[b])),
            "sameRegion": region[a] == region[b],
            "navigable": already_navigable(a, b),
            "offset": s,
            "context": ctx,
            "edit": {"op": "wrap", "at": s, "old": raw[s:e],
                     "new": "[[" + raw[s:e] + "]]"},
        })
    if n % 4000 == 0:
        log("  scanned", n, "found", len(proposals))
log("mention proposals", len(proposals))

# --- see-also: the residue, held to a much higher bar -------------------
mentioned = {(p["from"], p["to"]) for p in proposals}
mentioned |= {(p["to"], p["from"]) for p in proposals}
see_also = []
for (a, b), sim in cands.items():
    if sim < SIM_SEE_ALSO or (a, b) in mentioned:
        continue
    if not (eligible(a) and eligible(b)):
        continue
    if already_navigable(a, b):
        continue
    if region[a] != region[b]:
        continue
    shared = sorted(set(cats[a]) & set(cats[b]))
    if not shared:
        continue                            # no curated evidence, only the model
    see_also.append({
        "kind": "see-also",
        "from": a, "to": b,
        "fromTitle": titles[a], "toTitle": titles[b],
        "sim": round(sim, 3),
        "sharedCats": shared,
        "sharedFacets": sorted(set(facetOf[a]) & set(facetOf[b])),
        "sameRegion": True,
        "navigable": False,
        "leadFrom": leads[a][:400], "leadTo": leads[b][:400],
        "edit": {"op": "see-also", "section": "See Also",
                 "new": "*[[" + titles[b] + "]]"},
    })
log("see-also candidates", len(see_also))

# --- cap so no page is swamped, strongest first -------------------------
def cap(items):
    kept, per = [], defaultdict(int)
    for p in sorted(items, key=lambda p: -p["sim"]):
        if per[p["from"]] >= MAX_PER_PAGE or per[p["to"]] >= MAX_PER_PAGE:
            continue
        per[p["from"]] += 1; per[p["to"]] += 1
        kept.append(p)
    return kept

strict = cap([p for p in proposals if p["strict"]])
proposals = cap(proposals)
see_also = cap(see_also)
log("after per-page cap:", len(proposals), "mention,", len(strict), "strict,",
    len(see_also), "see-also")

out = {
    "generatedAt": time.strftime("%Y-%m-%d"),
    "corpus": {"articles": N, "existingLinks": len(linked)},
    "thresholds": {"SIM_MENTION": SIM_MENTION, "SIM_SEE_ALSO": SIM_SEE_ALSO,
                   "MAX_PER_PAGE": MAX_PER_PAGE, "MAX_OUTDEG": MAX_OUTDEG,
                   "MIN_BYTES": MIN_BYTES, "TITLE_MIN_LEN": TITLE_MIN_LEN},
    "funnel": {"notLinked": len(cands), "mentionRaw": len(proposals),
               "strict": len(strict), "seeAlsoRaw": len(see_also)},
    "strict": strict,
    "mention": proposals,
    "seeAlso": see_also,
}
p = os.path.join(DATA, "proposals.json")
json.dump(out, open(p, "w", encoding="utf-8"), ensure_ascii=False, separators=(",", ":"))
log("written", os.path.getsize(p) // 1024, "KB ->", p)
