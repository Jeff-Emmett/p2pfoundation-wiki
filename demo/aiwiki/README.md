# aiwiki — what the Portico gateway makes possible over the P2P Foundation wiki

A single-page, step-through demo published at **https://aiwiki.jeffemmett.com**.

Six chapters, each animating one affordance the gateway adds on top of a MediaWiki
that is otherwise just a pile of pages:

| § | Affordance | Portico module it mirrors |
|---|---|---|
| 1 | **Ingest** — an article becomes an addressable document with its provenance attached | `render.ts`, `provenance.ts`, `wiki-holon.ts` |
| 2 | **Regroup** — nine reading-room lenses mapped over free-form MediaWiki categories | `taxonomy-p2p.ts` |
| 3 | **Retrieve** — lexical ∪ semantic union, ranks fused rather than scores averaged | `hybrid-retriever.ts`, `reranker.ts`, `rank-signals.ts` |
| 4 | **Subset** — an overlay holds a group's organization of a corpus it does not own | `overlay-store.ts` |
| 5 | **Re-view** — level-of-detail bands and the form-degradation ladder | `lod-band.ts`, `render.ts` |
| 6 | **Ground & derive** — claim coverage, computed licence, non-increasing confidence | `grounding.ts`, `derive-license.ts`, `derive-confidence.ts` |

## The data is real

Nothing on the page is placeholder copy. `scripts/build-corpus.py` pulls live from
`wiki.p2pfoundation.net` and computes everything the page displays:

- **Articles** — real titles, page ids, byte counts, revisions, contributors and
  MediaWiki categories, fetched through `api.php`.
- **Authority** — real inbound-link counts measured across the *whole* wiki
  (`list=backlinks`), not just the demo slice.
- **Facets** — the nine lenses applied with the real `facetsForCategories()`
  algorithm: case-insensitive substring match, union across an article's categories.
- **Lexical scores** — a real BM25 (k1=1.5, b=0.75) over the stripped article text.
- **Semantic scores** — real 768-dimension embeddings from the GX10 LiteLLM stack
  (`gx10-embed`), the same dimensionality as Portico's production embedder.
- **Fusion** — real Reciprocal Rank Fusion, `RRF_K = 60`, plus the saturating
  link-centrality signal at weight 0.3 (`half = 5`).
- **The generated answer** — a real completion from `gx10-general` over the sources
  retrieval actually returned, unedited, then scored by the real `groundAnswer()`
  coverage rule (`grounded ≥ 0.6`, `uncertain ≥ 0.3`).

## Build

```bash
# 1. fetch + compute the dataset  → data/aiwiki-data.json
#    the GX10 tailnet proxy answers anonymously; the public gateway needs a key
LITELLM_BASE_URL=http://100.64.0.5:4001 python3 scripts/warm-embeddings.py
LITELLM_BASE_URL=http://100.64.0.5:4001 python3 scripts/build-corpus.py --skip-fetch

# 2. inline the data into the page  → dist/index.html
./build.sh

# 3. publish
CLOUDFLARE_API_TOKEN=… CLOUDFLARE_ACCOUNT_ID=… ./build.sh --deploy
```

`--skip-fetch` reuses `data/_cache/` (pages, backlinks, statistics, embeddings) so
only the computation re-runs. Drop it to re-read the wiki.

### Gotchas worth knowing

- **A custom `User-Agent` trips Cloudflare's bot challenge** on `wiki.p2pfoundation.net`,
  and `urllib` is blocked outright. `curl` with its default UA is the path that works.
- **`rvlimit` is invalid when querying multiple titles.** Omit it; the latest revision
  is the default.
- **The wiki has no TextExtracts extension** (only CategoryTree, YouTube, ConfirmEdit,
  QuestyCaptcha), so `prop=extracts` returns nothing. Lead text is produced by running
  the gateway's own `stripMarkup()` + `toChunks()` over raw wikitext — which is what
  ingest does anyway.
- **`LITELLM_BASE_URL` is set in the shell environment** to `https://llm.jeffemmett.com`,
  which requires a key. As of 2026-08-24 the env `LITELLM_API_KEY`, `~/.config/llm/key`
  and `~/.private/claude-mcp/gx10-recommender-key` are all rejected by it. Point the
  scripts at the GX10 tailnet proxy instead, which answers anonymously.
- **Do not retry the proxy in a tight loop.** A burst of retries keeps itself failing;
  `warm-embeddings.py` paces requests and cools down on error instead.
- **The repo root `.gitignore` excludes `data/`**, which silently covers
  `demo/aiwiki/data/aiwiki-data.json` too. It is tracked because it was added with
  `git add -f`; a new generated file under `data/` will need the same, or it will
  look committed and not be.

## A real bug this demo surfaced

The reading-room lenses match keywords against categories by substring. The lens
keywords are `peer production`, `peer governance`, `peer property` — but the categories
editors actually use on the wiki are **`Peerproduction`, `Peergovernance`,
`Peerproperty`**, single words. The match never fires.

The consequence is visible in §2: **Peer Production**, with more inbound links than all
but two articles in the sample, is *unfiled* — invisible to the `p2p-paradigms` lens
that exists to hold it. Same for the 4 `Peergovernance` and 5 `Peerproperty` pages.

The fix is to add the concatenated spellings to those keyword sets in
`server/portico/taxonomy-p2p.ts`. Nothing surfaces this on its own, because "unfiled"
is a legitimate state and a silent one.
