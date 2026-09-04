---
id: TASK-4
title: Decide where the aiwiki tour lives
status: To Do
assignee: []
created_date: '2026-09-04 19:30'
updated_date: '2026-09-04 19:31'
labels: []
dependencies: []
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The tour at aiwiki.jeffemmett.com is the narrative walkthrough of the retrieval work — BM25, reciprocal rank fusion, the LOD ladder. The atlas is now canonically at wiki.p2pfoundation.net/explore and links back to the tour in its header, so aiwiki cannot be taken down until this is decided. Three options: leave it on Pages, move it under the wiki beside the atlas, or retire it and drop the header link.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 a decision is recorded, with the reason
- [ ] #2 if it moves or retires, the atlas header link is updated in the same change
- [ ] #3 if it stays, aiwiki.jeffemmett.com is documented as a dependency of the atlas rather than an orphan
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Needs Jeff, not an agent — it is a question about what the tour is for, not about how to move it.

The coupling is one href in demo/aiwiki/graph/graph.template.html:
  href="https://aiwiki.jeffemmett.com/"
That is the whole technical cost of retiring or moving it. Everything else in this decision is editorial.

Note that the tour and the atlas are already separate build outputs of the same script: demo/aiwiki/build.sh writes dist/ (Pages, the tour) and dist-atlas/ (netcup, the atlas). They are different products that happen to share a builder.
<!-- SECTION:NOTES:END -->
