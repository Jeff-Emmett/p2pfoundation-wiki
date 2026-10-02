---
id: TASK-9
title: Give the p2pwiki drift probe an Uptime Kuma monitor
status: To Do
assignee: []
created_date: '2026-10-02 13:31'
labels: []
dependencies: []
priority: low
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The probe at /opt/scripts/p2pwiki-extension-drift-probe.sh already pushes to Kuma if P2PWIKI_EXTENSION_DRIFT_PUSH_TOKEN exists in /etc/uptime-kuma-push.env; absent, email via unit-failure-notify@ is the whole alert. Email works (verified end-to-end 2026-10-02) but leaves no status-page history, so a drift that is fixed quickly leaves no trace. Needs a push monitor minted in the Kuma UI and its token appended to the env file (0600).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 Push monitor exists in Kuma and the token is in /etc/uptime-kuma-push.env
- [ ] #2 A deliberate drift shows DOWN on the status page, and a clean run shows UP
<!-- AC:END -->
