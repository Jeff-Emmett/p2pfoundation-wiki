# P2P Wiki Atlas

A 2-D map of every article in the wiki, laid out by what the articles are
about, with a search that highlights up to three terms at once.

Published artifact: https://claude.ai/code/artifact/bbde063a-323e-4b92-9fcb-7b0a58291c00

## Pipeline

1. `dumpGraph.php` — run inside the `p2pwiki` container as a MediaWiki
   maintenance script. Writes `/tmp/pages.tsv`, `/tmp/cats.tsv`,
   `/tmp/links.tsv` using the wiki's own DB credentials, so no secret has to
   leave Infisical.

   ```
   docker cp dumpGraph.php p2pwiki:/var/www/html/maintenance/
   docker exec p2pwiki sh -c 'cd /var/www/html && php maintenance/dumpGraph.php'
   ```

   `pagelinks` came back with only 45k rows for 39k articles — the link tables
   were never rebuilt after the XML import, so the layout uses text and
   categories, not links.

2. `build_map.py` — TF-IDF over the article wikitext in `../../wiki`,
   concatenated with category membership (weighted up, because the categories
   are curated and the text is not), SVD to 80 dims, KMeans for 26 regions,
   t-SNE for the layout. Regions are named from the categories their members
   share most distinctively. ~3 minutes. Needs scikit-learn in a venv.

3. `pack.py` — pulls each point toward its region centroid and pushes the
   centroids apart, so the regions read as islands rather than one ball;
   precomputes a trimmed convex hull per region; packs coordinates as
   base64 Uint16 and titles/categories as newline-joined strings. ~1.9 MB.

4. `assemble.py` — inlines `atlas.head.html`, `atlas.body.html`,
   `atlas.script.js` and the payload into one self-contained page.

## Design notes

Colour carries search state, not region identity: the validated categorical
palette only clears the all-pairs colour-blindness floors at three hues, and
26 regions coloured 26 ways would be unreadable anyway. Regions are carried by
position, labels and the outline that appears when you pick one from the rail.
