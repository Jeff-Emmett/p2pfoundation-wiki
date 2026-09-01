"""Semantic neighbours only — the part the link proposals actually need.

Split out from build-layout.py because the t-SNE plane is irrelevant to
proposing a link, and because doing both in one process OOM-killed the box.
Same feature construction, then blocked cosine k-NN with the intermediates
freed as soon as they are dead.

Output: data/neighbours.json (knnIdx, knnSim, region, regionLabels)
"""
import gc, gzip, json, os, re, time
import numpy as np
from scipy import sparse
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.decomposition import TruncatedSVD
from sklearn.cluster import MiniBatchKMeans
from sklearn.preprocessing import normalize

SP = os.path.dirname(os.path.abspath(__file__))
DATA = os.path.join(SP, "..", "data")
WIKI = "/home/jeffe/Github/p2pfoundation-wiki/wiki"
N_REGIONS, SVD_DIMS, KNN, BLOCK = 24, 80, 6, 512

T0 = time.time()
def log(*a): print("[%6.1fs]" % (time.time() - T0), *a, flush=True)

c = json.load(gzip.open(os.path.join(DATA, "corpus.json.gz"), "rt", encoding="utf-8"))
titles, cats, links = c["titles"], c["cats"], c["links"]
N = len(titles)
log("corpus", N)

cat_index, cat_names, rows, cols = {}, [], [], []
for i, cs in enumerate(cats):
    for name in cs:
        j = cat_index.setdefault(name, len(cat_names))
        if j == len(cat_names): cat_names.append(name)
        rows.append(i); cols.append(j)
C = sparse.csr_matrix((np.ones(len(rows), np.float32), (rows, cols)), shape=(N, len(cat_names)))

er, ec = [], []
for i, out in enumerate(links):
    for j in out:
        er += [i, j]; ec += [j, i]
A = sparse.csr_matrix((np.ones(len(er), np.float32), (er, ec)), shape=(N, N))
del er, ec, rows, cols
log("categories", len(cat_names), "adjacency nnz", A.nnz)

NOISE = re.compile(r"(\[\[File:[^\]]*\]\]|\{\{[^}]*\}\}|https?://\S+|[\[\]{}|=*#'])")
def read_text(t):
    try:
        with open(os.path.join(WIKI, t + ".mediawiki"), encoding="utf-8", errors="ignore") as fh:
            return t + " " + NOISE.sub(" ", fh.read(20000))
    except OSError:
        return t

tfidf = TfidfVectorizer(max_features=60000, min_df=5, max_df=0.35,
                        stop_words="english", sublinear_tf=True, dtype=np.float32)
X_text = normalize(tfidf.fit_transform(read_text(t) for t in titles))
N_TEXT = X_text.shape[1]
vocab = tfidf.vocabulary_
idf = tfidf.idf_.astype(np.float32)
X = sparse.hstack([X_text, normalize(C) * 1.6, normalize(A) * 0.8]).tocsr()
del tfidf, A, X_text; gc.collect()
log("features", X.shape, "nnz", X.nnz)

svd = TruncatedSVD(n_components=SVD_DIMS, random_state=0)
Z = normalize(svd.fit_transform(X)).astype(np.float32)
explained = round(float(svd.explained_variance_ratio_.sum()), 3)
# Keep the text block of the projection. This is what lets the browser fold a
# free-text query into the *same* basis the articles live in, so "distance to
# this query" is measured in the space the map was built from rather than in
# some second, unrelated notion of similarity.
comp_text = svd.components_[:, :N_TEXT].astype(np.float32)
del X, svd; gc.collect()
log("svd", Z.shape, "explained", explained)

region = MiniBatchKMeans(n_clusters=N_REGIONS, n_init=4, random_state=0,
                         batch_size=2048).fit_predict(Z).astype(np.int32)
cat_totals = np.asarray(C.sum(axis=0)).ravel()
region_labels = []
for k in range(N_REGIONS):
    members = np.where(region == k)[0]
    sub = np.asarray(C[members].sum(axis=0)).ravel()
    with np.errstate(divide="ignore", invalid="ignore"):
        score = (sub / max(len(members), 1)) * np.log1p(sub) / np.log1p(cat_totals)
    score[np.isnan(score)] = 0
    region_labels.append([cat_names[j] for j in np.argsort(-score)[:4] if sub[j] > 0])
del C; gc.collect()
log("regions named")

ZT = np.ascontiguousarray(Z.T)
knn_idx = np.zeros((N, KNN), np.int32)
knn_sim = np.zeros((N, KNN), np.float32)
for s in range(0, N, BLOCK):
    e = min(s + BLOCK, N)
    S = Z[s:e] @ ZT
    S[np.arange(e - s), np.arange(s, e)] = -1.0
    np.negative(S, out=S)
    part = np.argpartition(S, KNN, axis=1)[:, :KNN].astype(np.int32)
    take = -np.take_along_axis(S, part, axis=1)
    order = np.argsort(-take, axis=1)
    knn_idx[s:e] = np.take_along_axis(part, order, axis=1)
    knn_sim[s:e] = np.take_along_axis(take, order, axis=1)
    del S, part, take, order
    if s % 8192 == 0: log("  knn", s)
log("knn done")

json.dump({"n": N, "knnIdx": knn_idx.tolist(), "knnSim": np.round(knn_sim, 3).tolist(),
           "region": region.tolist(), "regionLabels": region_labels,
           "regionSizes": [int((region == k).sum()) for k in range(N_REGIONS)],
           "nCategories": len(cat_names), "svdExplained": explained},
          open(os.path.join(DATA, "neighbours.json"), "w", encoding="utf-8"),
          ensure_ascii=False, separators=(",", ":"))
np.save(os.path.join(DATA, "svd.npy"), Z)
np.save(os.path.join(DATA, "comp_text.npy"), comp_text)
inv = {v: k for k, v in vocab.items()}
json.dump({"terms": [inv[i] for i in range(len(inv))], "idf": idf.tolist()},
          open(os.path.join(DATA, "vocab.json"), "w", encoding="utf-8"),
          ensure_ascii=False, separators=(",", ":"))
log("saved projection", comp_text.shape, "vocab", len(inv))
log("written")
