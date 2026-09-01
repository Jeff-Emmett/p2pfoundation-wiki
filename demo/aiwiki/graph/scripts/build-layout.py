"""Lay the corpus out semantically and compute both edge relations.

Position comes from what the articles are about: TF-IDF over the wikitext,
concatenated with category membership (weighted up, because categories are
curated and the text is not) and with the link adjacency (weighted down,
because only a third of the corpus has any links at all). SVD to 80 dims,
KMeans for regions, t-SNE for the plane.

Two edge sets come out, and they are different kinds of claim:
  * `link`     — a wikilink an editor actually wrote. Sparse: 64% of articles
                 have none. Ground truth about intent, silent about the rest.
  * `semantic` — mutual k-nearest-neighbours in the SVD space. Dense and
                 complete, but computed: it asserts similarity, not intent.
Where they coincide is the only place both agree; §3 of the narrative is about
exactly that disagreement.

Output: data/layout.json
"""
import gzip, json, os, re, sys, time
import numpy as np
from scipy import sparse
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.decomposition import TruncatedSVD
from sklearn.cluster import KMeans
from sklearn.manifold import TSNE
from sklearn.preprocessing import normalize

SP = os.path.dirname(os.path.abspath(__file__))
DATA = os.path.join(SP, "..", "data")
WIKI = "/home/jeffe/Github/p2pfoundation-wiki/wiki"
AIWIKI_DATA = os.path.join(SP, "..", "..", "data", "aiwiki-data.json")

N_REGIONS = 24
SVD_DIMS = 80
KNN = 6

T0 = time.time()
def log(*a): print("[%6.1fs]" % (time.time() - T0), *a, flush=True)

c = json.load(gzip.open(os.path.join(DATA, "corpus.json.gz"), "rt", encoding="utf-8"))
titles, cats, links, leads, sizes = c["titles"], c["cats"], c["links"], c["leads"], c["sizes"]
N = len(titles)
log("corpus", N, "articles")

# ---- category matrix ---------------------------------------------------
cat_index, cat_names = {}, []
rows, cols = [], []
for i, cs in enumerate(cats):
    for name in cs:
        j = cat_index.get(name)
        if j is None:
            j = cat_index[name] = len(cat_names); cat_names.append(name)
        rows.append(i); cols.append(j)
C = sparse.csr_matrix((np.ones(len(rows), np.float32), (rows, cols)),
                      shape=(N, len(cat_names)))
log("categories", len(cat_names), "memberships", len(rows))

# ---- adjacency (symmetric: a link is evidence in both directions) ------
er, ec = [], []
for i, out in enumerate(links):
    for j in out:
        er.append(i); ec.append(j)
        er.append(j); ec.append(i)
A = sparse.csr_matrix((np.ones(len(er), np.float32), (er, ec)), shape=(N, N))
log("adjacency nnz", A.nnz)

# ---- article text ------------------------------------------------------
WIKI_NOISE = re.compile(r"(\[\[File:[^\]]*\]\]|\{\{[^}]*\}\}|https?://\S+|[\[\]{}|=*#'])")
def read_text(title):
    path = os.path.join(WIKI, title + ".mediawiki")
    try:
        with open(path, encoding="utf-8", errors="ignore") as fh:
            raw = fh.read(20000)
    except OSError:
        return title
    return title + " " + WIKI_NOISE.sub(" ", raw)

texts = [read_text(t) for t in titles]
log("text loaded")

tfidf = TfidfVectorizer(max_features=60000, min_df=5, max_df=0.35,
                        stop_words="english", sublinear_tf=True)
X_text = tfidf.fit_transform(texts)
del texts
log("tfidf", X_text.shape)

X = sparse.hstack([normalize(X_text), normalize(C) * 1.6, normalize(A) * 0.8]).tocsr()
svd = TruncatedSVD(n_components=SVD_DIMS, random_state=0)
Z = normalize(svd.fit_transform(X)).astype(np.float32)
log("svd", Z.shape, "explained", round(float(svd.explained_variance_ratio_.sum()), 3))

# ---- regions -----------------------------------------------------------
km = KMeans(n_clusters=N_REGIONS, n_init=4, random_state=0)
region = km.fit_predict(Z).astype(np.int32)
log("kmeans done")

