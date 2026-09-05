# batch-review

A closed-testing approval queue for bulk wiki changes. Proposals are generated
offline; a human reads them and presses Commit; only then does anything get
written. Built for the taxonomy work in the September 2026 category audit, but
the applier is generic.

**Live at** <https://wiki.p2pfoundation.net/p2pwiki-custom/batch-review/>

## Who can use it

**Two accounts. `JeffEmmett` and `Mbauwens`.** Everyone else — logged in or not,
sysop or not — gets a flat 403 with no detail: no batch list, no item counts, no
hint that the tool exists. Every refusal is logged.

Each entry in `reviewers` pins **both the username and the numeric user id**, and
both must match:

```php
'reviewers' => [
    [ 'name' => 'JeffEmmett', 'id' => 2943 ],
    [ 'name' => 'Mbauwens',   'id' => 9 ],
],
```

The id is not belt-and-braces. Both of these accounts hold `bureaucrat`, which is
the right that renames accounts, so a username on its own is a mutable key:
rename an account and the freed name can be registered by anyone, who would then
inherit its access. With the id, the gate follows the account rather than the
string. A right-name/wrong-id request is refused *and* logged, because that is
the case the id exists to catch.

Three more things the gate checks, in this order: you are logged in; you are on
the list; your account is not blocked (`refuse_blocked`); you hold `edit`. Note
that **`MBauwens bot` (id 2946) is deliberately not a reviewer** — a bot password
can be handed around, and an approval has to be traceable to a person.

Adding a third person is a deliberate act, not a config tweak: get the exact
username and the id from
`api.php?action=query&list=users&ususers=<name>&usprop=groups`, add the pair, and
say in the deploy notes who asked for it. The list is re-read on every request,
so no restart is needed.

`log/access-YYYYMM.jsonl` in the data dir records every open, every denial and
every commit chunk, including for the people the gate turned away — who leave no
trace anywhere else.

## Current state

- `live_writes` is **false**. Approvals are recorded and you see the exact
  wikitext each one would produce, but nothing is written. Flip it in
  `config.php` when you are both satisfied.
