---
id: TASK-1
title: 'Atlas: topic islands, on-canvas gists, and the mass panel'
status: Done
assignee: []
created_date: '2026-09-04 19:04'
updated_date: '2026-09-04 19:05'
labels: []
dependencies: []
priority: high
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Ship the semantic-reorganisation half of the atlas: user-named topics become islands whose volume is how much has been written about them, articles open their first line on the canvas at the near and full bands, and the left rail reports how much of the corpus each region, category, lens or topic accounts for.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 arrange-by topics groups all 39,915 articles into user-named islands sized by article count
- [x] #2 unclaimed articles are visible as an outer shell, not hidden
- [x] #3 the broad/balanced/strict threshold is user-facing and the counts move with it
- [x] #4 gists render on canvas at near and full, from their own shard tier
- [x] #5 gist text is free of flattened section headings and infobox rows
- [x] #6 deploy.sh purges the whole tree, not the first 30 URLs
- [x] #7 live at wiki.p2pfoundation.net/explore with no console errors
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Shipped 2026-09-04 as 3d60c1a9 (feature) and b4703c60 (favicon). Live: https://wiki.p2pfoundation.net/explore/

Verified in a browser at both ends — local build and production — zero console errors on the deployed page.

WHAT THE GIST WORK ACTUALLY WAS. extract-corpus flattens `==Description==` into `Description. ` because in a retrieval index the heading word is signal. On a 150-character label it is not, and the scale of it was not visible until the labels existed: 12,613 of 38,595 gists opened on a flattened heading, and `URL = Description. "…"` — an infobox row and a heading stacked — accounted for thousands of those. dehead() strips the field row first, then peels headings up to five deep, and only when real prose survives underneath. 12,613 -> 1,498, and what is left is mostly prose that genuinely begins 'Summary by Kevin Carson'.

Two things it refuses to strip, because both have the exact shape of a flattened heading: an initial (`D. Sadoway`) and an honorific (`Dr.`). Eating one silently renames the person the sentence is about. A gist that is nothing but furniture returns empty rather than a label reading 'Video via' — an empty slot beats a slot filled with nothing.

The same stripper runs over the panel leads, which carried the same furniture.

PURGE. deploy.sh purged urls[:30] because the Free plan allows 30 per call and the tree was 29 files. The gist shards took it to 54, and the old code reported success while leaving most of the payload stale at the edge. It batches now — the deploy that shipped this printed 'purging 54 URLs in 2 call(s)'.

dist-atlas/ was 25 MB of build output that the `dist/` ignore rule never matched, so it sat untracked and unignored — one `git add -A` from being committed. Now ignored.
<!-- SECTION:NOTES:END -->
