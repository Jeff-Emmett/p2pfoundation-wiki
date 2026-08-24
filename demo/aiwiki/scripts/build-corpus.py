#!/usr/bin/env python3
"""
Build the aiwiki demo dataset from the LIVE P2P Foundation wiki.

Everything the demo page shows is computed here from real wiki content using the
same algorithms Portico runs in production (rspace-online/server/portico/*):

  taxonomy-p2p.ts    the nine reading-room lenses + facetsForCategories()
  render.ts          stripMarkup() / toChunks() / the degradation ladder
  hybrid-retriever   lexical ∪ semantic union, origin+rank preserved
  reranker.ts        Reciprocal Rank Fusion, RRF_K = 60
  rank-signals.ts    saturate(v, half); link-centrality half = 5, weight 0.3
  grounding.ts       significant-word coverage; grounded ≥ .6, uncertain ≥ .3

Semantic retrieval uses real 768-dim embeddings from the GX10 LiteLLM stack
(model `gx10-embed`) — the same dimensionality as Portico's production embedder.
The generated answer in the grounding chapter is a real completion from
`gx10-general`, not a written-out script.

Usage:  python3 scripts/build-corpus.py [--skip-fetch]
Output: data/aiwiki-data.json
"""

import json, math, os, re, subprocess, sys, time
from collections import Counter

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
DATA = os.path.join(ROOT, "data")
CACHE = os.path.join(DATA, "_cache")
API = "https://wiki.p2pfoundation.net/api.php"
LITELLM = os.environ.get("LITELLM_BASE_URL", "http://100.64.0.5:4001")
# Read from a chmod-600 file (same one the litellm MCP server uses) so the key is
# never an argv value, never echoed, and never lands in a transcript or shell history.
LITELLM_KEY_FILE = os.environ.get(
    "LITELLM_API_KEY_FILE", os.path.expanduser("~/.private/claude-mcp/gx10-recommender-key"))

_AUTH_MODE = None   # None = not yet probed, [] = anonymous, [...] = bearer header

def _litellm_key():
    key = (os.environ.get("LITELLM_API_KEY") or "").strip()
    if not key and os.path.exists(LITELLM_KEY_FILE):
        try: key = open(LITELLM_KEY_FILE).read().strip()
        except OSError: key = ""
    return key

def llm_post(path, payload):
    """POST to LiteLLM, negotiating auth ONCE.

    The GX10 proxy currently accepts anonymous calls on the tailnet, and the key
    file the litellm MCP server uses has gone stale — sending a rejected key is
    strictly worse than sending none. So: try the key if there is one, and on an
    auth error fall back to anonymous and remember that for the rest of the run.
    The key value is never printed, logged, or passed as an argv value.
    """
    global _AUTH_MODE
    key = _litellm_key()
    last = None
    # The GX10 proxy flaps: it intermittently 401s BOTH a keyed and an anonymous
    # call (its verification-token cache repopulates after a restart), so a single
    # auth failure says nothing about whether the key is good. Retry the whole
    # ladder with backoff before giving up.
    for round_ in range(8):
        # Anonymous FIRST, deliberately. The GX10 proxy accepts anonymous calls on
        # the tailnet, and the key file the litellm MCP server uses has gone stale —
        # presenting a rejected key makes the whole exchange fail where sending
        # nothing succeeds. The key is only a fallback for a proxy that demands one.
        attempts = ([_AUTH_MODE] if _AUTH_MODE is not None
                    else ([[], ["-H", "Authorization: Bearer " + key]] if key else [[]]))
        for auth in attempts:
            r = subprocess.run(["curl", "-s", "-m", "300", f"{LITELLM}{path}",
                                "-H", "Content-Type: application/json", *auth, "-d", payload],
                               capture_output=True)
            try: d = json.loads(r.stdout)
            except Exception: last = r.stdout[:200]; continue
            err = (d.get("error") or {}).get("type", "") if isinstance(d.get("error"), dict) else ""
            if err in ("auth_error", "token_not_found_in_db"):
                last = d
                _AUTH_MODE = None   # a cached mode that just failed is not trustworthy
                continue
            _AUTH_MODE = auth
            return d
        time.sleep(min(3 * (round_ + 1), 15))
    raise RuntimeError(f"LiteLLM {path} failed after retries: {str(last)[:280]}")
