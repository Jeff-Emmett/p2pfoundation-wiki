#!/usr/bin/env python3
"""
Fill data/_cache/embeddings.json gently.

The GX10 LiteLLM proxy answers a paced caller reliably but returns auth errors
under a burst of retries — a retry storm keeps itself failing. So this walks the
batches slowly, one at a time, and on a failure it WAITS rather than hammering.
It is separate from build-corpus.py so the expensive part can be resumed on its
own; build-corpus.py then finds every vector already cached.
"""
import hashlib, json, os, re, subprocess, sys, time

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
CACHE = os.path.join(ROOT, "data", "_cache")
LITELLM = os.environ.get("LITELLM_BASE_URL", "http://100.64.0.5:4001")
MODEL = "gx10-embed"
BATCH, PACE, COOLDOWN = 12, 4, 45

src = open(os.path.join(HERE, "build-corpus.py")).read().replace(
    'if __name__ == "__main__":\n    main()', '')
M = {"__file__": os.path.join(HERE, "build-corpus.py"), "__name__": "bc"}
exec(compile(src, "build-corpus.py", "exec"), M)

raw = json.load(open(os.path.join(CACHE, "pages.json")))
QUERIES = [q["q"] for q in M["QUERIES"]]

texts = []
for t, p in sorted(raw.items()):
    lead = ""
    for c in M["to_chunks"](p["wikitext"]):
        f = M["flat"](M["strip_markup"](c["text"]))
        if len(f) > 90: lead = f; break
    if not lead: lead = M["flat"](M["strip_markup"](p["wikitext"]))[:400]
    texts.append(f"{t}. {' '.join(p['categories'])}. {lead[:520]}")
texts += QUERIES

cache_p = os.path.join(CACHE, "embeddings.json")
cache = json.load(open(cache_p)) if os.path.exists(cache_p) else {}
key = lambda t: hashlib.sha256((MODEL + "\x00" + t).encode()).hexdigest()[:32]
todo = [t for t in texts if key(t) not in cache]
print(f"{len(texts)} texts, {len(todo)} to embed", flush=True)

i = 0
while i < len(todo):
    batch = todo[i:i+BATCH]
    payload = json.dumps({"model": MODEL, "input": batch})
    r = subprocess.run(["curl", "-s", "-m", "180", f"{LITELLM}/v1/embeddings",
                        "-H", "Content-Type: application/json", "-d", payload],
                       capture_output=True)
    ok = False
    try:
        d = json.loads(r.stdout)
        if "data" in d:
            for t, e in zip(batch, sorted(d["data"], key=lambda x: x["index"])):
                cache[key(t)] = e["embedding"]
            json.dump(cache, open(cache_p, "w"))
            i += BATCH; ok = True
            print(f"  {min(i, len(todo))}/{len(todo)} cached", flush=True)
    except Exception:
        pass
    if not ok:
        msg = (r.stdout[:120] or r.stderr[:120]).decode(errors="replace")
        print(f"  batch at {i} failed, cooling down {COOLDOWN}s — {msg}", flush=True)
        time.sleep(COOLDOWN)
    else:
        time.sleep(PACE)

print(f"DONE — {len(cache)} vectors cached", flush=True)
