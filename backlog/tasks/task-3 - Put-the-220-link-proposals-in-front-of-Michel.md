---
id: TASK-3
title: Put the 220 link proposals in front of Michel
status: In Progress
assignee:
  - p2pfoundation-wiki-07
created_date: '2026-09-04 19:30'
updated_date: '2026-09-05 12:10'
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
- [x] #1 the batch is loaded into batch-review and opens for a logged-in approver
- [x] #2 a dry run over the whole batch reports what it would change without editing
- [ ] #3 Michel is told it exists, where it is, and what pulling the STOP page does
- [x] #4 one small batch is walked end to end before the full 220 is offered
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Blocked on TASK-2 only in the sense that the batch must be loaded into the tool; the tool itself is live. The file exists and has not moved since it was built:
  demo/aiwiki/graph/data/batch.wikitext  — 462 lines, 24 KB, 2026-09-01

Do not send Michel 220 rows as the first thing he sees. Walk one small batch end to end first — the applier's guarantees are only as good as the first live run, and the namespace bug found on 2026-09-04 (TASK-2) is exactly the class of thing that only shows up when a real edit is attempted.

The applier's seven safety properties, for whoever writes the note to Michel: a blank decision is not an approval; every proposal is re-verified at apply time rather than trusting the batch file; edits are idempotent; one revision per article; the approver is named on the revision; every edit is reversible; rate-limited to one edit per five seconds; dry-run is the default; and an on-wiki STOP page anyone can write to halts everything.

Taken from the atlas session 2026-09-04.

Corrected: this file holds 60 rows, not 220. demo/aiwiki/graph/data/batch.wikitext says 'Batch 2026-09-01 · 60 suggestions' in its own header and has exactly 60 table rows.

Converted those 60 to the JSONL gen_links takes and ran it against the LIVE wiki. Batch links-mentions-20260904 written, 59 items, and deliberately STAGED (batches/staged/) rather than queued, per AC #4 and the atlas session's own advice: Michel walks one small batch before 59 rows are offered. br_list_batches() globs batches/*.json and glob is not recursive, so a subdirectory is the whole hiding mechanism.

Corpus staleness, measured rather than assumed: candidates came from a wikitext mirror frozen 2026-04-14 (last touched 06-10) against a live wiki at 150,201 edits. gen_links re-fetches each source and re-locates the phrase, never trusting recorded offsets. 59 of 60 survived — 1 phrase gone, 0 already linked, 0 pages missing. ~1.7% decay. See TASK-5: refreshing the corpus is a yield improvement, not a correctness fix, and this task is not blocked on it.

Two rows to distrust, and the reason to read the sentence column rather than bulk-approve:
- Non-Exclusive Dunbar Number -> [[Exclusive Dunbar Number]] (target title is a substring of the source title; the hyphen is a word boundary so the match is real but means the opposite)
- Project State and its Rivals -> [[Project State and Its Rivals]] (differ only in the case of 'its' — a duplicate-article pair wanting a merge, not a link)

Two defects found in batch.wikitext and reported to the atlas session:
- it points at [[P2P Foundation:Suggested links/STOP]]. 'P2P Foundation:' is not a namespace here (project ns is 'P2P Foundation Wiki:', ns 4), so that title is an ordinary main-namespace article and the handle would never stop anything. Same bug fixed in batch-review in 63493fc8.
- [[User:P2PLinkBot]] does not exist, and is the wrong actor regardless: batch-review edits AS the approver via their own session, and refuses bot accounts as approvers because an approval has to be traceable to a person.

Remaining for this task: promote the batch into batches/ once one small batch has been walked end to end, then AC #1 and #2.

2026-09-05. Batch promoted into the live queue; staging area removed. Queue is now 8 batches / 258 items, all confirmed to load and plan against the live wiki.

AC#1 and #2 closed on a throwaway copy of the whole batch, so the real one stays pristine (verified after: 59 items, 0 decisions, status open). 59 of 59 planned, dry run applied 41 + 18 = 59, 0 failed. That also exercised the link-mention op through commit.php for the first time — it is the newest op and had only ever been run by its generator, so a crash there would have hit Michel on the largest batch.

AC#3 left unchecked: telling Michel is a delivery step, not something I can verify. The document exists and is current — https://claude.ai/code/artifact/bb1e2f8b-3173-40db-ad88-dde622ceddef — and now describes all eight batches tiered by where to start, with the STOP page named correctly. Sending it is Jeff's.
<!-- SECTION:NOTES:END -->