FEATURED = "Open Source"

os.makedirs(CACHE, exist_ok=True)

# NOTE: a custom User-Agent trips Cloudflare's bot challenge on this host, and
# urllib is blocked outright — curl with its default UA is the path that works.
def api(params):
    params = dict(params); params["format"] = "json"
    from urllib.parse import urlencode
    url = API + "?" + urlencode(params)
    for attempt in range(4):
        r = subprocess.run(["curl", "-s", "-m", "60", "--compressed", url], capture_output=True)
        if r.returncode == 0 and r.stdout.strip().startswith(b"{"):
            return json.loads(r.stdout)
        time.sleep(1.5 * (attempt + 1))
    raise RuntimeError("wiki api failed: " + url[:180])


# ── Portico: server/portico/taxonomy-p2p.ts ───────────────────────────────
FACETS = [
 {"id":"p2p-paradigms","label":"Understanding P2P Dynamics & the Commons in the Digital Age",
  "keywords":["peer production","peer governance","peer property","cooperation","sharing","p2p theory","paradigm","commons transition"]},
 {"id":"three-applications","label":"How P2P & the Commons Change Human Institutions",
  "keywords":["civil society","market approach","state approach","p2p state","p2p market","transitioning","institution"]},
 {"id":"hot-topics","label":"Towards an Ecologically Stable Civilization",
  "keywords":["thermodynamic","p2p accounting","accounting","mutual coordination","urban commons","carrying capacity","externalities","ecological"]},
 {"id":"peer-driven-economy","label":"The Emerging Collaborative Economy",
  "keywords":["collaborative econom","open company","mutualize","open business","crowdfunding","p2p finance","value metrics","open accounting","legal infrastructure","shared innovation","distributed manufacturing","open manufacturing","cooperative"]},
 {"id":"new-p2p-culture","label":"Contemporary Expressions of P2P & Commons Culture",
  "keywords":["art","collective intelligence","culture","education","learning","facilitation","media","relationships","spirituality"]},
 {"id":"provisioning-systems","label":"Commons-Based Provisioning Systems",
  "keywords":["food","water","air","clothing","energy","health care","healthcare","housing","shelter","transportation","provisioning"]},
 {"id":"domains","label":"Applying P2P to the Domains of Life",
  "keywords":["community economics","ecology","sustainab","economics","gaming","metaverse","geography","mapping","labor","licensing","money","finance","music","open standards","policy","politics","science","security","warfare","taxation","technology","villages","cities"]},
 {"id":"how-p2p","label":"The Commons-Based Society",
  "keywords":["p2p in business","p2p in governance","intellectual property","how p2p","influences society"]},
 {"id":"p2p-perspectives","label":"What Comes After — New Models of Organizing Society",
  "keywords":["post-growth","post growth","post-capitalist","post capitalist","post-corporate","post corporate","perspective"]},
]

def facets_for(categories):
    """facetsForCategories() — case-insensitive substring, union across categories."""
    cats = [c.lower() for c in categories]
    return [f["id"] for f in FACETS
            if any(any(kw in cat for kw in f["keywords"]) for cat in cats)]


# ── Portico: server/portico/render.ts ─────────────────────────────────────
def strip_markup(t):
    t = re.sub(r"\[\[File:[^\]]*\]\]", "", t)
    t = re.sub(r"\[\[Category:[^\]]*\]\]", "", t)
    t = re.sub(r"\[\[(?:[^\]|]*\|)?([^\]]*)\]\]", r"\1", t)      # [[link|label]] -> label
    t = re.sub(r"^={2,}\s*(.+?)\s*={2,}\s*$", r"\1", t, flags=re.M)
    t = re.sub(r"'''?", "", t)                                    # bold / italic ticks
    t = re.sub(r"\[https?://\S+\s+([^\]]+)\]", r"\1", t)
    t = re.sub(r"\[https?://\S+\]", "", t)
    t = re.sub(r"<ref[^>]*>.*?</ref>", "", t, flags=re.S)
    t = re.sub(r"<[^>]+>", "", t)
    return t.strip()

HEADING_RE = re.compile(r"^(?:={2,}\s*(.+?)\s*={2,}|#{1,6}\s+(.+?))\s*$")