- The rate limit is one edit every **5 s**, **200 edits per commit**.
- Anyone at all can stop every commit by writing anything on
  [`P2P Foundation Wiki:Batch review/STOP`](https://wiki.p2pfoundation.net/index.php?title=P2P_Foundation_Wiki:Batch_review/STOP).

### The queue

All eight batches are live. The staging area is gone — everything generated has
been promoted.

| batch | items | kind |
|---|---|---|
| `facets-2026-09-04` | 1 | reattach the detached Entity facet root |
| `merge-spelling-20260904` | 14 | retire live typos by rewriting each member's tag |
| `synth-cosmo-local-production-20260902` | 1 | machine-written draft, into `Draft:` |
| `categories-index-20260904` | 1 | writes the 17 KB curated Categories index |
| `uncat-none-2026-09-01` | 40 | guess a subject for uncategorised articles |
| `links-mentions-20260904` | 59 | bracket a phrase the author already wrote |
| `rollup-commons-2026-09-01` | 60 | roll articles up into `Category:Commons` |
| `cat-parents-2026-09-01` | 82 | wire sub-topic categories to their parent |

258 items. Every one of the eight was confirmed to load and plan against the
live wiki on 5 September. `merge-spelling` leaves four empty category *pages*
behind — `Ethical Economy`, `Korea`, `Standard`, `United Kingdom` hold no
articles, so there is nothing for a batch to rewrite; deleting those four is a
sysop's job and the generator says so rather than pretending otherwise.

### The guarantees are tested, not asserted

Checked 5 September, none of it requiring a real edit:

| guarantee | how it was shown |
|---|---|
| closed reviewer list | `Claude bot` (2950) — valid, logged in, holds `edit`, not on the list → **403**, no batch names, no counts |
| **blocked accounts refused** | `BR gate test` (2952) put ON the list → **200, full queue**. Then blocked on the wiki, nothing else changed → **403 "Your wiki account is currently blocked."** The block was the only variable, so it is the only possible cause |
| every denial logged | `deny.anon`, `deny.not-reviewer`, `deny.blocked` in `log/access-YYYYMM.jsonl`, each with username and numeric id |
| one revision per article | `tests/lib-test.php` grouping cases |
| **chunked + resumable commit** | 82-item batch with `commit_budget_seconds` turned down: 43 items → `Continue — 33 pages left` → 39 items → done. Both passes share one `run` id in the log |
| STOP halts everything | `stop_page` pointed at a page that has text → `halted=true`; pointed back at the absent one → `halted=false` |

The two that remain unexercised under **live** writes are the 5-second inter-edit
pause (`commit.php` only calls `usleep()` when `live_writes` is true, which is
why the 82-item dry run took 3.1s) and a real `editconflict`. Both need a human
approver and `live_writes => true`.

Testing the blocked path needs the account ON the reviewer list first — the gate
runs logged-in → on the list → not blocked, so an off-list account is refused as
`deny.not-reviewer` and never reaches the block check at all. `BR gate test`
(2952) is left blocked and off the list; it is inert, and MediaWiki cannot
delete accounts.

### What the link batch measured

`gen_links` has run (`links-mentions-20260904`, staged, 59 items). Its
candidates are the 60 rows in `demo/aiwiki/graph/data/batch.wikitext`,
converted to the JSONL it wants — id, source, target, sentence.

That corpus is a frozen wikitext mirror: `demo/aiwiki/graph/scripts/extract-corpus.py`
reads `<repo>/wiki/*.mediawiki`, a tree from 14 April 2026 last touched on
10 June, against a live wiki now at 150,201 edits. The obvious worry is that
five-month-old proposals are worthless.

**They are not, and now we have the number.** `gen_links` re-fetches every
source article and re-locates the phrase before it will propose anything —
recorded offsets are never trusted. Of 60 candidates, **59 survived**: 1 phrase
no longer appears unlinked, 0 were already linked, 0 pages had gone. So the
decay on this particular signal is about 1.7%, which makes sense — a phrase an
author already wrote in prose does not churn the way categories or titles do.
Refreshing the corpus is still right, but it is a yield improvement, not a
correctness fix, and nothing here is blocked on it.

Two rows in that batch are worth a reviewer's suspicion, and they are the
argument for reading the sentence column rather than bulk-approving:

- `Non-Exclusive Dunbar Number` → `[[Exclusive Dunbar Number]]`. The target
  title is a substring of the source title. The word-boundary check passes
  because the hyphen is a boundary, so this is a true match of a phrase that
  means the opposite of what the link says.
- `Project State and its Rivals` → `[[Project State and Its Rivals]]`. The two
  differ only in the case of "its". That is a duplicate-article pair, and the
  right fix is a merge, not a link.

## Why it is built this way

**Generate offline, approve online.** The web app never calls a model and never
invents a proposal. It reads batch files, shows them, and applies the ones a
human approved. Everything expensive, slow, or fallible happens in
`generate/`, where it can be re-run and diffed.

**Edits are made as the reviewer.** The app runs inside the wiki container and
calls the API over loopback, forwarding your own session cookie. So every edit
lands in your contributions, passes the wiki's normal permission checks, and
reverts like any other edit. The tool has no account and no privileges of its
own.

## The eight guarantees

These are properties of the code, not intentions. `tests/lib-test.php` covers the
ones that can be tested without touching the wiki; run it with
`docker exec -u www-data p2pwiki php .../tests/lib-test.php`.

| # | Guarantee | Where it lives |
|---|---|---|
| 01 | **Dry run by default.** Without `live_writes` the tool shows the diffs it would make and writes nothing. | `config.php`, `br_apply_group()` |
| 02 | **Re-verified at the moment of writing.** Offsets and text recorded when a batch was generated are never trusted; the live page is re-fetched and the change re-planned. If the article has moved on the row is skipped as `stale`. | `br_plan_group()` |
| 03 | **Idempotent.** An article that already carries the tag, or already links to the target, is left alone. Running the same batch twice is a no-op the second time. | every op reports `already` |
| 04 | **One revision per article.** All approved items for a page go in a single edit, so a page's history stays readable rather than accumulating one revision per item. | `br_group_items()` |
| 05 | **Every edit names its approver.** The summary carries the batch id, the row ids and the person who approved — taken from the item's own decision, not from whoever pressed Commit. | `br_group_summary()` |
| 06 | **Reversible.** Each applied row records its revision id; `undo.php` walks a whole batch back out through MediaWiki's own undo, which refuses cleanly if someone has edited since. | `undo.php` |
| 07 | **Rate limited.** One edit every 5 s, 200 per run. A session of approvals cannot look like an attack or flood a watchlist. | `edit_delay_us`, `max_items_per_commit` |
| 08 | **Stoppable by anyone.** Any text at all on `P2P Foundation Wiki:Batch review/STOP` halts every commit, for everyone. No credentials needed to pull the handle — it is an ordinary wiki page. | `br_stop_state()` |

Guarantee 07 has a consequence: 200 items at 5 s is about **17 minutes**, and no
HTTP request survives that — Cloudflare gives up at 100 s with a 524. So a commit
runs in **chunks**: each request applies what fits in `commit_budget_seconds`,
saves, and hands back a Continue button that auto-submits. The batch freezes only
when every approved item has a result, so an interrupted commit is resumable
rather than half-done and forgotten.

The rate limit counts **edits**. A `bookmark-visibility` decision writes one line
to a local ledger and touches neither the wiki nor the network, so those do not
count against it — thousands of them commit in one pass.

If the STOP page cannot be *read*, that counts as stopped when live writes are
on, and as clear during a dry run. A safety handle that stops working when the
wiki is unwell is not a safety handle.

## Reviewing a batch

1. Open the queue, pick a batch.
2. **Check against the wiki** re-plans the visible page and marks each item
   `ready` / `already` / `stale` / `missing`. It groups exactly as a commit
   would, so two items on one page show as one edit here too.
3. Decide items — per row, *Approve this page*, *Approve all*, or **Adopt
   suggestions**, which takes the generator's own recommendation as your
   decision on every row that carries one. Rows with no suggestion are never
   touched by it: nothing here turns an absence into an approval.
4. **Save decisions**, then **Review & commit**.
5. The commit screen shows the exact change every approved item will make.
   Nothing has happened yet. Press the button to apply.
6. Afterwards: **Publish the decision record to the wiki** writes what was
   proposed, who decided what and what happened to
   `P2P Foundation Wiki:Batch review/<batch-id>` — so the record survives us.

Decisions freeze once a batch is committed for real; a dry run leaves it open.

## Generating batches

Generators are CLI-only and read-only against the wiki. **Run them as
`www-data`** or the batch files land root-owned and the web app cannot update
them:

```bash
ssh netcup-full
docker exec -u www-data p2pwiki php \
  /var/www/html/p2pwiki-custom/batch-review/generate/<script>.php [options]
```

### `check.php` — audit the data files before trusting them

```bash
… check.php --what data     # does every category we name actually exist?
… check.php --what vocab    # do our keywords discriminate?
```

`--what data` catches the failure that is otherwise invisible: a typo in
`families.php` or `merges.php` produces a row that silently never fires.

`--what vocab` measures every keyword against the corpus and flags the two ways
a vocabulary fails quietly — terms that match nothing, and terms so broad they
separate nothing. This exists because of what happened to the nine reading-room
lenses: **41 of their 91 keywords match no category at all**, one lens reaches
zero pages, another reaches 20,430, and three fail on a space alone
(`peer production` never matches the category `Peerproduction`, which with
`Peergovernance` and `Peerproperty` is 2,549 pages the opening lens exists to
hold and cannot see). Those lenses were a deliberately designed, controlled
vocabulary, so control was not the missing ingredient. Discrimination is, and it
can only be measured against the corpus you actually have.

### `gen_facets.php` — reattach the facet roots (Tier 1)

The cheapest edit on the wiki. `Category:P2P Entity Type` declares no parent, and
that one missing line detaches a whole facet: Movements (3,745 articles) and
Conferences (453) are unreachable from the root because of it. Adding it lifts
root reachability from 85.3% to 87.2%.

```bash
… gen_facets.php --id facets-2026-09-04
```

### `gen_category_parents.php` — wire sub-topics to their parent (Tier 2)

Reads `data/families.php`, checks each edge against the live wiki, proposes only
the genuinely missing ones. Touches category pages only, never articles. The 82
missing edges; attaching just the 52 largest takes root reachability to 98.9%.

```bash
… gen_category_parents.php --id cat-parents-2026-09-01
… gen_category_parents.php --id commons-only --family Commons
```

### `gen_category_merge.php` — merge, rename, retire the tail (Tier 3)

Reads `data/merges.php`. Four groups: `spelling` (17 duplicate spellings),
`french` (9 — folds the parallel French taxonomy into the Language facet by
moving each page into the ordinary category *and* tagging it `French`, in one
edit), `renames` (4 — the WikiSprint residue whose names claim to be general),
`countries` (17 single-article countries folded up into their continent).

```bash
… gen_category_merge.php --group spelling
… gen_category_merge.php --group french --limit 200
```

**It refuses to merge a category holding more than `--max-members` (250)** unless
you pass `--force`. That is the audit's clearest negative finding enforced rather
than remembered: across every pair of categories holding 250+ articles the
highest Jaccard similarity is 28.9%, so the forty categories that carry the wiki
are genuinely about different things and merging any pair destroys a real
distinction. The wiki's problem is not too many categories — it is that they are
not connected to each other.

### `gen_rollup.php` — put articles in the primary their sub-topics imply

An article in `Urban Commons` and `Credit Commons` but not `Commons` is
invisible to anyone browsing the commons. `--min-evidence 2` means only propose
articles carried by two or more sub-topics; that is the sane starting point.
Across twelve primaries this is ~18,300 memberships the wiki's own tagging
already implies but does not record.

```bash
… gen_rollup.php --family Commons --min-evidence 2 --limit 60
```

### `gen_uncategorised.php` — suggest a subject for articles that have none (Tier 4)

`--mode none` for the 1,276 articles with no category at all; `--mode format` for
the 1,665 carrying only Books/Webcasts/Bios-type tags, which say what a page *is*
rather than what it is *about*.

Scoring is keyword-based on purpose — every item shows the words that triggered
it and the runner-up, so a bad call is obvious without opening the article.
**The default `--min-score 6` is deliberately permissive**; raise it to ~20 for
a batch that is mostly right, or leave it low when you want the reject path
exercised.

```bash
… gen_uncategorised.php --mode none --limit 40 --min-score 20
```

### `gen_index_page.php` — the curated Categories page (Tier 5)

There is no index worth landing on: `Category:Categories`, `Category:Contents`
and `Category:Browse` all return nothing, so browsing by topic means reading 499
names alphabetically, *15M Movements* first, with no sign that 38 are empty and
127 hold two articles or fewer. This builds one page — five facets, sixteen
subject primaries, live counts — and proposes it as a single `write-page` item
shown as a line diff.

```bash
… gen_index_page.php --page "P2P Foundation Wiki:Categories" --exact
```

`--exact` computes each primary's true article count by unioning membership; it
is correct and slow. Without it the page shows per-category counts only, which
are exact per line and never summed — summing them would double-count every
article in two of a primary's categories.

### `gen_links.php` — put brackets around words the authors already wrote

The wiki holds 39,915 articles and barely one link apiece; 64% link nowhere.
Comparing articles by subject surfaces 59,267 unlinked related pairs, which is
not a review queue but a denial of service on an editor's attention. One rule
does nearly all the subtraction, and it is not about similarity: **the target's
exact title must already appear in the source's prose, unlinked**. The words are
the author's, so approving one adds no claim, no sentence and no assertion — four
characters.

```bash
… gen_links.php --from /tmp/link-candidates.jsonl --limit 60
```

Input is JSONL or CSV with `source`, `target`, optional `sentence` and `score`,
produced by `demo/aiwiki/graph/scripts/`. Filters, cheapest first:
`--min-words 2` (single common nouns are what overlinking is made of: *…integrate
the information* is not a reference to `[[Information]]`), exact case,
`--max-per-page 3`, then the live check.

**Typed links are deliberately unsupported.** A candidate carrying a `predicate`
is refused rather than quietly stripped, and `link-mention` errors on one at
apply time too. An untyped suggestion's whole claim to safety is that approving
it asserts nothing; a predicate is an assertion and needs a different, higher
standard of review. Typed links are the right second step, on their own track,
with their own approval — and their value depends on editors accepting untyped
suggestions first, so if the acceptance rate on the first batch is poor they are
moot anyway.

### `gen_synthesis.php` — draft an article from existing articles

Sends the text of named wiki articles to an OpenAI-compatible endpoint and
stores the returned draft in a batch for a human to read. Approving creates a
page in **`Draft:`** — never the main namespace — with a provenance box listing
every source. Promoting a draft into the encyclopedia stays a separate human
act.

Two endpoints are reachable from the wiki container, each with its own key:

| Endpoint | Key ref | Models |
|---|---|---|
| `http://100.64.0.5:4001/v1` (GX10, over the tailnet) | `isec://claude-ops/prod/claude-fabric/LITELLM_GX10_KEY` | `gx10-heavy`, `gx10-general`, … — local, free |
| `http://litellm:4000/v1` (Netcup) | `isec://claude-ops/prod/claude-fabric/LITELLM_NETCUP_KEY` | the full paid list plus `local-*` |

Prefer GX10: the models are local and free, and a draft takes ~20 s. Run it
without the key ever touching a file, your shell history or a process argument
list — `/root/br-synth.sh` on Netcup reads it from stdin and hands it to
`docker exec` through the environment:

```bash
secretctl run --ref K=isec://claude-ops/prod/claude-fabric/LITELLM_GX10_KEY -- \
  sh -c 'printf "%s\n" "$K" | ssh netcup-full \
    "BR_LLM_BASE=http://100.64.0.5:4001/v1 BR_LLM_MODEL=gx10-heavy /root/br-synth.sh \
      --title \"Cosmo-Local Production\" \
      --sources \"Cosmo-Localism|Design Global, Manufacture Local|Fab City\""'
```

`--dry` assembles the prompt and prints it without calling the model or needing
a key. `--timeout` caps the model call, default 900 s — the wiki's own API calls
keep their 30 s, which a thousand-word generation would blow through.

The warning box at the top of a draft is **inline wikitext, not a template**.
`{{Synthesised draft}}` does not exist on this wiki, and a call to a missing
template renders as a red link — which would have turned the one visible safety
warning into a broken link. Nothing needs installing on the wiki first.

## The Diigo public / private gate

Michel's Diigo archive is 118,728 bookmarks; a few thousand are private. The
public ones already feed the wiki work. The private ones must not — and until
someone says so, one at a time, none of them can.

The old shape of this was a CSV: 2,629 rows emailed out, ticked in a spreadsheet,
re-imported. That round trip is why the integration has been on hold since 27 May
2026 — one file in one inbox, no audit trail, and no way to do half of it. The
same decision now lives in the review queue:

| decision | what it means |
|---|---|
| **approve = public** | the URL becomes eligible for `gen_links.php`, `gen_synthesis.php`, the Portico corpus, and the re-import that flips `shared` on Diigo |
| **reject = private** | it stays out of the wiki, out of the LLM pipeline and out of every export, permanently |
| **blank** | neither. It stays private. Doing nothing is always safe. |

The CSV's own posture — default TRUE, uncheck the exceptions — survives as a
**suggestion** per row rather than as a decision. *Adopt all suggestions* is one
click, so nothing is slower; the difference is that a release is always something
a person chose, and the batch records who and when.

```bash
# stage the CSV into the container (it is gitignored, and lives on the dev box)
docker cp diigo/private_review.csv p2pwiki:/tmp/private_review.csv
docker exec -u www-data p2pwiki php …/generate/gen_diigo_visibility.php \
    --from /tmp/private_review.csv --limit 500
# … review and commit in the UI …
docker exec -u www-data p2pwiki php …/generate/export_diigo_release.php \
    --out /tmp/released.jsonl --source /tmp/mbauwens.jsonl
```

**Deciding writes one line to a ledger and nothing else** —
`<data_dir>/diigo/decisions.jsonl`, append-only, latest line wins. No bookmark
reaches any pipeline until `export_diigo_release.php` is run, and that reads the
*ledger*, never a batch file, so a bookmark that was merely proposed, or approved
but not committed, or left blank, cannot get out. **Absence is not consent.**

`--stats` prints who decided what without writing anything. `export` refuses to
write an empty file over an existing one.

The bookmark's own quoted text is shown to the reviewer, truncated, because you
cannot judge a bookmark you cannot see. It stays inside the container behind the
reviewer gate and leaves only if the bookmark is released.

## Files

| Path | What it is |
|---|---|
| `config.php` | Reviewer list (name **and** id), `live_writes`, rate limit, stop page, caps |
| `lib.php` | Auth, loopback API client, the operations, grouping, planning, applying |
| `index.php` / `batch.php` / `commit.php` / `undo.php` | The four screens |
| `data/families.php` | Which categories are sub-topics of which — one editorial judgement, one reviewable file |
| `data/facets.php` | The five facet roots and the sixteen subject primaries — the 2013 scheme, written down |
| `data/merges.php` | Tier 3: duplicate spellings, the French fork, the WikiSprint renames, single-article countries |
| `data/vocab.php` | Keyword vocabulary per subject, used only for suggestions |
| `generate/*.php` | Proposal generators and audits, CLI only |
| `tests/lib-test.php` | The guarantees that can be tested without touching the wiki |

State lives in `/var/editor-request-data/batch-review/` inside the container
(`./editor-request-data/batch-review/` on the host) — `batches/`, `log/` and
`diigo/`, all outside the docroot.

## Operations

| op | does |
|---|---|
| `append-category` | adds one `[[Category:X]]` line |
| `replace-category` | rewrites one category tag to another, keeping any sortkey |
| `link-mention` | wraps the first unlinked occurrence of a title in `[[brackets]]` |
| `write-page` | replaces a whole page, shown as a line diff |
| `create-draft` | creates a page that must not already exist |
| `classify-bookmark` | records a Diigo release decision — no wiki edit at all |

Items sharing a page are applied in one edit, in item order. A `write-page` or
`create-draft` can never share an edit with anything else.

## Deployment

The app sits in the existing read-only `./extensions` bind mount, so deploying
is a file copy with **no compose change and no container restart**:

```bash
cd infra/p2pwiki && tar czf /tmp/br.tgz --exclude=batch-review/bin batch-review
ssh netcup-full 'cat > /tmp/br.tgz' < /tmp/br.tgz
ssh netcup-full 'cd /opt/websites/p2pwiki/extensions && tar xzf /tmp/br.tgz && rm /tmp/br.tgz'
```

`bin/` is deliberately excluded: those scripts run on the Netcup **host**, not
inside the container, and the extensions directory is served over the web.
`bin/br-synth.sh` belongs at `/root/br-synth.sh`, mode 700.

**Opcache holds compiled PHP for up to 60 seconds**, so a change does not take
effect instantly and a test run straight after a copy can report the old
behaviour. Wait a minute before concluding a fix did not work.

## A note on `.htaccess` on this wiki

`.htaccess` files here are read, but their **access control is inert**:
`conf-enabled/block-bots.conf` carries a site-wide

```apache
<Location "/">
    Require all granted
</Location>
```

and in Apache a `<Location>` is merged after every `<Directory>` and every
`.htaccess`, so it overrides any `Require all denied` you write in one. That is
why every non-entry-point file in this app guards itself in PHP:

```php
if ( !defined( 'BR_ENTRY' ) && PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit; }
```

The `.htaccess` files are kept as defence in depth for the day that `<Location>`
block is narrowed, but nothing relies on them. **Anything else on this wiki that
depends on `.htaccess` for access control is not actually protected** — worth
checking `editor-request/` on the same basis.

## Reverting a live batch

`undo.php` (linked from a committed batch) walks the whole batch back out through
MediaWiki's own undo, newest revision first, at the same one-edit-every-5s rate,
honouring the same STOP page. A page somebody has edited since will refuse rather
than lose their work — a refusal there is the tool behaving, not failing.

Failing that, every edit's summary carries `[batch-review <batch-id> #n]`, so a
whole batch is findable in Special:Contributions and revertable page by page, or
with `rollbackEdits.php` for a run by one user.

## Not built here, on purpose

Four things from the source documents that this tool deliberately does not do.

- **Typed / semantic links.** See `gen_links.php` above. A predicate is an
  assertion; it needs its own track and its own approval, after untyped links
  have earned an acceptance rate.
- **Search and replace across the wiki.** Technically easy, which is the danger.
  A bulk rewrite tool on a 39,915-article commons is an accident waiting to
  happen. Every op here is narrow and named.
- **Creating the sixteen subject primaries as categories.** `data/facets.php`
  holds them, and `gen_index_page.php` renders them, but no generator mints them:
  the audit's tiers stop short of sixteen new category pages, and so does this.
  The same goes for the proposed seventeenth, Crypto & Blockchain.
- **Deciding what the taxonomy should be.** Restructuring someone else's
  taxonomy is a governance act, not a feature. This supplies the evidence and
  applies what a person approved.
