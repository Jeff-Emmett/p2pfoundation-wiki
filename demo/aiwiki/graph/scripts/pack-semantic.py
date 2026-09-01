"""Ship the semantic space itself, so the browser can re-lay-out the corpus.

Highlighting a query is cheap: match strings, colour the hits. Re-organising by
a query is a different thing — every article has to be *placed* by how close it
is to what you asked, which means the browser needs (a) each article's vector
and (b) a way to turn typed words into a vector in the same basis.

Both come out of the SVD the map was already built from, so an axis measures
the same notion of similarity the positions do rather than a second, unrelated
one bolted on beside it:

  vecs.bin   39,915 x 32 int8   the articles, first 32 SVD components
  terms.bin  15,000 x 32 int16  idf * projection, one row per vocabulary term

A query is folded in the standard LSA way: sum the rows of its terms, normalise,
then cosine against the article matrix. Two matrix ops in JS, no server.

This is fetched only when someone actually opens the reorganise controls.
"""
import json, os, struct, time
import numpy as np

SP = os.path.dirname(os.path.abspath(__file__))
DATA = os.path.join(SP, "..", "data")
OUT = os.path.join(SP, "..", "..", "dist", "graph")
DIMS = 32          # of 80. The tail components split hairs an axis cannot show.
N_TERMS = 15000    # by document frequency; the tail is rare tokens nobody types

T0 = time.time()
def log(*a): print("[%5.1fs]" % (time.time() - T0), *a, flush=True)

Z = np.load(os.path.join(DATA, "svd.npy"))[:, :DIMS]
comp = np.load(os.path.join(DATA, "comp_text.npy"))[:DIMS]      # (DIMS, n_text)
V = json.load(open(os.path.join(DATA, "vocab.json"), encoding="utf-8"))
terms, idf = V["terms"], np.array(V["idf"], np.float32)
log("articles", Z.shape, "vocab", len(terms))

# --- articles -----------------------------------------------------------
a_scale = float(np.abs(Z).max()) / 127.0
A8 = np.clip(np.round(Z / a_scale), -127, 127).astype(np.int8)

# --- terms --------------------------------------------------------------
# idf = ln((1+n)/(1+df)) + 1, so df falls out of it; keep the most common terms
# because those are the ones a person actually types.
n_docs = Z.shape[0]
df = (1.0 + n_docs) / np.exp(idf - 1.0) - 1.0
keep = np.argsort(-df)[:N_TERMS]
keep = keep[np.argsort([terms[i] for i in keep])]          # sorted for lookup
rows = (comp[:, keep] * idf[keep]).T.astype(np.float32)    # (N_TERMS, DIMS)
t_scale = float(np.abs(rows).max()) / 32767.0
T16 = np.clip(np.round(rows / t_scale), -32767, 32767).astype(np.int16)
log("terms kept", len(keep), "df range %.0f..%.0f" % (df[keep].min(), df[keep].max()))

os.makedirs(OUT, exist_ok=True)
open(os.path.join(OUT, "vecs.bin"), "wb").write(A8.tobytes())
open(os.path.join(OUT, "terms.bin"), "wb").write(T16.tobytes())
json.dump({"dims": DIMS, "n": int(Z.shape[0]), "nTerms": int(len(keep)),
           "articleScale": a_scale, "termScale": t_scale,
           "terms": [terms[i] for i in keep]},
          open(os.path.join(OUT, "semantic.json"), "w", encoding="utf-8"),
          ensure_ascii=False, separators=(",", ":"))
for f in ("vecs.bin", "terms.bin", "semantic.json"):
    log(f, os.path.getsize(os.path.join(OUT, f)) // 1024, "KB")
