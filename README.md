# P2P Foundation Wiki Archive

Content archive and infrastructure configs for the [P2P Foundation](https://p2pfoundation.net) — a global network focused on peer-to-peer dynamics in technology, governance, and economics.

## What's Here

| Directory | Contents | Size |
|-----------|----------|------|
| `wiki/` | 41,500+ MediaWiki articles as `.mediawiki` files | ~275 MB |
| `xmldump/` | 31 XML database dumps (Git LFS) | ~507 MB |
| `blog/` | Static blog server configs (mirror is rsync'd separately) | configs only |
| `infra/` | Docker Compose stack for the full MediaWiki + WordPress platform | configs only |

## Quick Start

### Browse Articles

Articles are plain text MediaWiki markup — open any file in `wiki/` to read it:

```bash
cat wiki/Commons-Based_Peer_Production.mediawiki
```

### Run the Full Stack (Operators)

The `infra/` directory contains the complete Docker Compose setup for wiki.p2pfoundation.net, blog.p2pfoundation.net, and related sites.

```bash
cd infra/
cp .env.example .env
# Fill in database passwords (or use Infisical)
docker compose up -d
```

### XML Dumps

XML dumps are stored via Git LFS. After cloning:

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
