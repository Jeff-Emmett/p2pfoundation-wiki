"""Build a 2-D semantic map of the P2P Foundation Wiki.

Input : pages.tsv (page_id, title, len), cats.tsv (page_id, category)
        plus the article wikitext in <repo>/wiki/<Title>.mediawiki
Output: map.json — titles, coordinates, cluster ids, cluster labels

Features are article text (TF-IDF) concatenated with category membership,
the categories weighted up because they are curated and the text is not.
SVD to 80 dims, KMeans for the semantic groups, t-SNE for the layout.
"""
import json, os, re, sys, time
import numpy as np
from scipy import sparse
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.decomposition import TruncatedSVD
from sklearn.cluster import KMeans
from sklearn.manifold import TSNE
from sklearn.preprocessing import normalize

SP = os.path.dirname(os.path.abspath(__file__))
WIKI = "/home/jeffe/Github/p2pfoundation-wiki/wiki"
N_CLUSTERS = 26

def log(*a):
    print("[%6.1fs]" % (time.time() - T0), *a, flush=True)

T0 = time.time()

# ---- pages -------------------------------------------------------------
ids, titles, lens = [], [], []
for line in open(os.path.join(SP, "pages.tsv"), encoding="utf-8"):
    pid, title, plen = line.rstrip("\n").split("\t")
    ids.append(int(pid)); titles.append(title); lens.append(int(plen))
index = {p: i for i, p in enumerate(ids)}
N = len(ids)
log("pages", N)

# ---- categories --------------------------------------------------------
cat_names, cat_index = [], {}
rows, cols = [], []
page_cats = [[] for _ in range(N)]
for line in open(os.path.join(SP, "cats.tsv"), encoding="utf-8"):
    pid, cat = line.rstrip("\n").split("\t")
    i = index.get(int(pid))
    if i is None:
        continue
    j = cat_index.get(cat)
    if j is None:
        j = cat_index[cat] = len(cat_names); cat_names.append(cat)
    rows.append(i); cols.append(j); page_cats[i].append(j)
C = sparse.csr_matrix((np.ones(len(rows), dtype=np.float32), (rows, cols)),
                      shape=(N, len(cat_names)))
log("categories", len(cat_names), "memberships", len(rows))

# ---- article text ------------------------------------------------------
WIKI_NOISE = re.compile(r"(\[\[File:[^\]]*\]\]|\{\{[^}]*\}\}|https?://\S+|[\[\]{}|=*#'])")
def read_text(title):
    path = os.path.join(WIKI, title.replace("_", " ") + ".mediawiki")
    try:
        with open(path, encoding="utf-8", errors="ignore") as fh:
            raw = fh.read(20000)
    except OSError:
        return title.replace("_", " ")
    return title.replace("_", " ") + " " + WIKI_NOISE.sub(" ", raw)

texts = [read_text(t) for t in titles]
log("text loaded")

tfidf = TfidfVectorizer(max_features=60000, min_df=5, max_df=0.35,
                        stop_words="english", sublinear_tf=True)
X_text = tfidf.fit_transform(texts)
log("tfidf", X_text.shape)

X = sparse.hstack([normalize(X_text), normalize(C) * 1.6]).tocsr()
svd = TruncatedSVD(n_components=80, random_state=0)
Z = svd.fit_transform(X)
Z = normalize(Z)
log("svd", Z.shape, "explained", round(float(svd.explained_variance_ratio_.sum()), 3))

km = KMeans(n_clusters=N_CLUSTERS, n_init=4, random_state=0)
labels = km.fit_predict(Z)
log("kmeans done")

# name each cluster from the categories its members share most distinctively
cat_totals = np.asarray(C.sum(axis=0)).ravel()
cluster_labels = []
for k in range(N_CLUSTERS):
    members = np.where(labels == k)[0]
    sub = np.asarray(C[members].sum(axis=0)).ravel()
    with np.errstate(divide="ignore", invalid="ignore"):
        score = (sub / max(len(members), 1)) * np.log1p(sub) / np.log1p(cat_totals)
    score[np.isnan(score)] = 0
    top = np.argsort(-score)[:4]
    cluster_labels.append([cat_names[j].replace("_", " ") for j in top if sub[j] > 0])
    log("cluster", k, len(members), cluster_labels[-1])

log("tsne starting")
xy = TSNE(n_components=2, perplexity=40, init="pca", learning_rate="auto",
          random_state=0, max_iter=750, angle=0.6, verbose=1).fit_transform(Z)
log("tsne done")

# normalise to 0..4095 for compact transport
xy = xy - xy.min(axis=0)
xy = xy / xy.max()
coords = np.round(xy * 4095).astype(int)

out = {
    "n": N,
    "titles": titles,
    "x": coords[:, 0].tolist(),
    "y": coords[:, 1].tolist(),
    "cluster": labels.astype(int).tolist(),
    "size": lens,
    "cats": [[cat_names[j].replace("_", " ") for j in cs[:6]] for cs in page_cats],
    "clusterLabels": cluster_labels,
    "clusterSizes": [int((labels == k).sum()) for k in range(N_CLUSTERS)],
}
with open(os.path.join(SP, "map.json"), "w", encoding="utf-8") as fh:
    json.dump(out, fh, ensure_ascii=False, separators=(",", ":"))
log("written", os.path.getsize(os.path.join(SP, "map.json")) // 1024, "KB")
