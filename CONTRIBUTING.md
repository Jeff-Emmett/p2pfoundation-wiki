# Contributing to the P2P Foundation Wiki Archive

Thank you for helping preserve and improve the P2P Foundation's knowledge base.

## Article Editing

### File Format

Articles are stored as `.mediawiki` files in the `wiki/` directory. They use standard [MediaWiki markup](https://www.mediawiki.org/wiki/Help:Formatting).

### File Naming

- Use the article title with spaces replaced by underscores: `Commons-Based_Peer_Production.mediawiki`
- Match the naming convention of existing files
- Special characters in titles should be URL-encoded or simplified

### Making Changes

1. Fork this repository
2. Create a branch: `git checkout -b fix/article-name`
3. Edit the `.mediawiki` file(s)
4. Commit with a descriptive message: `fix: correct broken links in Peer_Production article`
5. Open a Pull Request

### What to Contribute

- **Corrections**: Fix typos, broken links, outdated information
- **Updates**: Add new developments to existing topics
- **New articles**: Add articles about peer-to-peer topics not yet covered
- **Formatting**: Improve markup consistency

### Style Guide

- Use `==Section==` for headings (not `=Title=` which is reserved for the page title)
- Internal wiki links: `[[Article Name]]` or `[[Article Name|display text]]`
- External links: `[https://example.com display text]`
- Categories at the bottom: `[[Category:Name]]`

## Infrastructure Changes

For changes to Docker configs or deployment scripts in `infra/`:

1. Test locally with `docker compose up -d`
2. Ensure no secrets are hardcoded (use `${ENV_VAR}` placeholders)
3. Document any new environment variables in `.env.example`

## Questions?

Open an issue or reach out via the [P2P Foundation](https://p2pfoundation.net) website.
