---
id: TASK-5
title: The atlas corpus is a frozen dump with no refresh path
status: To Do
assignee: []
created_date: '2026-09-04 19:31'
updated_date: '2026-09-04 19:31'
labels: []
dependencies: []
priority: high
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Everything the atlas shows is derived from wiki/, a one-time wikitext archive committed in the initial commit and touched once since. The live wiki has kept moving — 150,201 edits, 6 active users, and two new accounts created on 2026-09-04 alone. Nothing re-extracts, and nothing says how old what you are looking at is.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 the page states the date of the corpus it is showing
- [ ] #2 a documented, runnable path from live wiki to deployed atlas exists end to end
- [ ] #3 the cost and the GX10 dependency of a full re-run are written down
- [ ] #4 a decision is recorded on whether a refresh may move existing points, and how a reader is told when it has
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Found 2026-09-04 while filing TASK-1. The staleness is structural, not an oversight anyone would notice from the page.

  wiki/ last changed by a commit    2026-06-10 (and that one was quote extraction,
                                    not a re-fetch — the dump itself is from the
                                    initial commit)
  corpus.json.gz built              2026-09-01
  plane3.json (the 3D layout)       2026-09-01
  live wiki                         45,632 pages, 150,201 edits, 6 active users

So the atlas is a picture of an archive, presented with no date on it. A reader has no way to know, and neither does an editor who adds an article and then goes looking for it.

THE HARD PART IS NOT THE RE-EXTRACT. The pipeline is extract-corpus.py -> build-neighbours.py -> tsne3-plane.py (GX10, the expensive step) -> pack-graph.py -> deploy.sh. Re-running it is a few hours. The problem is that t-SNE is stochastic: a re-run does not nudge the map, it redraws it. Every point moves, every region lands somewhere else, and anyone who had learned where things are loses that. A refresh is therefore a product decision about spatial memory, not a cron job — which is the AC worth arguing about before any of the others.

Note the coupling to TASK-3: if Michel approves cross-links, the wikilink layer the atlas draws is out of date the moment they land, because those edges come from the same frozen dump.
<!-- SECTION:NOTES:END -->
