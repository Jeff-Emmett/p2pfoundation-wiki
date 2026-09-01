"""Lay the SVD space out in three dimensions.

Same construction as tsne-plane.py, one axis richer. 3-D is not decoration here:
in 2-D a 39,915-point t-SNE has to resolve every neighbourhood onto one sheet,
so distinct regions get folded on top of each other and the centre becomes a
hairball. The extra axis gives those neighbourhoods somewhere to go, and the
shape of the corpus — which parts are shells, which are cores — becomes
something you can see by orbiting rather than something you infer.

Reads data/svd.npy (already on this host). Writes data/plane3.json.
"""
import json, os, time
import numpy as np
from sklearn.manifold import TSNE

SP = os.path.dirname(os.path.abspath(__file__))
DATA = os.path.join(SP, "..", "data")
PULL, PUSH = 0.42, 1.28          # gentler than 2-D: depth already separates

T0 = time.time()
def log(*a): print("[%6.1fs]" % (time.time() - T0), *a, flush=True)

Z = np.load(os.path.join(DATA, "svd.npy"))
NB = json.load(open(os.path.join(DATA, "neighbours.json"), encoding="utf-8"))
region = np.array(NB["region"], np.int32)
K = len(NB["regionLabels"])
log("points", Z.shape, "regions", K)

xyz = TSNE(n_components=3, perplexity=40, init="pca", learning_rate="auto",
           random_state=0, max_iter=750, angle=0.6, verbose=2,
           n_jobs=-1).fit_transform(Z.astype(np.float32)).astype(np.float64)
log("tsne done")

cent = np.stack([xyz[region == k].mean(axis=0) for k in range(K)])
gc = xyz.mean(axis=0)
xyz = xyz + (cent[region] - xyz) * PULL + ((gc + (cent - gc) * PUSH) - cent)[region]

xyz -= xyz.min(axis=0)
xyz /= xyz.max()
coords = np.clip(np.round(xyz * 4095), 0, 4095).astype(np.uint16)

# Region centroids travel with the points so labels can float at the middle of
# their own territory rather than being recomputed from a hull that no longer
# exists in three dimensions.
cen3 = [[float(v) for v in coords[region == k].mean(axis=0)] for k in range(K)]
# A radius per region, for the soft translucent shell drawn at the far band.
rad3 = [float(np.quantile(np.linalg.norm(coords[region == k] - np.array(cen3[k]), axis=1), 0.72))
        for k in range(K)]

json.dump({"x": coords[:, 0].tolist(), "y": coords[:, 1].tolist(), "z": coords[:, 2].tolist(),
           "centroids": cen3, "radii": rad3},
          open(os.path.join(DATA, "plane3.json"), "w"), separators=(",", ":"))
log("written", os.path.getsize(os.path.join(DATA, "plane3.json")) // 1024, "KB")
