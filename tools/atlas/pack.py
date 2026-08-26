"""Pack map.json into a compact payload for the artifact page.

Two layout adjustments happen here, not in the t-SNE:
  * points are pulled slightly toward their region centroid, and the centroids
    pushed slightly apart, so the regions read as regions at a glance instead
    of one undifferentiated ball;
  * a convex hull per region is precomputed so the page can outline territory
    without shipping the whole point set to a hull routine at load time.
"""
import base64, json, os
import numpy as np
from scipy.spatial import ConvexHull

SP = os.path.dirname(os.path.abspath(__file__))
m = json.load(open(os.path.join(SP, "map.json"), encoding="utf-8"))

titles = m["titles"]
xy = np.stack([np.array(m["x"], float), np.array(m["y"], float)], axis=1)
cluster = np.array(m["cluster"], dtype=np.int32)
K = len(m["clusterLabels"])

PULL = 0.50      # toward own centroid
PUSH = 1.32      # centroids away from the global centre

cent = np.stack([xy[cluster == k].mean(axis=0) for k in range(K)])
global_c = xy.mean(axis=0)
new_cent = global_c + (cent - global_c) * PUSH
xy = xy + (cent[cluster] - xy) * PULL + (new_cent - cent)[cluster]

xy -= xy.min(axis=0)
xy /= xy.max()
coords = np.clip(np.round(xy * 4095), 0, 4095).astype(np.uint16)

hulls = []
for k in range(K):
    pts = coords[cluster == k].astype(float)
    if len(pts) < 4:
        hulls.append([])
        continue
    # trim the 4% most distant members so one outlier cannot balloon a region
    c = pts.mean(axis=0)
    d = np.linalg.norm(pts - c, axis=1)
    pts = pts[d <= np.quantile(d, 0.96)]
    try:
        h = ConvexHull(pts)
        hulls.append([[int(v) for v in pts[i]] for i in h.vertices])
    except Exception:
        hulls.append([])

size = np.clip(np.array(m["size"], dtype=np.int64) // 64, 0, 65535).astype(np.uint16)

cat_ids, cat_names, per_page = {}, [], []
for cs in m["cats"]:
    row = []
    for c in cs:
        j = cat_ids.get(c)
        if j is None:
            j = cat_ids[c] = len(cat_names); cat_names.append(c)
        row.append(j)
    per_page.append(row)

payload = {
    "n": m["n"],
    "titles": "\n".join(titles),
    "x": base64.b64encode(coords[:, 0].copy().tobytes()).decode(),
    "y": base64.b64encode(coords[:, 1].copy().tobytes()).decode(),
    "cluster": base64.b64encode(cluster.astype(np.uint8).tobytes()).decode(),
    "size": base64.b64encode(size.tobytes()).decode(),
    "catNames": cat_names,
    "cats": "\n".join(",".join(str(j) for j in row) for row in per_page),
    "clusterLabels": m["clusterLabels"],
    "clusterSizes": m["clusterSizes"],
    "hulls": hulls,
}
out = os.path.join(SP, "payload.json")
with open(out, "w", encoding="utf-8") as fh:
    json.dump(payload, fh, ensure_ascii=False, separators=(",", ":"))
print("payload", os.path.getsize(out) // 1024, "KB")
