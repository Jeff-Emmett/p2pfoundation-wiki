---
id: TASK-2
title: 'Batch-review: the on-wiki approval tool for link proposals'
status: In Progress
assignee:
  - p2pfoundation-wiki-07
created_date: '2026-09-04 19:30'
updated_date: '2026-09-04 19:30'
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
- [ ] #1 reviewer list is closed and pinned by username AND numeric user id
- [ ] #2 blocked accounts are refused, and every denial is logged
- [ ] #3 one revision per article, however many items touch it
- [ ] #4 commit runs in chunks so a 200-edit run survives Cloudflare's 100s limit
- [ ] #5 on-wiki STOP page halts every commit for everybody
- [ ] #6 stop_page and record_page_prefix resolve into the project namespace, not the article namespace
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
<!-- SECTION:NOTES:END -->
