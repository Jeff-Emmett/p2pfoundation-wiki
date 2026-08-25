import re, shutil, time
p = "/home/mycopunk/bin/headscale-control-push.sh"
s = open(p).read()
if "--http1.1" in s:
    print("already fixed"); raise SystemExit(0)

old = """TS_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 \\
  "${RESOLVE[@]}" \\
  -X POST \\
  -H 'Upgrade: tailscale-control-protocol' \\
  -H 'Connection: Upgrade' \\
  "$HS_URL/ts2021" 2>/dev/null) || TS_CODE="000\""""

new = """# --http1.1 IS LOAD-BEARING, do not drop it.
#
# HTTP/2 has no Upgrade mechanism at all -- the header is meaningless there and
# the request errors out with a 500. Without this flag curl negotiates h2 via
# ALPN and the probe reports the control plane DOWN while it is perfectly
# healthy: on 2026-08-25 the same request was 500 over h2 and 400 over HTTP/1.1,
# against the same headscale, seconds apart.
#
# 400 is the healthy answer. It means an unauthenticated bare POST reached the
# real headscale and was rejected on its merits. Real tailscale clients speak
# HTTP/1.1 for exactly this reason, so pinning it here is what makes the probe
# test the path clients actually use rather than one no client takes.
TS_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 --http1.1 \\
  "${RESOLVE[@]}" \\
  -X POST \\
  -H 'Upgrade: tailscale-control-protocol' \\
  -H 'Connection: Upgrade' \\
  "$HS_URL/ts2021" 2>/dev/null) || TS_CODE="000\""""

assert old in s, "anchor not found"
shutil.copy2(p, p + ".bak-" + time.strftime("%Y%m%dT%H%M%SZ", time.gmtime()))
open(p, "w").write(s.replace(old, new, 1))
print("patched probe to pin HTTP/1.1")
