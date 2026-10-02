---
id: TASK-9
title: Give the p2pwiki drift probe an Uptime Kuma monitor
status: Done
assignee: []
created_date: '2026-10-02 13:31'
updated_date: '2026-10-02 14:10'
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
- [x] #1 Push monitor exists in Kuma and the token is in /etc/uptime-kuma-push.env
- [x] #2 A deliberate drift shows DOWN on the status page, and a clean run shows UP
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Monitor 332 'p2pwiki config drift (extensions + <ref> + $wgSMTP)' created 2026-10-02: type PUSH, interval 25200s (7h) against a 6h timer, notification 1 'Mailcow Email Alerts'. Token appended once to /etc/uptime-kuma-push.env (0600). The probe pushed and Kuma reports UP carrying the probe's own message.

How it was created without the admin credential passing through a terminal: Kuma has no REST route for monitor CRUD, only socket.io, so monitoring/create-kuma-push-monitor.py runs as a one-off through the kuma-alert-agent service, which already receives its Kuma login from Infisical via /opt/infisical/entrypoint-wrapper.sh (whose last line is exec "$@"). Attaching to the already-running container is NOT enough: the wrapper injects into the main process only, so the credential is absent from the container config and the first attempt died on KeyError. 'docker compose run' is the path that works, because it goes through the same entrypoint. The script prints the push token on its own last line, so the caller appends it to the env file without reading it. Idempotent: a second run reports CREATED=no (reused).

Timer moved 24h -> 6h so the 7h heartbeat window has an hour of slack.

AC #2, honestly: the UP leg was verified on 332 itself with a real probe run. The DOWN leg was verified on an equivalent throwaway push monitor (id 333, no notification attached, deleted afterwards) rather than on 332, because firing DOWN on 332 would have sent mail and that was explicitly out of scope. Same monitor type, same /api/push path, same status=down the probe sends: pushed up -> UP, pushed down -> DOWN, then deleted. Script kept as monitoring/test-kuma-down.py.

Why both channels stay: OnFailure=unit-failure-notify@ mails when a run FAILS; the Kuma monitor goes DOWN when a heartbeat NEVER ARRIVES, which OnFailure structurally cannot see because a timer that stops firing produces no failed unit.
<!-- SECTION:NOTES:END -->