def to_chunks(text):
    chunks, heading, buf = [], "", []
    def flush():
        body = "\n".join(buf).strip()
        if body: chunks.append({"heading": heading, "text": body})
    for line in text.split("\n"):
        m = HEADING_RE.match(line.strip())
        if m:
            flush(); buf.clear(); heading = (m.group(1) or m.group(2) or "").strip()
        else:
            buf.append(line)
    flush()
    return chunks

def flat(t):
    return re.sub(r"\s+", " ", t).strip()


# ── fetch ─────────────────────────────────────────────────────────────────
def fetch_pages(titles):
    out, B = {}, 12
    for i in range(0, len(titles), B):
        chunk = [t.strip() for t in titles[i:i+B] if t.strip()]
        if not chunk: continue
        d = api({"action":"query", "titles":"|".join(chunk),
                 "prop":"categories|links|info|revisions",
                 "cllimit":"max", "clshow":"!hidden",
                 "pllimit":"max", "plnamespace":0,
                 "rvprop":"timestamp|user|content", "rvslots":"main", "redirects":1})
        for pid, p in d.get("query", {}).get("pages", {}).items():
            if int(pid) < 0: continue
            rev = (p.get("revisions") or [{}])[0]
            content = ((rev.get("slots") or {}).get("main") or {}).get("*", "") or rev.get("*", "")
            out[p["title"]] = {
                "pageid": p["pageid"], "title": p["title"], "wikitext": content[:9000],
                "bytes": p.get("length"),
                "categories": [c["title"].replace("Category:", "") for c in p.get("categories", [])],
                "links": [l["title"] for l in p.get("links", [])],
                "lastrev": rev.get("timestamp"), "lastuser": rev.get("user"),
            }
        print(f"  pages {len(out)}/{len(titles)}", file=sys.stderr)
        time.sleep(0.3)
    return out

def fetch_backlinks(titles):
    """In-degree over the WHOLE wiki, not just the demo set — the honest authority signal."""
    bl = {}
    for i, title in enumerate(titles):
        names, cont = [], None
        for _ in range(6):
            params = {"action":"query","list":"backlinks","bltitle":title,"bllimit":"500",
                      "blnamespace":0,"blfilterredir":"nonredirects"}
            if cont: params["blcontinue"] = cont
            d = api(params)
            names += [b["title"] for b in d.get("query", {}).get("backlinks", [])]
            cont = d.get("continue", {}).get("blcontinue")
            if not cont: break
        bl[title] = {"count": len(names), "sample": names[:400]}
        print(f"  backlinks {i+1}/{len(titles)} {title}: {len(names)}", file=sys.stderr)
        time.sleep(0.15)
    return bl

def fetch_stats():
    d = api({"action":"query","meta":"siteinfo","siprop":"statistics|general"})
    s = d["query"]["statistics"]; g = d["query"]["general"]
    return {"pages":s["pages"],"articles":s["articles"],"edits":s["edits"],
            "users":s["users"],"images":s["images"],"admins":s["admins"],
            "generator":g["generator"],"host":"wiki.p2pfoundation.net"}


# ── embeddings + completion (GX10 LiteLLM) ────────────────────────────────
def embed(texts, model="gx10-embed"):
    import hashlib
    cache_p = os.path.join(CACHE, "embeddings.json")
    cache = json.load(open(cache_p)) if os.path.exists(cache_p) else {}
    keys = [hashlib.sha256((model + "\x00" + t).encode()).hexdigest()[:32] for t in texts]
    todo = [(k, t) for k, t in zip(keys, texts) if k not in cache]
    if todo:
        _embed_uncached(todo, cache, cache_p, model)
    return [cache[k] for k in keys]


def _embed_uncached(todo, cache, cache_p, model):
    B = 16
    for i in range(0, len(todo), B):
        batch = todo[i:i+B]
        d = llm_post("/v1/embeddings", json.dumps({"model": model, "input": [t for _, t in batch]}))
        if "data" not in d: raise RuntimeError("embed failed: " + str(d)[:300])
        vecs = [e["embedding"] for e in sorted(d["data"], key=lambda x: x["index"])]
        for (k, _), v in zip(batch, vecs): cache[k] = v
        json.dump(cache, open(cache_p, "w"))
        print(f"  embedded {min(i+B, len(todo))}/{len(todo)} (new)", file=sys.stderr)


