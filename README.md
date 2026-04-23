# P2P Foundation Wiki Archive

Content archive and infrastructure configs for the [P2P Foundation](https://p2pfoundation.net) — a global network focused on peer-to-peer dynamics in technology, governance, and economics.

## What's Here

| Directory | Contents | Size |
|-----------|----------|------|
| `wiki/` | 41,500+ MediaWiki articles as `.mediawiki` files (main namespace only) | ~275 MB |
| `xmldump/` | 31 paginated Special:Export XMLs from an earlier seed (Git LFS) — **superseded** by the self-hosted dump endpoint below | ~507 MB |
| `blog/` | Static blog server configs (mirror is rsync'd separately) | configs only |
| `infra/p2pwiki/` | Docker Compose stack for wiki.p2pfoundation.net — mirrors live `/opt/websites/p2pwiki/` | configs only |

## Live dumps

Canonical wiki exports are now generated server-side by `dumpBackup.php` / `dumpUploads.php` and served at:

- <https://wiki.p2pfoundation.net/dumps/> — directory index
- `…/p2pwiki-latest-current.xml.bz2` — current revisions, all namespaces (~53 MB)
- `…/p2pwiki-latest-history.xml.bz2` — full revision history (~135 MB)
- `…/p2pwiki-latest-images.tar` — all uploaded images (~1.7 GB)
- `…/p2pwiki-latest-uploads.txt` — image filename list

Current XML regenerates weekly; history + images monthly. See [`infra/p2pwiki/README.md`](infra/p2pwiki/README.md) for the cron script and import instructions. Unlike `xmldump/`, these cover **all namespaces** — Templates, Categories, Files, Talk, User, Draft, MediaWiki, Help — which is what you want if you're bootstrapping a working mirror.

## Quick Start

### Browse Articles

Articles are plain text MediaWiki markup — open any file in `wiki/` to read it:

```bash
cat wiki/Commons-Based_Peer_Production.mediawiki
```

### Run the stack (operators)

```bash
cd infra/p2pwiki/
cp .env.example .env   # fill in DB_ROOT_PASSWORD, DB_PASSWORD (or source from Infisical)
docker compose up -d
```

`LocalSettings.php` is not in the repo — pull from the Netcup backup or generate a fresh one with `maintenance/install.php`.

### Getting a full wiki snapshot

Prefer the live endpoint (always current, all namespaces):

```bash
curl -O https://wiki.p2pfoundation.net/dumps/p2pwiki-latest-history.xml.bz2
curl -O https://wiki.p2pfoundation.net/dumps/p2pwiki-latest-images.tar
```

The older Git LFS dumps in `xmldump/` are kept for historical reference only:

```bash
git lfs pull
```

## Blog Static Mirror

The blog.p2pfoundation.net static HTML mirror (~6.8 GB, ~130K files) is too large for Git. It's deployed via rsync:

```bash
rsync -avz --progress user@source:/path/to/blog.p2pfoundation.net/ blog/blog.p2pfoundation.net/
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for how to edit articles, submit corrections, or add new content.

## Related

- **[p2pwiki-ai](https://gitea.jeffemmett.com/jeffemmett/p2pwiki-ai)** — AI-powered chat and article generation using this content
- **[wiki.p2pfoundation.net](https://wiki.p2pfoundation.net)** — Live wiki
- **[blog.p2pfoundation.net](https://blog.p2pfoundation.net)** — Blog

## License

- **Wiki content**: [CC BY-SA 3.0](https://creativecommons.org/licenses/by-sa/3.0/) (P2P Foundation)
- **Infrastructure configs**: [MIT](https://opensource.org/licenses/MIT)
