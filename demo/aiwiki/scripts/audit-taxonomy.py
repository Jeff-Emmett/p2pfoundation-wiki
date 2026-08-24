#!/usr/bin/env python3
"""
Audit the nine reading-room lenses against EVERY category on the wiki.

The demo's §2 works over a 77-article sample, which is enough to show the shape
of a miss but not its size. This walks the wiki's complete category list with
page counts and asks, for each lens, which real categories its keywords match —
so "this keyword matches nothing" becomes a number of pages rather than an
impression.

Writes the result into data/aiwiki-data.json as `taxonomyAudit`.
"""
import json, os, subprocess, sys, time
from urllib.parse import urlencode

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
API = "https://wiki.p2pfoundation.net/api.php"

src = open(os.path.join(HERE, "build-corpus.py")).read().replace(
    'if __name__ == "__main__":\n    main()', '')
M = {"__file__": os.path.join(HERE, "build-corpus.py"), "__name__": "bc"}
exec(compile(src, "build-corpus.py", "exec"), M)
FACETS = M["FACETS"]

def api(params):
    params = dict(params); params["format"] = "json"
    url = API + "?" + urlencode(params)
    for a in range(4):
        r = subprocess.run(["curl", "-s", "-m", "60", "--compressed", url], capture_output=True)
        if r.returncode == 0 and r.stdout.strip().startswith(b"{"):
            return json.loads(r.stdout)
        time.sleep(2 * (a + 1))
    raise RuntimeError("wiki api failed")

cats, cont = [], None
while True:
    p = {"action": "query", "list": "allcategories", "aclimit": "500", "acprop": "size"}
    if cont: p["accontinue"] = cont
    d = api(p)
    cats += [(c["*"], c.get("pages", 0)) for c in d["query"]["allcategories"]]
    cont = d.get("continue", {}).get("accontinue")
    print(f"  {len(cats)} categories", file=sys.stderr)
    if not cont: break
    time.sleep(0.2)

lower = [(n, n.lower(), pages) for n, pages in cats]
audit = {"categories": len(cats), "categorised_pages": sum(p for _, p in cats), "lenses": []}

for f in FACETS:
    matched = [(n, p) for n, ln, p in lower if any(kw in ln for kw in f["keywords"])]
    dead = [kw for kw in f["keywords"] if not any(kw in ln for _, ln, _ in lower)]
    audit["lenses"].append({
        "id": f["id"], "label": f["label"],
        "categories": len(matched),
        "pages": sum(p for _, p in matched),
        "dead_keywords": dead,
        "top": sorted(matched, key=lambda x: -x[1])[:5],
    })

# the specific near-misses: a keyword that fails only because of a space
near = []
for kw in ("peer production", "peer governance", "peer property", "intellectual property"):
    squashed = kw.replace(" ", "")
    hit = [(n, p) for n, ln, p in lower if ln == squashed or ln.startswith(squashed)]
    if hit and not any(kw in ln for _, ln, _ in lower):
        near.append({"keyword": kw, "actual": hit[0][0], "pages": hit[0][1]})
audit["near_misses"] = near

dst = os.path.join(ROOT, "data", "aiwiki-data.json")
data = json.load(open(dst))
data["taxonomyAudit"] = audit
json.dump(data, open(dst, "w"), separators=(",", ":"))

print(f"\n{audit['categories']} categories, {audit['categorised_pages']} category memberships")
for l in audit["lenses"]:
    print(f"  {l['pages']:6d} pages / {l['categories']:4d} cats  {l['label'][:46]}"
          + (f"   DEAD: {l['dead_keywords']}" if l["dead_keywords"] else ""))
print("\nnear misses (keyword fails only on the space):")
for n in near:
    print(f"  '{n['keyword']}' → category '{n['actual']}' holds {n['pages']} pages")