cat_totals = np.asarray(C.sum(axis=0)).ravel()
region_labels = []
for k in range(N_REGIONS):
    members = np.where(region == k)[0]
    sub = np.asarray(C[members].sum(axis=0)).ravel()
    with np.errstate(divide="ignore", invalid="ignore"):
        score = (sub / max(len(members), 1)) * np.log1p(sub) / np.log1p(cat_totals)
    score[np.isnan(score)] = 0
    top = np.argsort(-score)[:4]
    region_labels.append([cat_names[j] for j in top if sub[j] > 0])
    log("region", k, len(members), region_labels[-1][:3])

# ---- semantic k-NN -----------------------------------------------------
# Brute-force cosine in row blocks. 40k x 40k in one array would be 6 GB;
# 2000 rows at a time is 320 MB and takes about a minute in total.
log("knn starting")
knn_idx = np.zeros((N, KNN), np.int32)
knn_sim = np.zeros((N, KNN), np.float32)
BLOCK = 2000
for s in range(0, N, BLOCK):
    e = min(s + BLOCK, N)
    S = Z[s:e] @ Z.T
    S[np.arange(e - s), np.arange(s, e)] = -1.0     # never one's own neighbour
    part = np.argpartition(-S, KNN, axis=1)[:, :KNN]
    take = np.take_along_axis(S, part, axis=1)
    order = np.argsort(-take, axis=1)
    knn_idx[s:e] = np.take_along_axis(part, order, axis=1)
    knn_sim[s:e] = np.take_along_axis(take, order, axis=1)
    if s % 10000 == 0:
        log("knn", s)
log("knn done")

# ---- layout ------------------------------------------------------------
log("tsne starting")
xy = TSNE(n_components=2, perplexity=40, init="pca", learning_rate="auto",
          random_state=0, max_iter=750, angle=0.6, verbose=1).fit_transform(Z)
log("tsne done")

# ---- authority ---------------------------------------------------------
indeg = np.zeros(N, np.int32)
for out in links:
    for j in out:
        indeg[j] += 1
authority = indeg / max(1.0, float(indeg.max()))

# ---- the nine reading-room lenses, applied to every article -------------
# Same rule the gateway uses: case-insensitive substring of a lens keyword
# against each of the article's categories, unioned. Reproduced here so the
# map can show which articles no lens can see.
facets = []
if os.path.exists(AIWIKI_DATA):
    facets = json.load(open(AIWIKI_DATA, encoding="utf-8"))["facets"]
facet_of = [[] for _ in range(N)]
for fi, f in enumerate(facets):
    kws = [k.lower() for k in f["keywords"]]
    for i, cs in enumerate(cats):
        low = [x.lower() for x in cs]
        if any(k in cat for k in kws for cat in low):
            facet_of[i].append(fi)
unfiled = sum(1 for f in facet_of if not f)
log("lenses", len(facets), "articles no lens can see", unfiled)

out = {
    "n": N,
    "titles": titles,
    "x": xy[:, 0].tolist(),
    "y": xy[:, 1].tolist(),
    "region": region.tolist(),
    "regionLabels": region_labels,
    "regionSizes": [int((region == k).sum()) for k in range(N_REGIONS)],
    "size": sizes,
    "indeg": indeg.tolist(),
    "authority": [round(float(a), 4) for a in authority],
    "cats": cats,
    "links": links,
    "knnIdx": knn_idx.tolist(),
    "knnSim": np.round(knn_sim, 3).tolist(),
    "facetOf": facet_of,
    "facets": facets,
    "unfiled": unfiled,
    "svdExplained": round(float(svd.explained_variance_ratio_.sum()), 3),
    "nCategories": len(cat_names),
    "nRedirects": c["nRedirects"],
}
p = os.path.join(DATA, "layout.json")
json.dump(out, open(p, "w", encoding="utf-8"), ensure_ascii=False, separators=(",", ":"))
log("written", os.path.getsize(p) // 1024, "KB")

np.save(os.path.join(DATA, "svd.npy"), Z)
json.dump({"leads": leads}, gzip.open(os.path.join(DATA, "leads.json.gz"), "wt", encoding="utf-8"),
          ensure_ascii=False, separators=(",", ":"))
log("done")
