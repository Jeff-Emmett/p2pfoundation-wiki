---
id: TASK-7
title: Restore the Cite extension and stop LocalSettings.php drifting silently
status: Done
assignee: []
created_date: '2026-10-02 13:31'
updated_date: '2026-10-02 13:31'
labels: []
dependencies: []
priority: high
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
<ref>/<references /> rendered as literal text on 192 pages because extensions/Cite shipped inside the mediawiki:1.40 image but was never wfLoadExtension'd. Fixing it exposed the real problem: LocalSettings.php is the one piece of this service not reproducible from this repo, and it has silently lost configuration twice (Cite, and the $wgSMTP port/password fix the 2026-08-24 failback reverted).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Cite loaded and <ref> rendering footnotes through the public edge
- [x] #2 Parser cache invalidated for every affected page, not left to expire
- [x] #3 Change committed and pushed where production config actually lives (gitea jeffemmett/p2pwiki), with main no longer behind the deploy branch
- [x] #4 Backup coverage of LocalSettings.php verified against an actual restic snapshot
- [x] #5 GX10 standby has Cite, and apply-parity.sh no longer instructs the reader not to install it
- [x] #6 $wgSMTP repaired (465 + rotated password) and verified end-to-end by a real send
- [x] #7 A daily probe asserts extensions, <ref> rendering and $wgSMTP, mails on failure, and has had every failure path exercised
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Cite was never missing from disk: extensions/Cite ships in the mediawiki:1.40 image and sat in the p2pwiki-extensions volume unloaded, with ~36 other bundled extensions, of which nine were ever wfLoadExtension'd. Help:Create Citations documents the extension, so the wiki had it once; the loss predates the oldest archived Special:Version (2026-01-27).

Enabled with one line. No download (bundled copy matches core), no schema change. 192 pages use <ref>; 200 pages had their parser cache invalidated via page_touched (no revisions, no RecentChanges noise) and were re-parsed. MW 1.40 Cite appends the footnote list when <references /> is missing, so the 17 pages without the tag needed no edit. Only Cite error on the wiki is a content bug on Help:Create Citations (ref name="multiple" defined twice).

Persistence, each leg checked rather than assumed:
- Committed 021e932 in gitea jeffemmett/p2pwiki (where LocalSettings.php IS tracked), pushed dev; main was 8 commits behind its own deploy branch and was fast-forwarded. A fresh clone lands on main.
- Pushing dev fires /opt/deploy-webhook, which ran docker compose up -d --build: p2pwiki-db recreated, p2pwiki restarted, ~45s, clean start. Unintended, but it proves Cite survives a recreate. Cirrus still answers (8,285 hits for 'commons').
- cf-cache-status is DYNAMIC on articles, so no edge cache was involved.
- /opt is one of 22 restic roots, excludes do not touch /opt/websites, and restic ls on the 2026-10-02 03:03 snapshot lists LocalSettings.php. The GitHub mirror target is private.
- GX10 standby: Cite appended in place (no container recreate on that host).

$wgSMTP: the 2026-08-24 failback had restored tls:// + port 587 AND a pre-rotation password. From inside the container, fsockopen tls://mail.rmail.online:587 fails 'wrong version number'. Fixed to 465 with the password re-read from /opt/secrets/mailcow/p2pwiki_noreply_smtp_password by a server-side script that never printed it; AUTH LOGIN answers 235, PHP parses the file literal back to the secret's exact bytes, and a UserMailer send reached the mailbox (status=sent).

Probe: /opt/scripts/p2pwiki-extension-drift-probe.sh + p2pwiki-smtp-config-check.py, daily timer, OnFailure mails jeff+agent@. Six failure paths exercised (extension missing, port reverted, password reverted, unreadable LocalSettings, unreachable wiki, clean run). Alert chain itself exercised with a transient unit: status=sent. Found and fixed en route: p2pwiki-log-tail.service had OnFailure= in [Service], where systemd ignores it, so the unit whose three months of silence was the original bug could not report its own death.
<!-- SECTION:NOTES:END -->
