-- Monitor 181 was a GET of https://vpn.jeffemmett.com accepting 200-299.
-- Headscale answers that with 200 even when the tailnet is dying, so this
-- monitor reported green through the entire 2026-08-17 outage and would have
-- gone on doing so indefinitely.
--
-- The control protocol is what actually matters, and it is a different request:
-- POST /ts2021 with `Upgrade: tailscale-control-protocol`. Cloudflare does not
-- forward that, and when it is broken the endpoint returns 500 while / and
-- /health stay 200.
--
-- Accepted codes are 4xx, which reads oddly and is correct: an unauthenticated
-- POST reaching the real headscale is REJECTED on its merits, which proves the
-- path works end to end. 101 is accepted too, for the case where the upgrade is
-- allowed to complete. 5xx and connection failures are the actual outage.
UPDATE monitor SET
  name = 'Headscale control protocol (/ts2021)',
  url = 'https://vpn.jeffemmett.com/ts2021',
  method = 'POST',
  headers = '{"Upgrade":"tailscale-control-protocol","Connection":"Upgrade"}',
  accepted_statuscodes_json = '["101","400-499"]',
  interval = 120,
  description = 'Tests the endpoint that actually carries the tailnet, not /health.

On 2026-08-20 /health returned 200 {"status":"pass"} and /apple rendered while
/ts2021 returned 500 and every device on the tailnet was three days from being
logged out for good. Those first two are ordinary GETs and survive anything.

A 4xx here is HEALTHY: an unauthenticated POST reached the real headscale and
was rejected on its merits. A 5xx is the Cloudflare signature - POST with
Upgrade: tailscale-control-protocol is not being forwarded, clients fall back to
the legacy /machine/register that 0.28 no longer serves, and the tailnet dies
quietly over about three days.

Do not "fix" this by pointing it back at / or /health.'
WHERE id = 181;

SELECT id, name, method, url, accepted_statuscodes_json FROM monitor WHERE id=181;
