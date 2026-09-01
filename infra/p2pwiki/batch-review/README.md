# batch-review

A closed-testing approval queue for bulk wiki changes. Proposals are generated
offline; a human reads them and presses Commit; only then does anything get
written. Built for the taxonomy work in the September 2026 category audit, but
the applier is generic.

**Live at** <https://wiki.p2pfoundation.net/p2pwiki-custom/batch-review/>
(you must be logged in to the wiki *and* on the reviewer list, or you get a
flat 403 with no detail).

## Current state

- `live_writes` is **false**. Approvals are recorded and you see the exact
  wikitext each one would produce, but nothing is written. Flip it in
  `config.php` when the three of you are satisfied.
- Reviewers: `JeffEmmett`, `Mbauwens`. **Bryan has no account yet** — add his
  username to `reviewers` in `config.php` once he registers. No other change is
  needed; the list is re-read on every request.

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

**Idempotent by construction.** Every item is re-planned against the live wiki
at commit time. An item whose change is already present comes back `already`
and is skipped rather than forced, so re-running a generator or committing a
batch twice cannot double-tag a page.

**Conflict-aware.** Each edit carries `basetimestamp`, so if somebody edited the
page between generation and commit, the API refuses and the item is recorded as
an error instead of clobbering their work.

## Reviewing a batch

1. Open the queue, pick a batch.
2. **Check against the wiki** re-plans the visible page and marks each item
   `ready` / `already` / `missing`. Worth doing before a big approve.
3. Decide items — per row, or *Approve this page* / *Approve all*.
4. **Save decisions**, then **Review & commit**.
5. The commit screen shows the exact change every approved item will make.
   Nothing has happened yet. Press the button to apply.

Decisions freeze once a batch is committed; the batch keeps a per-item record
of what happened, and a copy goes to `log/`.

## Generating batches

Generators are CLI-only and read-only against the wiki. **Run them as
`www-data`** or the batch files land root-owned and the web app cannot update
them:

```bash
ssh netcup-full
docker exec -u www-data p2pwiki php \
  /var/www/html/p2pwiki-custom/batch-review/generate/<script>.php [options]
```

### `gen_category_parents.php` — wire sub-topics to their parent

Reads `data/families.php`, checks each edge against the live wiki, proposes
only the genuinely missing ones. Touches category pages only, never articles.

```bash
… gen_category_parents.php --id cat-parents-2026-09-01
… gen_category_parents.php --id commons-only --family Commons
```

### `gen_rollup.php` — put articles in the primary their sub-topics imply

An article in `Urban Commons` and `Credit Commons` but not `Commons` is
invisible to anyone browsing the commons. `--min-evidence 2` means only propose
articles carried by two or more sub-topics; that is the sane starting point.

```bash
… gen_rollup.php --family Commons --min-evidence 2 --limit 60
```

### `gen_uncategorised.php` — suggest a subject for articles that have none

`--mode none` for articles with no category at all; `--mode format` for those
carrying only Books/Webcasts/Bios-type tags, which say what a page *is* rather
than what it is *about*.

Scoring is keyword-based on purpose — every item shows the words that triggered
it and the runner-up, so a bad call is obvious without opening the article.
**The default `--min-score 6` is deliberately permissive**; raise it to ~20 for
a batch that is mostly right, or leave it low when you want the reject path
exercised.

```bash
… gen_uncategorised.php --mode none --limit 40 --min-score 20
```

### `gen_synthesis.php` — draft an article from existing articles

Sends the text of named wiki articles to an OpenAI-compatible endpoint and
stores the returned draft in a batch for a human to read. Approving creates a
page in **`Draft:`** — never the main namespace — with a provenance box listing
every source. Promoting a draft into the encyclopedia stays a separate human
act.

```bash
docker exec -u www-data \
  -e BR_LLM_BASE=http://litellm:4000/v1 \
  -e BR_LLM_KEY=<virtual key> \
  -e BR_LLM_MODEL=gx10-coder \
  p2pwiki php …/gen_synthesis.php \
    --title "Cosmo-Local Production" \
    --sources "Cosmo-Localism|Design Global, Manufacture Local|Fab City"
```

`--dry` assembles the prompt and prints it without calling the model. The wiki
container can reach `http://litellm:4000` but needs a key — there is no key
wired into this stack yet, so this generator is the one piece not yet exercised
end to end.

## Files

| Path | What it is |
|---|---|
| `config.php` | Reviewer list, the `live_writes` switch, caps |
| `lib.php` | Auth, loopback API client, the operations, planning and applying |
| `index.php` / `batch.php` / `commit.php` | The three screens |
| `data/families.php` | Which categories are sub-topics of which — the one editorial judgement, kept in one reviewable file |
| `data/vocab.php` | Keyword vocabulary per subject, used only for suggestions |
| `generate/*.php` | Proposal generators, CLI only |

State lives in `/var/editor-request-data/batch-review/` inside the container
(`./editor-request-data/batch-review/` on the host) — `batches/` and `log/`,
both outside the docroot.

## Deployment

The app sits in the existing read-only `./extensions` bind mount, so deploying
is a file copy with **no compose change and no container restart**:

```bash
cd infra/p2pwiki && tar czf /tmp/br.tgz batch-review
ssh netcup-full 'cat > /tmp/br.tgz' < /tmp/br.tgz
ssh netcup-full 'cd /opt/websites/p2pwiki/extensions && tar xzf /tmp/br.tgz && rm /tmp/br.tgz'
```

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

Every edit's summary carries `[batch-review <batch-id>]`. Once `live_writes` is
on, that makes a whole batch findable in Special:Contributions and revertable
page by page, or with `rollbackEdits.php` for a run by one user. There is no
one-click undo in the UI yet — deliberately, while this is in testing.
