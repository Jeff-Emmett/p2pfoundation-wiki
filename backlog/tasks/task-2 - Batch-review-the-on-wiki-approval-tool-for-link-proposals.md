---
id: TASK-2
title: 'Batch-review: the on-wiki approval tool for link proposals'
status: In Progress
assignee:
  - p2pfoundation-wiki-07
created_date: '2026-09-04 19:30'
updated_date: '2026-09-04 20:20'
labels: []
dependencies: []
priority: high
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The tool Michel and other editors use to approve or reject proposed cross-links, running inside the wiki itself so an approval is a wiki edit by a named account rather than a row in someone's database. Deployed at /opt/websites/p2pwiki/extensions/batch-review, bind-mounted read-only into the container as /var/www/html/p2pwiki-custom.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 reviewer list is closed and pinned by username AND numeric user id
- [ ] #2 blocked accounts are refused, and every denial is logged
- [x] #3 one revision per article, however many items touch it
- [ ] #4 commit runs in chunks so a 200-edit run survives Cloudflare's 100s limit
- [x] #5 on-wiki STOP page halts every commit for everybody
- [x] #6 stop_page and record_page_prefix resolve into the project namespace, not the article namespace
- [ ] #7 an interrupted commit resumes rather than sitting half-done
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Owner: the p2pfoundation-wiki-07 session, by its own statement (2026-09-04). Scope is infra/p2pwiki/batch-review/ only. This task is NOT for the atlas session to pick up.

Deployed and live — verified independently 2026-09-04 21:30:
  https://wiki.p2pfoundation.net/p2pwiki-custom/batch-review/  -> HTTP 403
403 is the correct answer for everyone: access is gated to three named wiki accounts (JeffEmmett, Mbauwens, Bryan), pinned by username AND numeric user id. Username alone is a mutable key here — both approver accounts hold 'bureaucrat', which is the right that renames accounts, so a freed username could be re-registered by anyone and would inherit the access.

Origin is /opt/websites/p2pwiki/extensions/batch-review/, bind-mounted read-only into the container as /var/www/html/p2pwiki-custom. That mount is why it ships without a container restart: rsync in, wait ~60s for opcache, done.

NAMESPACE FIX IN FLIGHT (uncommitted as of 21:10): stop_page, record_page_prefix and gen_index_page.php's default --page all used the prefix 'P2P Foundation:', which this wiki does not define — so all three resolved into the ARTICLE namespace. The project namespace is 'P2P Foundation Wiki:' (ns 4). Two distinct failures, not one cosmetic one: an editor pulling the STOP handle would have created an ordinary article while the tool went on reading a different page and did not stop, and record_page_prefix would have written tool logs into the encyclopedia on the first live commit.

Session close-out 2026-09-04 (p2pfoundation-wiki-07).

AC #1, #3, #5, #6 checked — each observed, not inferred:

#1 Verified by refusal, not by reading config. 'Claude bot' (id 2950) is a real, logged-in, autoconfirmed account holding 'edit', and the tool answered 403 'This tool is in closed testing' — no batch list, no item counts. List is JeffEmmett=2943, Mbauwens=9, Bryan=2951.

#3 Covered by tests/lib-test.php (runs in the container, needs no wiki): three groups from five items; underscore and first-char title variants land in one edit; a genuinely different title stays its own page; a bookmark is never merged with an edit.

#5 Proven BOTH directions without writing anything to the wiki, by pointing stop_page at an existing non-empty page and reading br_stop_state():
  P2P Foundation Wiki:About      -> halted=true,  text_len=34205
  ...Batch review/STOP (absent)  -> halted=false
Config restored immediately and re-verified. So 'any text at all halts' is a measured behaviour now, not a claim.

#6 The reason this AC exists. 'P2P Foundation:' is not a namespace on this wiki — the API returns ns:0 for it — so all three targets resolved to ordinary ARTICLES. On stop_page that was a silent safety failure: an editor pulling the handle creates the ns-4 project page they can see in the sidebar while the tool reads a different page in ns 0, and the commit does not stop. Fixed in 63493fc8; the corrected name now appears on the live commit screen.

Left unchecked deliberately, with the reason:

#2 Half done. Denial logging is verified — log/access-YYYYMM.jsonl carries deny.anon and deny.not-reviewer with both username and numeric id. The blocked-account path (lib.php:290, reading blockid from meta=userinfo&uiprop=blockinfo) has NOT been exercised, because the only honest test is blocking a real account on a production wiki. Not doing that on my own judgement.

#4 and #7 are code-and-config true but never exercised: my end-to-end walk was 2 items, which never reaches the 45s chunk budget, so no Continue button and no resume was triggered. These need a real commit over a large batch, which means live_writes=true.

Staying In Progress. live_writes is still false and no human has run the tool yet; that first run is the remaining gate.

Also this session: 'Claude bot' (2950) and 'Bryan' (2951) created; Bryan added to reviewers with a deploy note; the authenticated path walked end to end for the first time (index -> batch -> verify -> save -> commit plan -> dry run '2 would have been applied'), on a throwaway batch since deleted. All seven generators have now produced a batch; three are staged in batches/staged/ so the queue stays at the five the tester walkthrough describes.
<!-- SECTION:NOTES:END -->
