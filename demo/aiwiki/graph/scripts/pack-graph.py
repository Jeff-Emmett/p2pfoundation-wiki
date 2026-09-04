"""Pack the graph into what the page actually fetches.

Two tiers, because the page needs them at different moments:

  core.json      everything needed to draw and search the whole map — 3-D
                 positions, titles, regions, both edge relations, categories.
                 Fetched once, up front.
  leads/NN.json  the lead text of one region's articles. Fetched only when the
                 reader zooms into that region, because 39,915 leads is 15 MB and
                 nobody reads more than a handful.
  gists/NN.json  one sentence per article, same sharding. Separate from the
                 leads because these are drawn *on the canvas* at the near and
                 full bands, so they are needed for whatever happens to be in
                 view rather than for the one article a reader clicked — and a
                 420-character lead is 2.5x the bytes of the sentence a label
                 can actually show.

Everything numeric ships as base64 typed arrays. The corpus is under 65,536
articles, so every index fits in a uint16 — which halves the two largest arrays
(the k-NN table and the edge list) against the uint32 they would otherwise need.
"""
import base64, gzip, json, os, re, time
import numpy as np

SP = os.path.dirname(os.path.abspath(__file__))
DATA = os.path.join(SP, "..", "data")
OUT = os.path.join(SP, "..", "..", "dist-atlas")   # shipped to the wiki, not to Pages

T0 = time.time()
def log(*a): print("[%5.1fs]" % (time.time() - T0), *a, flush=True)
def b64(a): return base64.b64encode(np.ascontiguousarray(a).tobytes()).decode()

def trim(t, n=420):
    """Cut to a word boundary so a preview does not end mid-word."""
    if len(t) <= n:
        return t
    cut = t[:n]
    sp = cut.rfind(" ")
    # ".…" is what you get when the cut lands just after a full stop
    return (cut[:sp] if sp > n * 0.6 else cut).rstrip(" ,;:.") + "…"


# `==Description==` in the wikitext becomes `Description. ` in the lead, because
# extract-corpus flattens headings rather than dropping them — in a retrieval
# index the heading word is signal. On a label it is not: 13,082 of 38,595 gists
# opened on one, and "Video via Description." spends four words of the widest
# card the atlas draws saying nothing about the article.
# `= Description = open government advocate`, `URL = Description. "…"` — what a
# template the lead stripper could not unwrap leaves behind. Anything before the
# last such marker is field furniture, not prose.
FIELD = re.compile(r"^.{0,90}?=\s*")
HEADWORD = re.compile(r"^[A-Z][\w\u2019'\-]+$")
JOINER = {"via", "at", "and", "of", "the", "for", "in", "on", "from", "by", "to", "&"}
# `Description. D. Sadoway: "…"` — an initial is one capitalised token followed
# by a full stop, which is the exact shape of a flattened heading. So is an
# honorific. Neither is furniture, and eating one silently renames the person
# the sentence is about.
NOTHEAD = {"mr", "mrs", "ms", "dr", "prof", "st", "sr", "jr", "rev", "hon", "fr"}

def furniture(frag):
    words = frag.split()
    if not 1 <= len(words) <= 5:      # "Podcast at Video at Description." is five
        return False
    for w in words:
        if w.lower() in JOINER:
            continue
        if w.lower().rstrip(".") in NOTHEAD or not HEADWORD.match(w):
            return False
    return True

def dehead(t, floor=30):
    """Drop leading flattened section headings. Conservative on purpose: a
    fragment qualifies only if it is at most five words, every word is either
    capitalised or a joiner, it carries no punctuation of its own, and real
    prose survives underneath it. Stacked headings ("Video at Audio at
    Description.") are peeled one at a time, up to four deep."""
    if "=" in t[:90]:
        t = FIELD.sub("", t, count=1).strip()
    for _ in range(4):
        m = re.match(r"\s*([^.!?]{1,60})\.\s+(?=\S)", t)
        if not m:
            break
        if not furniture(m.group(1).strip()):
            break
        rest = t[m.end():]
        if len(rest) < floor:
            break
        t = rest
    return t.strip()


SENT = re.compile(r"(?<=[.!?])\s")

def gist(t, n=150):
    """The first sentence, for a label. Not a summary of the article — a
    summary would have to be written, and 39,915 of them written by a model is a
    claim about every article that nobody has checked. This is the article's own
    opening line, cut to something a card can hold."""
    t = dehead(" ".join(t.split()))
    if not t:
        return ""
    parts = SENT.split(t, 1)
    s = parts[0]
    if len(s) < 40 and len(parts) > 1:          # "Video via Description." alone says nothing
        s = s + " " + parts[1]
    # …and when there is no parts[1], it still says nothing. 1,800 articles are
    # a bare heading and an external link the stripper removed; a label reading
    # "Video via" is worse than no label, because it occupies the slot.
    if furniture(s.rstrip(".")):
        return ""
    return trim(s, n)


C = json.load(gzip.open(os.path.join(DATA, "corpus.json.gz"), "rt", encoding="utf-8"))
NB = json.load(open(os.path.join(DATA, "neighbours.json"), encoding="utf-8"))
PL = json.load(open(os.path.join(DATA, "plane3.json"), encoding="utf-8"))

