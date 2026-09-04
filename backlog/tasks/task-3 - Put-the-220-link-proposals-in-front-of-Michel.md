---
id: TASK-3
title: Put the 220 link proposals in front of Michel
status: Blocked
assignee: []
created_date: '2026-09-04 19:30'
updated_date: '2026-09-04 19:31'
labels: []
dependencies:
  - TASK-2
priority: high
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
60,747 mutual k-NN pairs filtered to 220 strict cross-link proposals, built at demo/aiwiki/graph/data/batch.wikitext on 2026-09-01 and never sent. The precision comes from two filters — the surface form must match the target title exactly including case, and the target title must be at least two words — plus an idempotence guard that refuses to link a page that already links the target (24 of 60 articles mention the phrase more than once, so without it a re-run would progressively link every occurrence). 220/220 currently no-op on re-run.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 the batch is loaded into batch-review and opens for a logged-in approver
- [ ] #2 a dry run over the whole batch reports what it would change without editing
- [ ] #3 Michel is told it exists, where it is, and what pulling the STOP page does
- [ ] #4 one small batch is walked end to end before the full 220 is offered
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Blocked on TASK-2 only in the sense that the batch must be loaded into the tool; the tool itself is live. The file exists and has not moved since it was built:
  demo/aiwiki/graph/data/batch.wikitext  — 462 lines, 24 KB, 2026-09-01

Do not send Michel 220 rows as the first thing he sees. Walk one small batch end to end first — the applier's guarantees are only as good as the first live run, and the namespace bug found on 2026-09-04 (TASK-2) is exactly the class of thing that only shows up when a real edit is attempted.

The applier's seven safety properties, for whoever writes the note to Michel: a blank decision is not an approval; every proposal is re-verified at apply time rather than trusting the batch file; edits are idempotent; one revision per article; the approver is named on the revision; every edit is reversible; rate-limited to one edit per five seconds; dry-run is the default; and an on-wiki STOP page anyone can write to halts everything.
<!-- SECTION:NOTES:END -->
