"""t-SNE the SVD space into the plane, then separate the regions a little.

Runs where there is memory: 39,915 points at 80 dims is fine, but Barnes-Hut
allocates enough working set to OOM a loaded 19 GB laptop, which is why this is
a separate script that takes svd.npy as input rather than recomputing features.

The two post-layout adjustments are deliberate and are not part of the t-SNE:
points are pulled toward their own region's centroid and the centroids pushed
apart, so regions read as places instead of one undifferentiated cloud; and a
trimmed convex hull per region is precomputed so the page can outline territory
without shipping the point set to a hull routine at load time.
"""
import json, os, sys, time
import numpy as np
from scipy.spatial import ConvexHull
from sklearn.manifold import TSNE

SP = os.path.dirname(os.path.abspath(__file__))
DATA = os.path.join(SP, "..", "data")
PULL, PUSH = 0.50, 1.32

T0 = time.time()
def log(*a): print("[%6.1fs]" % (time.time() - T0), *a, flush=True)

Z = np.load(os.path.join(DATA, "svd.npy"))
NB = json.load(open(os.path.join(DATA, "neighbours.json"), encoding="utf-8"))
region = np.array(NB["region"], dtype=np.int32)
K = len(NB["regionLabels"])
log("points", Z.shape, "regions", K)

xy = TSNE(n_components=2, perplexity=40, init="pca", learning_rate="auto",
          random_state=0, max_iter=750, angle=0.6, verbose=2,
          n_jobs=-1).fit_transform(Z.astype(np.float32))
log("tsne done")

xy = xy.astype(np.float64)
cent = np.stack([xy[region == k].mean(axis=0) for k in range(K)])
gc = xy.mean(axis=0)
xy = xy + (cent[region] - xy) * PULL + ((gc + (cent - gc) * PUSH) - cent)[region]

xy -= xy.min(axis=0); xy /= xy.max()
coords = np.clip(np.round(xy * 4095), 0, 4095).astype(np.uint16)

hulls = []
for k in range(K):
    pts = coords[region == k].astype(float)
    if len(pts) < 4:
        hulls.append([]); continue
    c = pts.mean(axis=0)
    d = np.linalg.norm(pts - c, axis=1)
    pts = pts[d <= np.quantile(d, 0.96)]          # one outlier must not balloon a region
    try:
        h = ConvexHull(pts)
        hulls.append([[int(v) for v in pts[i]] for i in h.vertices])
    except Exception:
        hulls.append([])
log("hulls")

json.dump({"x": coords[:, 0].tolist(), "y": coords[:, 1].tolist(), "hulls": hulls},
          open(os.path.join(DATA, "plane.json"), "w"), separators=(",", ":"))
log("written", os.path.getsize(os.path.join(DATA, "plane.json")) // 1024, "KB")