titles, cats, links, leads, sizes = C["titles"], C["cats"], C["links"], C["leads"], C["sizes"]
N = len(titles)
assert N < 65536, "index no longer fits in uint16"
region = np.array(NB["region"], np.uint8)
K = len(NB["regionLabels"])
log("articles", N, "regions", K)

# ---- categories as a shared string table --------------------------------
cat_id, cat_names, per_page = {}, [], []
for cs in cats:
    row = []
    for c in cs[:6]:
        j = cat_id.get(c)
        if j is None:
            j = cat_id[c] = len(cat_names); cat_names.append(c)
        row.append(j)
    per_page.append(row)

# ---- edges ---------------------------------------------------------------
# Wikilinks, deduplicated to undirected pairs: the page draws them as one line,
# and direction is shown in the panel rather than on the canvas.
seen, ea, eb = set(), [], []
for a, outs in enumerate(links):
    for b in outs:
        k = (a, b) if a < b else (b, a)
        if k in seen:
            continue
        seen.add(k); ea.append(k[0]); eb.append(k[1])
log("wikilink edges", len(ea))

knn = np.array(NB["knnIdx"], np.uint16)
sim = np.clip(np.round(np.array(NB["knnSim"], np.float32) * 255), 0, 255).astype(np.uint8)

indeg = np.zeros(N, np.uint16)
for outs in links:
    for b in outs:
        indeg[b] = min(65535, int(indeg[b]) + 1)
outdeg = np.array([min(65535, len(o)) for o in links], np.uint16)

# The nine reading-room lenses, as a bitmask per article — 9 bits fits a uint16,
# so a lens filter is one AND per node instead of a set lookup.
AIW = os.path.join(DATA, "..", "..", "data", "aiwiki-data.json")
facets = json.load(open(AIW, encoding="utf-8"))["facets"] if os.path.exists(AIW) else []
lens = np.zeros(N, np.uint16)
for fi, f in enumerate(facets):
    kws = [k.lower() for k in f["keywords"]]
    for i, cs in enumerate(cats):
        if any(k in c for k in kws for c in (x.lower() for x in cs)):
            lens[i] |= (1 << fi)
log("lenses", len(facets), "unfiled", int((lens == 0).sum()))

core = {
    "n": N, "k": K,
    "titles": "\n".join(titles),
    "x": b64(np.array(PL["x"], np.uint16)),
    "y": b64(np.array(PL["y"], np.uint16)),
    "z": b64(np.array(PL["z"], np.uint16)),
    "region": b64(region),
    "indeg": b64(indeg), "outdeg": b64(outdeg),
    "bytes": b64(np.clip(np.array(sizes, np.int64) // 64, 0, 65535).astype(np.uint16)),
    "lens": b64(lens),
    "knn": b64(knn), "knnSim": b64(sim), "knnK": knn.shape[1],
    "ea": b64(np.array(ea, np.uint16)), "eb": b64(np.array(eb, np.uint16)),
    "catNames": cat_names,
    "cats": "\n".join(",".join(str(j) for j in r) for r in per_page),
    "regionLabels": NB["regionLabels"],
    "regionSizes": NB["regionSizes"],
    "centroids": PL["centroids"],
    "radii": PL["radii"],
    "facets": [{"id": f["id"], "label": f["label"]} for f in facets],
    "stats": {"articles": N, "links": len(ea), "categories": len(cat_names),
              "regions": K, "redirects": C["nRedirects"],
              "isolated": int((indeg == 0).sum() & 0xffffffff)},
}
os.makedirs(os.path.join(OUT, "leads"), exist_ok=True)
os.makedirs(os.path.join(OUT, "gists"), exist_ok=True)
p = os.path.join(OUT, "core.json")
json.dump(core, open(p, "w", encoding="utf-8"), ensure_ascii=False, separators=(",", ":"))
log("core.json", os.path.getsize(p) // 1024, "KB")

reg = np.array(NB["region"])
for k in range(K):
    idx = np.where(reg == k)[0]
    # 420 characters, not the 900 the corpus holds: this is a panel preview
    # with "read on the wiki" one click away, and region 8 alone is 6,724
    # articles — the difference is a 4.5 MB fetch against a 2 MB one.
    shard = {str(int(i)): trim(dehead(" ".join(leads[i].split()))) for i in idx if leads[i]}
    q = os.path.join(OUT, "leads", "%02d.json" % k)
    json.dump(shard, open(q, "w", encoding="utf-8"), ensure_ascii=False, separators=(",", ":"))
log("leads: %d shards, %d KB total" % (K, sum(
    os.path.getsize(os.path.join(OUT, "leads", f))
    for f in os.listdir(os.path.join(OUT, "leads"))) // 1024))

for k in range(K):
    idx = np.where(reg == k)[0]
    shard = {}
    for i in idx:
        g = gist(leads[i]) if leads[i] else ""
        if g:
            shard[str(int(i))] = g
    json.dump(shard, open(os.path.join(OUT, "gists", "%02d.json" % k), "w", encoding="utf-8"),
              ensure_ascii=False, separators=(",", ":"))
log("gists: %d shards, %d KB total" % (K, sum(
    os.path.getsize(os.path.join(OUT, "gists", f))
    for f in os.listdir(os.path.join(OUT, "gists"))) // 1024))
