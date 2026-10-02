---
id: TASK-8
title: 'GX10 wiki standby has no search: CirrusSearch/Elastica missing'
status: To Do
assignee: []
created_date: '2026-10-02 13:31'
updated_date: '2026-10-02 14:47'
labels: []
dependencies: []
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Measured 2026-10-02 from the standby's own siteinfo: it runs CategoryTree, Cite, ConfirmEdit, QuestyCaptcha, Vector, YouTube — but NOT CirrusSearch, Elastica, HitCounters or WikiEditor, all of which Netcup has. Promoted today it would come up with search silently degraded to MySQL full-text on a 45k-page corpus. It also still lists MinervaNeue/MonoBook/Timeless in siteinfo despite $wgSkipSkins, which hides a skin from preferences without unregistering it — so apply-parity.sh's claim that SkipSkins gets 'the same observable result' is wrong about Special:Version. The gap is recorded in infra/p2pwiki/standby/apply-parity.sh; closing it needs an Elasticsearch instance on GX10 and an index rebuild.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Elasticsearch is startable on demand and verified by actually starting it; it is NOT in the default stack and cannot restart on its own (it measures 973MiB and the standby serves no traffic)
- [ ] #2 CirrusSearch + Elastica staged at Netcup's own versions (6.5.4 / 6.2.0) and loaded only when the switch is on; the index is built over the full corpus and the document count is checked against Elasticsearch rather than against the indexer's own report
- [ ] #3 With the switch on the standby returns Netcup-shaped search results on the same queries; with it off it still answers from MySQL full-text
- [ ] #4 apply-parity.sh reproduces the staging on a fresh standby and promote-to-primary.sh turns search on, so neither needs today's steps repeated by hand
<!-- AC:END -->
