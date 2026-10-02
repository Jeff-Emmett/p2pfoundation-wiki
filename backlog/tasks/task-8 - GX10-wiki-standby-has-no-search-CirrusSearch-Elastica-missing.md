---
id: TASK-8
title: 'GX10 wiki standby has no search: CirrusSearch/Elastica missing'
status: Done
assignee: []
created_date: '2026-10-02 13:31'
updated_date: '2026-10-02 16:29'
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
- [x] #1 Elasticsearch is startable on demand and verified by actually starting it; it is NOT in the default stack and cannot restart on its own (it measures 973MiB and the standby serves no traffic)
- [x] #2 CirrusSearch + Elastica staged at Netcup's own versions (6.5.4 / 6.2.0) and loaded only when the switch is on; the index is built over the full corpus and the document count is checked against Elasticsearch rather than against the indexer's own report
- [x] #3 With the switch on the standby returns Netcup-shaped search results on the same queries; with it off it still answers from MySQL full-text
- [x] #4 apply-parity.sh reproduces the staging on a fresh standby and promote-to-primary.sh turns search on, so neither needs today's steps repeated by hand
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Done 2026-10-02 as a SWITCH rather than a running service, because an always-on Elasticsearch for a wiki nobody reads is the cost the task was supposed to avoid: it measures 973MiB resident, on a host already at 244 containers, and 24h of the standby's access log contains nothing but local probes.

WHY IT MATTERED. MySQL full-text was not a slightly worse search. Same queries, both wikis:

  query                  Netcup (Cirrus)              standby (MySQL)
  commons                8285  'Commons'              2150  'Typology of Global Commons-Lacking...'
  peer production        2222                         195
  Ostrom                  637  'Elinor Ostrom'        15    'Elinor Ostrom-s Rules for Radicals'
  platform cooperative   1034  'Platform Cooperativism' 11  'Transkribus - Cooperative AI Platform...'
  self-organisation      1078                         21

1-3% of the recall, wrong page first. Promoted in that state the wiki would have looked like it had lost its own contents.

WHAT WAS BUILT. extensions/CirrusSearch 6.5.4 + extensions/Elastica 6.2.0 copied byte-for-byte from Netcup (not cloned: Elastica needs its composer vendor/ tree, and a standby on a different search version is not a standby), mounted always and LOADED only when wiki-state/cirrus-enabled exists. docker-compose.cirrus.yml holds Elasticsearch, is absent from the default stack and carries restart: 'no' so a reboot cannot bring it back. cirrus.sh on|build|verify|off|status drives it; promote-to-primary.sh calls 'on'; apply-parity.sh reproduces the whole arrangement including the LocalSettings block.

RESULT, with the switch on: 39,084 content + 4,715 general = 43,799 documents, exactly the page count ForceSearchIndex processed. Queries now match Netcup within ~1% with the right top hits -- commons 8310 'Commons', Ostrom 630 'Elinor Ostrom', platform cooperative 1035 'Platform Cooperatives', self-organisation 1092 'Self-organisation'. With it off: back to 15 hits from MySQL, container gone, 244 containers, host memory unchanged.

A cold round trip measured 45 seconds from nothing to Cirrus answering, because the index volume survives 'off'. Promotion is a start, not a rebuild. Idle cost: zero RAM, zero CPU, 1.2GB of disk.

THREE TRAPS PAID FOR, all now encoded in the script:
1. ForceSearchIndex.php does not write to Elasticsearch. It enqueues cirrusSearchElasticaWrite jobs and prints 'Indexed N pages at 150/second' -- pages PROCESSED, not documents WRITTEN. With no job runner here, 378 pages 'indexed' meant 5860 jobs queued and ES's own index_total still exactly 0. A forced _refresh changed nothing, which ruled out the comfortable explanation. The build now drains the queue in a loop and checks the count against Elasticsearch.
2. runJobs.php stops at 95% of PHP's memory_limit, and the image's 150M default makes it quit every ~500 jobs. -d memory_limit=640M turned an hour of restarts into a steady drain.
3. /_cluster/health answers while shards are still recovering: 41s after 'on' the cluster answered, the index was listed, _count said 0 and a query threw. Readiness now means the index is countable, not that the process is up.

Residual 10 refreshLinksDynamic jobs are unrelated to search and are left alone; the job check counts only the Cirrus write type, so a finished index verifies clean.
<!-- SECTION:NOTES:END -->