def _embed_unused(texts, model="gx10-embed"):
    out, B = [], 16
    for i in range(0, len(texts), B):
        d = llm_post("/v1/embeddings", json.dumps({"model": model, "input": texts[i:i+B]}))
        if "data" not in d: raise RuntimeError("embed failed: " + str(d)[:300])
        out += [e["embedding"] for e in sorted(d["data"], key=lambda x: x["index"])]
        print(f"  embedded {len(out)}/{len(texts)}", file=sys.stderr)
    return out

def complete(prompt, model="gx10-general", max_tokens=400):
    d = llm_post("/v1/chat/completions", json.dumps(
        {"model": model, "messages":[{"role":"user","content":prompt}],
         "temperature":0.3, "max_tokens":max_tokens}))
    if "choices" not in d: raise RuntimeError("completion failed: " + str(d)[:300])
    return re.sub(r"<think>.*?</think>", "", d["choices"][0]["message"]["content"], flags=re.S).strip()

def cosine(a, b):
    n = sum(x*y for x, y in zip(a, b))
    na = math.sqrt(sum(x*x for x in a)); nb = math.sqrt(sum(x*x for x in b))
    return n/(na*nb) if na and nb else 0.0


# ── Portico: grounding.ts ─────────────────────────────────────────────────
G_STOP = set("""the a an and or but of to in on at for with as is are was were be been being it its
this that these those by from has have had not no can will would which their they them there then
than also such into about""".split())
GROUNDED_AT, UNCERTAIN_AT = 0.6, 0.3

def g_tokens(text):
    return {w for w in re.split(r"[^a-z0-9]+", text.lower()) if len(w) >= 3 and w not in G_STOP}

def split_claims(answer):
    return [s.strip() for s in re.split(r"(?<=[.!?])\s+", answer)
            if s.strip() and len(s.split()) >= 4]

def ground_answer(answer, sources):
    claims = []
    for text in split_claims(answer):
        ct = g_tokens(text); best = 0.0; cites = []
        for s in sources:
            score = (len(ct & g_tokens(s["text"])) / len(ct)) if ct else 0.0
            if score >= UNCERTAIN_AT: cites.append(s["id"])
            best = max(best, score)
        status = ("grounded" if best >= GROUNDED_AT
                  else "uncertain" if best >= UNCERTAIN_AT else "unsupported")
        claims.append({"text": text, "grounding_status": status,
                       "citations": cites, "score": round(best, 4)})
    return claims


# ── BM25 (the lexical origin) ─────────────────────────────────────────────
L_STOP = set("""the a an and or of in on to for is are was were be been by with as at from that this
it its their our we you they not but if then than so such which who whom whose what when where how
also more most other some any all can may will would could should have has had do does did into over
under about between within without across per via""".split())

def l_tokens(s):
    return [w for w in re.findall(r"[a-z0-9]+", s.lower()) if w not in L_STOP and len(w) > 2]


# ── Portico: reranker.ts / rank-signals.ts ────────────────────────────────
RRF_K = 60
CENTRALITY_HALF = 5
DERIVATION_HALF = 2
W = {"rrf": 1.0, "link-centrality": 0.3, "derivation-authority": 0.5, "overlay-curation": 1.0}

def saturate(v, half):
    return v/(v+half) if v > 0 and half > 0 else 0.0


QUERIES = [
 {"id":"q-govern",    "q":"how do communities govern a shared resource without privatising it"},
 {"id":"q-cosmo",     "q":"design global, manufacture local"},
 {"id":"q-bauwens",   "q":"Michel Bauwens"},
 {"id":"q-account",   "q":"accounting for contributions in a commons economy"},
 {"id":"q-provision", "q":"energy and food provisioning as commons"},
 {"id":"q-after",     "q":"what comes after capitalism"},
]


