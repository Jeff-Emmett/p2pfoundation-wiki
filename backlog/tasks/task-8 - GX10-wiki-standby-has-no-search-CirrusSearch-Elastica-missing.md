---
id: TASK-8
title: 'GX10 wiki standby has no search: CirrusSearch/Elastica missing'
status: To Do
assignee: []
created_date: '2026-10-02 13:31'
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
- [ ] #1 Elasticsearch running on GX10 and reachable from the standby container
- [ ] #2 CirrusSearch + Elastica loaded and the index built over the full corpus
- [ ] #3 A search on the standby returns the same shape of result as Netcup for the same query
- [ ] #4 apply-parity.sh updated so a re-run reproduces this rather than needing the steps again
<!-- AC:END -->
