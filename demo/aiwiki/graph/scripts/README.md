# Suggested links — pipeline

Turns the wiki's own text into a small set of wikilink suggestions, and routes
every one of them through a human before anything is written.

Nothing in this directory edits the wiki except `apply-decisions.py --apply`,
and that refuses to act on any row an editor has not marked and signed.

## Run order

```bash
PY=/home/jeffe/Github/p2pwiki-ai/.venv/bin/python   # needs numpy/scipy/sklearn

$PY scripts/extract-corpus.py      # wikitext -> titles, cats, links, leads   (~25 s)
$PY scripts/build-neighbours.py    # TF-IDF + cats + links -> SVD -> k-NN     (~80 s)
$PY scripts/propose-links.py       # k-NN -> reviewable proposals             (~30 s)
$PY scripts/render-batch.py --limit 60   # proposals -> the on-wiki review page
$PY scripts/apply-decisions.py --batch 2026-09-01          # DRY RUN, reads only
$PY scripts/apply-decisions.py --batch 2026-09-01 --apply  # writes
```

`build-layout.py` computes the t-SNE plane for the graph view. It is not needed
for link proposals, and it OOM-killed a 19 GB box when run alongside the k-NN —
which is why the two are separate scripts.

## Why so much is thrown away

| stage | pairs |
|---|---:|
| mutual k-NN, both directions agree | 60,643 |
| …not already linked | 59,162 |
| …the target's exact title appears unlinked in the source's prose | 719 |
| …written as a name (exact case) and more than one word | **221** |

The last row is the batch. The subtraction is the product: 59,162 suggestions is
not a review queue, it is a denial of service on an editor's attention.

The two filters in that final step do nearly all the precision work, and neither
is about the model:

- **exact case** — `…integrate the information` is not a reference to
  `[[Information]]`, and `…personal development` is not a reference to
  `[[Development]]`. Requiring the author to have written the phrase as a name
  removes almost every bad suggestion of this kind.
- **more than one word** — single common nouns are what overlinking is made of.

## Safety properties

Asserted by `apply-decisions.py` and covered by the round-trip test:

1. **Blank is not approval.** Only `yes`/`y`/`approve` acts. Unsigned approvals
   are held back.
2. **Re-verified at apply time.** Stored offsets are never trusted; the live
   wikitext is re-fetched and the mention re-located, or the row is skipped as
   stale.
3. **Idempotent.** An article that already links to the target is left alone, so
   running a batch twice is a no-op the second time. Without this guard 24 of
   the first 60 rows would have linked a *second* occurrence on a re-run, and a
   third on the run after — verified, and the reason the guard exists.
4. **One revision per article**, summary naming the approver, the batch and the
   row id, linking back to the review page.
5. **Rate limited** to one edit per 5 s, 200 per run.
6. **Kill switch** — any content on `P2P Foundation:Suggested links/STOP` halts it.
7. **Dry run by default.** `--apply` is required to write.

Credentials come from `P2PWIKI_BOT_USER` / `P2PWIKI_BOT_PASS` in the environment
— a `Special:BotPasswords` grant scoped to `editpage`, revocable from the wiki,
never an editor's main password and never on the command line.

## Deploying the atlas

`build.sh --deploy` publishes `dist/` to the Cloudflare Pages project
`aiwiki-jeffemmett`. Wrangler will **not** use a stored `wrangler login` session
in a non-interactive shell — it insists on an API token — so inject one:

```bash
secretctl run --ref CLOUDFLARE_API_TOKEN=isec://claude-ops/prod/CLOUDFLARE_API_TOKEN \
  -- bash -c 'set -a; . ~/.cloudflare-credentials.env; set +a; ./build.sh --deploy'
```

That ref is the **claude-workers** token and it carries Pages Write. It appeared
dead on 2026-09-01 — the monthly rotator rolled it and could not store the new
value, so Infisical kept a superseded copy — which is why an earlier version of
this file said a separate Pages token was needed. It was not. Fixed in
`dev-ops/netcup/scripts/cf-rotate-token.sh`; rotated and verified 2026-09-02.

`CLOUDFLARE_JEFF_MAIN_API` is a different token and has no Pages permission.

Note that `dist/` (the tour, plus the redirect for the old atlas URL) and
`dist-atlas/` (the atlas payload, served from the wiki) are separate trees. The
atlas is deployed by `infra/p2pwiki-atlas/deploy.sh`, not by this.