def main():
    skip = "--skip-fetch" in sys.argv
    seeds = [l.strip() for l in open(os.path.join(HERE, "seed-articles.txt")) if l.strip()]

    raw_p = os.path.join(CACHE, "pages.json")
    bl_p = os.path.join(CACHE, "backlinks.json")
    st_p = os.path.join(CACHE, "stats.json")

    if skip and os.path.exists(raw_p):
        raw = json.load(open(raw_p)); bl = json.load(open(bl_p)); stats = json.load(open(st_p))
    else:
        print("fetching wiki statistics…", file=sys.stderr); stats = fetch_stats()
        print("fetching articles…", file=sys.stderr);        raw = fetch_pages(seeds)
        print("fetching backlinks…", file=sys.stderr);       bl = fetch_backlinks(sorted(raw))
        json.dump(raw, open(raw_p, "w")); json.dump(bl, open(bl_p, "w"))
        json.dump(stats, open(st_p, "w"))

    # ---- documents ----
    docs = []
    for title, p in sorted(raw.items()):
        chunks = to_chunks(p["wikitext"])
        lead = ""
        for ch in chunks:
            c = flat(strip_markup(ch["text"]))
            if len(c) > 90: lead = c; break
        if not lead: lead = flat(strip_markup(p["wikitext"]))[:400]
        docs.append({
            "id": f"p2pwiki:{p['pageid']}", "pageid": p["pageid"], "title": title,
            "url": "https://wiki.p2pfoundation.net/" + title.replace(" ", "_"),
            "bytes": p.get("bytes") or 0, "rev": p.get("lastrev"), "revuser": p.get("lastuser"),
            "categories": p["categories"], "facets": facets_for(p["categories"]),
            "lead": lead[:520],
            "sections": [c["heading"] for c in chunks if c["heading"]][:12],
            "nchunks": len([c for c in chunks if c["text"].strip()]),
            "backlinks": bl.get(title, {}).get("count", 0),
            "_text": (title + " " + flat(strip_markup(p["wikitext"])))[:6000],
            "_out": p["links"],
        })

    titles = {d["title"] for d in docs}
    for d in docs:
        d["edges_in"] = sorted({t for t in bl.get(d["title"], {}).get("sample", [])
                                if t in titles and t != d["title"]})
        d["edges_out"] = sorted({t for t in d.pop("_out") if t in titles and t != d["title"]})

    mx = max(math.log1p(d["backlinks"]) for d in docs) or 1
    for d in docs:
        d["authority"] = round(math.log1p(d["backlinks"]) / mx, 4)

    # ---- retrieval: real BM25 ∪ real cosine, fused by real RRF ----
    print("embedding corpus…", file=sys.stderr)
    dvecs = embed([f"{d['title']}. {' '.join(d['categories'])}. {d['lead']}" for d in docs])
    qvecs = embed([q["q"] for q in QUERIES])

    corpus = [l_tokens(d["_text"]) for d in docs]
    N = len(corpus); avgdl = sum(len(c) for c in corpus)/N
    df = Counter()
    for c in corpus:
        for w in set(c): df[w] += 1
    k1, b = 1.5, 0.75
    def bm25(qt, i):
        tf = Counter(corpus[i]); dl = len(corpus[i]); s = 0.0
        for w in qt:
            if w not in tf: continue
            idf = math.log(1 + (N - df[w] + 0.5)/(df[w] + 0.5))
            s += idf * (tf[w]*(k1+1))/(tf[w] + k1*(1 - b + b*dl/avgdl))
        return s

    indeg = {d["title"]: len(d["edges_in"]) for d in docs}
    retrieval, K = {}, 12
    for qi, q in enumerate(QUERIES):
        qt = l_tokens(q["q"])
        lex = sorted(range(N), key=lambda i: -bm25(qt, i))[:K]
        sem = sorted(range(N), key=lambda i: -cosine(qvecs[qi], dvecs[i]))[:K]
        ranks = {}
        for r, i in enumerate(lex, 1): ranks.setdefault(i, []).append({"origin":"lexical","rank":r})
        for r, i in enumerate(sem, 1): ranks.setdefault(i, []).append({"origin":"semantic","rank":r})
        cands = []
        for i, rk in ranks.items():
            rrf = sum(1/(RRF_K + r["rank"]) for r in rk) * (RRF_K + 1)
            cent = saturate(indeg[docs[i]["title"]], CENTRALITY_HALF)
            cands.append({
                "title": docs[i]["title"], "id": docs[i]["id"], "ranks": rk,
                "bm25": round(bm25(qt, i), 3), "cos": round(cosine(qvecs[qi], dvecs[i]), 4),
                "rrf": round(rrf, 4), "centrality": round(cent, 4),
                "composite": round(rrf*W["rrf"] + cent*W["link-centrality"], 4),
                "origins": sorted({r["origin"] for r in rk}),
            })
        cands.sort(key=lambda c: -c["composite"])
        retrieval[q["id"]] = {
            "query": q["q"],
            "lexical": [{"title": docs[i]["title"], "bm25": round(bm25(qt, i), 3)} for i in lex],
            "semantic": [{"title": docs[i]["title"], "cos": round(cosine(qvecs[qi], dvecs[i]), 4)} for i in sem],
            "fused": cands,
        }

    # ---- grounding: one real answer per disclosure band ----
    by_title = {d["title"]: d for d in docs}
    top = [c["title"] for c in retrieval["q-govern"]["fused"][:5]]
    question = retrieval["q-govern"]["query"]

    def run_ground(sources, label):
        ctx = "\n\n".join(f"[{i+1}] {s['title']}\n{s['text']}" for i, s in enumerate(sources))
        prompt = (f"Answer the question using ONLY the numbered sources. 4-6 sentences, plain prose, "
                  f"no bullet points, no citation markers.\n\nSOURCES:\n{ctx}\n\n"
                  f"QUESTION: {question}\n\nANSWER:")
        answer = complete(prompt)
        claims = ground_answer(answer, sources)
        cov = sum(1 for c in claims if c["grounding_status"] == "grounded")/max(len(claims), 1)
        print(f"  ground[{label}]: {len(claims)} claims, coverage {cov:.2f}", file=sys.stderr)
        return {"question": question, "answer": answer, "band": label,
                "sources": [{**s, "text": s["text"][:900]} for s in sources],
                "claims": claims, "coverage": round(cov, 4)}

    print("grounding (near band)…", file=sys.stderr)
    near_src = [{"id": by_title[t]["id"], "title": t, "text": by_title[t]["lead"]} for t in top]
    g_near = run_ground(near_src, "near")

    print("grounding (full band)…", file=sys.stderr)
    full_src = [{"id": by_title[t]["id"], "title": t,
                 "text": flat(strip_markup(raw[t]["wikitext"]))[:2600]} for t in top]
    g_full = run_ground(full_src, "full")

    # ---- render forms of the featured article ----
    wt = raw[FEATURED]["wikitext"]
    plain = flat(strip_markup(wt))
    render = {
        "title": FEATURED, "pageid": raw[FEATURED]["pageid"],
        "wikitext": wt[:2600],
        "forms": {
            "text": plain[:1000],
            "markdown": wt[:1000],
            "excerpt": plain[:280],
            "chunks": [{"heading": c["heading"], "text": flat(strip_markup(c["text"]))[:420]}
                       for c in to_chunks(wt) if strip_markup(c["text"]).strip()][:8],
        },
        # render.ts FALLBACK — every chain terminates at `text`, which never fails
        "ladder": {"translation":"markdown","summary":"excerpt","markdown":"text","chunks":"text",
                   "excerpt":"text","okf":"markdown","opds":"markdown","text":"text"},
    }

    for d in docs: d.pop("_text", None)

    out = {
        "wiki": stats,
        "fetchedAt": time.strftime("%Y-%m-%d"),
        "ingestLicense": "CC-BY-SA-3.0",       # server/portico/provenance.ts default
        "featured": FEATURED,
        "facets": FACETS,
        "docs": docs,
        "retrieval": retrieval,
        "grounding": {"near": g_near, "full": g_full},
        "render": render,
        # server/portico/lod-band.ts — snippet budget and disclosure charge per band
        "bands": {"far":{"chars":0,"cost":0.25}, "mid":{"chars":240,"cost":0.5},
                  "near":{"chars":None,"cost":1}, "full":{"chars":None,"cost":3}},
        "constants": {"RRF_K":RRF_K, "CENTRALITY_HALF":CENTRALITY_HALF,
                      "DERIVATION_HALF":DERIVATION_HALF, "GROUNDED_AT":GROUNDED_AT,
                      "UNCERTAIN_AT":UNCERTAIN_AT, "SNIPPET_CHARS":1500, "weights":W},
    }
    dst = os.path.join(DATA, "aiwiki-data.json")
    json.dump(out, open(dst, "w"), separators=(",", ":"))
    print(f"\n✓ {dst}  ({os.path.getsize(dst)//1024} KB, {len(docs)} articles)", file=sys.stderr)
    print(f"  unfiled (no lens matched): {sum(1 for d in docs if not d['facets'])}", file=sys.stderr)


if __name__ == "__main__":
    main()
